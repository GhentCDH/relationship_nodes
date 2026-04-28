<?php

namespace Drupal\relationship_nodes\Display\Configurator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;

/**
 * Base configurator for nested relationship fields.
 *
 * Provides a config-based approach to building field configuration forms:
 * 1. Prepare phase: Build field configuration arrays (context-aware)
 * 2. Render phase: Build forms from configurations (context-agnostic)
 *
 * Can be used directly for field formatters or extended for Views/other contexts.
 */
class FieldConfiguratorBase {

  use StringTranslationTrait;

  protected FieldNameResolver $fieldNameResolver;

  /**
   * Constructs a FieldConfiguratorBase object.
   *
   * @param FieldNameResolver $fieldNameResolver
   *   The field name resolver service.
   */
  public function __construct(FieldNameResolver $fieldNameResolver) {
    $this->fieldNameResolver = $fieldNameResolver;
  }

  /**
   * Prepares field configurations from context and current settings.
   *
   * This is the PREPARE phase - builds complete configuration arrays
   * including all metadata needed for form rendering.
   *
   * @param array $field_names
   *   Array of available field names.
   * @param array $saved_settings
   *   Current field settings from saved configuration.
   * @param array $context
   *   Extension point for context-specific capabilities. Base class passes 
   *   this through unchanged - subclasses use it to determine field capabilities.
   *   Common keys:
   *   - 'linkable_fields': Fields supporting entity reference display modes
   *   - 'calculated_fields': Computed/aggregated fields (no separators)
   *   - 'filterable_fields': Fields available for filtering (Views)
   *   Empty array is valid - all fields get default capabilities.
   *
   * @return array
   *   Array of field configurations keyed by field name, each containing:
   *   - 'field_name': (string) The field name
   *   - 'linkable': (bool) Whether field supports entity linking
   *   - 'is_calculated': (bool) Whether field is calculated
   *   - 'enabled': (bool) Whether field is enabled in current settings
   *   - 'label': (string) Human-readable label
   *   - 'weight': (int) Display order weight
   *   - 'display_mode': (string) raw|label|link
   *   - 'hide_label': (bool) Whether to hide label in output
   *   - 'multiple_separator': (string) Separator for multiple values
   *   Plus any additional context-specific properties
   */
  public function prepareFieldConfigurations(
    array $field_names,
    array $saved_settings,
    array $context = []
  ): array {
    $configurations = [];
    $field_settings = $saved_settings['field_settings'] ?? [];
    $linkable_fields = $context['linkable_fields'] ?? [];
    $calculated_fields = $context['calculated_fields'] ?? [];
    $support_range = $context['support_range'] ?? [];

    foreach ($field_names as $field_name) {
      $saved_config = $field_settings[$field_name] ?? [];
      $is_linkable = in_array($field_name, $linkable_fields);
      $supports_range = in_array($field_name, $support_range);
      $configurations[$field_name] = [
        // Identity
        'field_name' => $field_name,
        
        // Capabilities (determined at prepare time)
        'linkable' => $is_linkable,
        'is_calculated' => in_array($field_name, $calculated_fields),
        'supports_range' => $supports_range,
        // User configuration (from saved settings)
        'enabled' => $saved_config['enabled'] ?? TRUE,
        'label' => $saved_config['label'] ?? $this->formatFieldLabel($field_name),
        'weight' => $saved_config['weight'] ?? 0,
        'display_mode' => $saved_config['display_mode'] ?? ($is_linkable ? 'link' : 'label'),
        'hide_label' => $saved_config['hide_label'] ?? TRUE,
        'multiple_separator' => $saved_config['multiple_separator'] ?? ', ',
      ];
    }

    return $configurations;
  }

  /**
   * Builds complete display settings form from field configurations.
   *
   * This is the RENDER phase - purely transforms configuration arrays
   * into form elements. Completely context-agnostic.
   *
   * Builds field_settings container directly on the provided form array.
   * If you need a wrapper (like <details>), add it to $form before calling this.
   *
   * @param array &$form
   *   The form array to add elements to.
   * @param array $field_configurations
   *   Field configurations from prepareFieldConfigurations().
   * @param array $global_settings
   *   Global settings like sort_by_field, group_by_field, template.
   * @param array $options
   *   Form structure options:
   *   - 'context_prefix': Form state path prefix (default: NULL)
   *   - 'show_template': Include template selector (default: FALSE)
   *   - 'show_grouping': Include grouping options (default: TRUE)
   *   - 'show_sorting': Include sorting options (default: TRUE)
   *   - 'field_callback': Custom callback for field rendering (default: NULL)
   */
  public function buildConfigurationForm(
    array &$form,
    array $field_configurations,
    array $global_settings = [],
    array $options = []
  ): void {
    // Merge with defaults
    $options += [
      'context_prefix' => NULL,
      'show_template' => FALSE,
      'show_grouping' => TRUE,
      'show_sorting' => TRUE,
      'field_callback' => NULL,
    ];

    // Field configuration container
    $form['field_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Available fields'),
      '#description' => $this->t('Select and configure fields.'),
      '#open' => TRUE
    ];

    // Build individual field forms from configurations
    foreach ($field_configurations as $config) {
      if ($options['field_callback'] && is_callable($options['field_callback'])) {
        // Use call_user_func_array to support reference parameters
        call_user_func_array($options['field_callback'], [&$form, $config, $options]);
      } else {
        // Use default display field rendering
        $this->buildFieldFormFromConfig(
          $form,
          $config,
          $options['context_prefix']
        );
      }
    }
    // Global options (sorting, grouping, template)
    $this->addGlobalOptions(
      $form,
      array_keys($field_configurations),
      $global_settings,
      $options
    );
  }

  /**
   * Builds form elements for a single field from its configuration.
   *
   * Pure rendering - takes config array and builds form elements.
   * No context lookups, no capability checks - everything is in the config.
   *
   * @param array &$form
   *   The form array.
   * @param array $config
   *   Field configuration array.
   * @param string|null $context_prefix
   *   Optional form state path prefix.
   */
  protected function buildFieldFormFromConfig(
    array &$form,
    array $config,
    ?string $context_prefix
  ): void {
    $field_name = $config['field_name'];
    $is_enabled = $config['enabled'];
    
    // Build disabled state
    $disabled_state = $this->buildFieldDisabledState(
      $field_name,
      $context_prefix
    );

    // Field container
    $form['field_settings'][$field_name] = [
      '#type' => 'details',
      '#title' => $config['label'],
      '#open' => $is_enabled,
    ];

    // Enable checkbox
    $form['field_settings'][$field_name]['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display this field'),
      '#default_value' => $config['enabled'],
    ];

    // Display mode (only for linkable fields)
    if ($config['linkable']) {
      $form['field_settings'][$field_name]['display_mode'] = [
        '#type' => 'radios',
        '#title' => $this->t('Display mode'),
        '#options' => [
          'raw' => $this->t('Raw value (ID)'),
          'label' => $this->t('Label of referenced item'),
          'link' => $this->t('Label of referenced item as link'),
        ],
        '#default_value' => $config['display_mode'],
        '#description' => $this->t('How to display this field value.'),
        '#states' => $disabled_state,
      ];
    }

    // Label
    $form['field_settings'][$field_name]['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom label'),
      '#default_value' => $config['label'],
      '#description' => $this->t('Label to display for this field.'),
      '#size' => 30,
      '#states' => $disabled_state,
    ];

    // Weight
    $form['field_settings'][$field_name]['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#default_value' => $config['weight'],
      '#description' => $this->t('Fields with lower weights appear first.'),
      '#size' => 5,
      '#states' => $disabled_state,
    ];

    // Hide label
    $form['field_settings'][$field_name]['hide_label'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide label in output'),
      '#default_value' => $config['hide_label'],
      '#states' => $disabled_state,
    ];

    // Multiple separator (not for calculated fields)
    if (!$config['is_calculated']) {
      $form['field_settings'][$field_name]['multiple_separator'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Multiple values separator'),
        '#default_value' => $config['multiple_separator'],
        '#description' => $this->t('Separator for multiple values.'),
        '#size' => 10,
        '#states' => $disabled_state,
      ];
    }
  }

  /**
   * Adds global options (sorting, grouping, template).
   *
   * @param array &$form
   *   The form array.
   * @param array $field_names
   *   Available field names for options.
   * @param array $settings
   *   Current global settings.
   * @param array $options
   *   Configuration options.
   */
  protected function addGlobalOptions(
    array &$form,
    array $field_names,
    array $settings,
    array $options
  ): void {
    // Sorting
    if ($options['show_sorting']) {
      $form['sort_by_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Sort by field'),
        '#options' => ['' => $this->t('- None -')] + array_combine($field_names, $field_names),
        '#default_value' => $settings['sort_by_field'] ?? '',
        '#description' => $this->t('Sort relationships by this field value.'),
      ];
    }

    // Grouping
    if ($options['show_grouping']) {
      $form['group_by_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Group by field'),
        '#options' => ['' => $this->t('- None -')] + array_combine($field_names, $field_names),
        '#default_value' => $settings['group_by_field'] ?? '',
        '#description' => $this->t('Group relationships by this field value.'),
      ];
    }

    // Template (Views-specific)
    if ($options['show_template']) {
      $form['template'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Template name'),
        '#default_value' => $settings['template'] ?? 'relationship-field',
        '#description' => $this->t('Template file name without .html.twig extension.'),
      ];
    }
  }

  /**
   * Builds Form API states for disabling field elements.
   *
   * @param string $field_name
   *   The field name.
   * @param string|null $context_prefix
   *   Optional form state path prefix.
   *
   * @return array
   *   Form API states configuration.
   */
  public function buildFieldDisabledState(
    string $field_name,
    ?string $context_prefix
  ): array {
    $field_parts = ['field_settings', $field_name, 'enabled'];
  
    if ($context_prefix) {
      $input_name = $context_prefix . '[' . implode('][', $field_parts) . ']';
    } else {
      $input_name = implode('][', $field_parts) . ']';
    }

    return [
      'disabled' => [
        ':input[name="' . $input_name . '"]' => ['checked' => FALSE],
      ],
    ];
  }


/**
 * Extracts settings from form state.
 *
 * Generic helper for extracting configuration values from form state.
 * Reads values directly from form state path without wrapper prefix.
 *
 * @param FormStateInterface $form_state
 *   The form state.
 * @param array $default_settings
 *   Default settings structure.
 * @param string|null $prefix
 *   Optional path prefix (e.g., 'settings' for formatters, 'options' for Views).
 *
 * @return array
 *   Extracted settings.
 */
public function extractSettingsFromFormState(
  FormStateInterface $form_state,
  array $default_settings,
  ?string $prefix = NULL
): array {
  $settings = [];

  foreach ($default_settings as $key => $default_value) {
    if ($prefix) {
      $value = $form_state->getValue([$prefix, $key]);
    } else {
      $value = $form_state->getValue($key);
    }

    $settings[$key] = $value ?? $default_value;
  }

  return $settings;
}

  /**
   * Gets default display settings structure.
   *
   * @param bool $include_template
   *   Whether to include template setting.
   *
   * @return array
   *   Default settings array.
   */
  public function getDefaultDisplaySettings(bool $include_template = FALSE): array {
    $defaults = [
      'field_settings' => [],
      'sort_by_field' => '',
      'group_by_field' => '',
    ];

    if ($include_template) {
      $defaults['template'] = 'relationship-field';
    }

    return $defaults;
  }

  /**
   * Builds field metadata for template rendering.
   *
   * Filters configurations to only enabled fields and prepares for templates.
   *
   * @param array $field_configurations
   *   Field configurations from prepareFieldConfigurations().
   *
   * @return array
   *   Array of field metadata sorted by weight, only enabled fields.
   */
  public function buildFieldsMetadata(array $field_configurations): array {
    $metadata = [];

    foreach ($field_configurations as $config) {
      if (!$config['enabled']) {
        continue;
      }

      $metadata[$config['field_name']] = [
        'name' => $config['field_name'],
        'label' => $config['label'],
        'weight' => $config['weight'],
        'hide_label' => $config['hide_label'],
        'display_mode' => $config['display_mode'],
        'multiple_separator' => $config['multiple_separator'],
      ];
    }

    // Sort by weight
    uasort($metadata, function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });

    return $metadata;
  }

  /**
   * Builds settings summary for admin UI.
   *
   * @param array $field_configurations
   *   Field configurations.
   * @param array $global_settings
   *   Global settings (sort, group, etc.).
   *
   * @return array
   *   Array of summary strings.
   */
  public function buildSettingsSummary(array $field_configurations, array $global_settings = []): array {
    $summary = [];

    // Count enabled fields
    $enabled_count = count(array_filter($field_configurations, fn($c) => $c['enabled']));
    
    if ($enabled_count > 0) {
      $summary[] = $this->t('Displaying @count field(s)', ['@count' => $enabled_count]);
    }

    if (!empty($global_settings['sort_by_field'])) {
      $summary[] = $this->t('Sorted by: @field', [
        '@field' => $this->formatFieldLabel($global_settings['sort_by_field'])
      ]);
    }

    if (!empty($global_settings['group_by_field'])) {
      $summary[] = $this->t('Grouped by: @field', [
        '@field' => $this->formatFieldLabel($global_settings['group_by_field'])
      ]);
    }

    return $summary;
  }

  /**
   * Formats a field name into human-readable label.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   Formatted label.
   */
  public function formatFieldLabel(string $field_name): string {
    // Remove common prefixes
    $label = preg_replace('/^(rn_|field_)/', '', $field_name);
    
    // Replace underscores with spaces
    $label = str_replace('_', ' ', $label);
    
    // Capitalize words
    return ucwords($label);
  }

  /**
   * Adds error message to form.
   *
   * @param array &$form
   *   The form array.
   * @param string|null $message
   *   Optional custom error message.
   */
  public function addErrorMessage(array &$form, ?string $message = NULL): void {
    $form['error'] = [
      '#markup' => $message ?? $this->t('Cannot load configuration, or no fields available.'),
      '#prefix' => '<div class="messages messages--error">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * Gets the field name resolver service.
   *
   * @return FieldNameResolver
   *   The field name resolver.
   */
  protected function getFieldNameResolver(): FieldNameResolver {
    return $this->fieldNameResolver;
  }
}