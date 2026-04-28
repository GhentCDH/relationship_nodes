<?php

namespace Drupal\relationship_nodes_search\Views\Config;

use Drupal\search_api\Entity\Index;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;
use Drupal\relationship_nodes_search\QueryHelper\FilterOperatorHelper;
use Drupal\relationship_nodes_search\FieldHelper\NestedIndexFieldHelper;

/**
 * Configuration form builder for Views filter fields.
 *
 * Extends the Views configurator base with filter-specific functionality:
 * - Widget type selection (textfield, select)
 * - Operator configuration
 * - Filter exposure settings
 * - Required/placeholder configuration
 */
class NestedFieldViewsFilterConfigurator extends NestedFieldViewsConfiguratorBase {

  protected FilterOperatorHelper $operatorHelper;

  /**
   * Constructs a NestedFieldViewsFilterConfigurator object.
   *
   * @param FieldNameResolver $fieldNameResolver
   *   The field name resolver service.
   * @param NestedIndexFieldHelper $nestedFieldHelper
   *   The nested field helper service.
   * @param CalculatedFieldHelper $calculatedFieldHelper
   *   The calculated field helper service.
   * @param FilterOperatorHelper $operatorHelper
   *   The operator helper service.
   */
  public function __construct(
    FieldNameResolver $fieldNameResolver,
    NestedIndexFieldHelper $nestedFieldHelper,
    CalculatedFieldHelper $calculatedFieldHelper,
    FilterOperatorHelper $operatorHelper
  ) {
    parent::__construct($fieldNameResolver, $nestedFieldHelper, $calculatedFieldHelper);
    $this->operatorHelper = $operatorHelper;
  }

  /**
   * Build filter configuration form.
   *
   * High-level method that orchestrates filter form building.
   *
   * @param array &$form
   *   The form array.
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   Parent field name.
   * @param array $child_fld_nms
   *   Available child field names.
   * @param array $saved_settings
   *   Current saved settings.
   */
  public function buildFilterConfigForm(
    array &$form,
    Index $index,
    string $sapi_fld_nm,
    array $child_fld_nms,
    array $saved_settings
  ): void {
    $context_prefix = $this->getViewsContextPrefix();

    // PREPARE: Build field configurations with filter-specific context.
    $field_configs = $this->prepareFilterFieldConfigurations(
      $index,
      $sapi_fld_nm,
      $child_fld_nms,
      $saved_settings
    );

    // RENDER: Build form using custom callback for filter-specific elements.
    $this->buildConfigurationForm(
      $form,
      $field_configs,
      [],
      [
        'context_prefix' => $context_prefix,
        'show_template' => FALSE,
        'show_grouping' => FALSE,
        'show_sorting' => FALSE,
        'field_callback' => [$this, 'buildFilterFieldForm'],
      ]
    );

    $rangeable = array_keys(array_filter(
      $field_configs,
      fn($cfg) => $cfg['supports_range'] ?? FALSE
    ));
    if (count($rangeable) >= 2) {
      $this->buildRangePairForm($form, $rangeable, $saved_settings, $context_prefix);
    }
  }

  /**
   * Prepares field configurations for filter context.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The parent Search API field name.
   * @param array $child_fld_nms
   *   Available child field names.
   * @param array $saved_settings
   *   Current saved settings.
   *
   * @return array
   *   Field configurations with filter-specific context.
   */
  protected function prepareFilterFieldConfigurations(
    Index $index,
    string $sapi_fld_nm,
    array $child_fld_nms,
    array $saved_settings
  ): array {
    $context = $this->buildViewsContext($index, $sapi_fld_nm, $child_fld_nms);
    $configs = $this->prepareFieldConfigurations($child_fld_nms, $saved_settings, $context);

    $field_settings = $saved_settings['field_settings'] ?? [];
    foreach ($configs as $field_name => &$config) {
      $config['search_api_type'] = $context['capabilities'][$field_name]['search_api_type'] ?? NULL;
      if (isset($field_settings[$field_name])) {
        $saved = $field_settings[$field_name];
        $config['widget'] = $saved['widget'] ?? 'textfield';
        $config['required'] = $saved['required'] ?? FALSE;
        $config['placeholder'] = $saved['placeholder'] ?? '';
        $config['field_operator'] = $saved['field_operator'] ?? '=';
        $config['expose_field_operator'] = $saved['expose_field_operator'] ?? FALSE;
        $config['select_display_mode'] = $saved['select_display_mode'] ?? 'raw';
        $config['int_range'] = $saved['int_range'] ?? [];
        $config['value'] = $saved['value'] ?? '';
        $config['child_filter_id'] = $saved['child_filter_id'] ?? $this->generateChildfieldFilterId($field_name);
      }
    }

    return $configs;
  }


  /**
   * Builds form elements for a single filter field.
   *
   * Custom callback used by buildConfigurationForm().
   *
   * @param array &$form
   *   The form array.
   * @param array $config
   *   Field configuration array.
   * @param array $options
   *   Form structure options.
   */
  public function buildFilterFieldForm(array &$form, array $config, array $options): void {
    $field_name = $config['field_name'];
    $context_prefix = $options['context_prefix'];
    $is_enabled = $config['enabled'];

    $disabled_state = $this->buildFieldDisabledState($field_name, $context_prefix);

    $form['field_settings'][$field_name] = [
      '#type' => 'details',
      '#title' => $config['label'],
      '#open' => $is_enabled,
    ];

    $form['field_settings'][$field_name]['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable this filter'),
      '#default_value' => $config['enabled'],
    ];

    $this->addWidgetSelector($form, $config, $disabled_state, $context_prefix);

    $form['field_settings'][$field_name]['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $config['label'],
      '#description' => $this->t('Label shown to users.'),
      '#size' => 30,
      '#states' => $disabled_state,
    ];

    $form['field_settings'][$field_name]['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#default_value' => $config['weight'],
      '#description' => $this->t('Fields with lower weights appear first.'),
      '#size' => 5,
      '#states' => $disabled_state,
    ];

    $form['field_settings'][$field_name]['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#default_value' => $config['required'] ?? FALSE,
      '#description' => $this->t('Make this field required when exposed.'),
      '#states' => $disabled_state,
    ];

    $form['field_settings'][$field_name]['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder'),
      '#default_value' => $config['placeholder'] ?? '',
      '#description' => $this->t('Placeholder text for the filter field.'),
      '#states' => $disabled_state,
    ];

    $form['field_settings'][$field_name]['field_operator'] = [
      '#type' => 'select',
      '#title' => $this->t('Operator'),
      '#options' => $this->operatorHelper->getOperatorOptionsForField($config['supports_range'] ?? FALSE),
      '#default_value' => $config['field_operator'] ?? $this->operatorHelper->getDefaultOperator(),
      '#description' => $this->t('Comparison operator for this field.'),
      '#states' => $disabled_state,
    ];

    $form['field_settings'][$field_name]['expose_field_operator'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let user choose operator'),
      '#default_value' => $config['expose_field_operator'] ?? FALSE,
      '#description' => $this->t('Override global setting for this specific field.'),
      '#states' => array_merge(
        $disabled_state,
        ['visible' => [':input[name="options[expose_operators]"]' => ['checked' => TRUE]]]
      ),
    ];

    $form['field_settings'][$field_name]['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value'),
      '#default_value' => $config['value'] ?? '',
      '#description' => $this->t('Filter value (only used when filter is not exposed).'),
      '#states' => $disabled_state,
    ];

    $form['field_settings'][$field_name]['child_filter_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Child field filter identifier'),
      '#default_value' => $config['child_filter_id'] ?? $this->generateChildfieldFilterId($field_name),
      '#description' => $this->t('This will appear in the URL after the ? and parent filter id to identify this child filter. Only alphanumeric characters and underscores. Must be unique within this filter.'),
      '#size' => 20,
      '#maxlength' => 30,
      '#pattern' => '[a-z0-9_]+',
      '#states' => $disabled_state,
    ];

  }

  /**
   * Adds widget type selector with display mode for dropdowns.
   *
   * @param array &$form
   *   The form array.
   * @param array $config
   *   Field configuration.
   * @param array $disabled_state
   *   Disabled state configuration.
   * @param string|null $context_prefix
   *   Form state path prefix.
   */
  protected function addWidgetSelector(
    array &$form,
    array $config,
    array $disabled_state,
    ?string $context_prefix
  ): void {
    $field_name = $config['field_name'];
    $widget_options = [
      'textfield' => $this->t('Text field'),
      'select_indexed' => $this->t('Dropdown (from indexed values)'),
    ];

    if ($config['supports_range'] ?? FALSE) {
      $widget_options['select_range'] = $this->t('Dropdown (consecutive integer range)');
    }

    $form['field_settings'][$field_name]['widget'] = [
      '#type' => 'radios',
      '#title' => $this->t('Widget type'),
      '#options' => $widget_options,
      '#default_value' => $config['widget'] ?? 'textfield',
      '#states' => $disabled_state,
      '#description' => $this->t('Dropdown automatically loads all unique values from the search index.'),
    ];

    if ($context_prefix) {
      $input_name = $context_prefix . '[field_settings][' . $field_name . '][widget]';
    } else {
      $input_name = 'field_settings[' . $field_name . '][widget]';
    }

    if ($config['linkable'] ?? FALSE) {
      $form['field_settings'][$field_name]['select_display_mode'] = [
        '#type' => 'radios',
        '#title' => $this->t('Display mode for dropdown options'),
        '#options' => [
          'raw' => $this->t('Raw value (ID)'),
          'label' => $this->t('Label (entity name)'),
        ],
        '#default_value' => $config['select_display_mode'] ?? 'raw',
        '#description' => $this->t('How to display options in the dropdown. Only applies to entity reference fields.'),
        '#states' => array_merge(
          $disabled_state,
          ['visible' => [':input[name="' . $input_name . '"]' => ['value' => 'select_indexed']]]
        ),
      ];
    }

    if ($config['supports_range'] ?? FALSE) {
      if ($context_prefix) {
        $int_range_base = $context_prefix . '[field_settings][' . $field_name . '][int_range]';
      } else {
        $int_range_base = 'field_settings[' . $field_name . '][int_range]';
      }
      $this->buildIntRangeSubForm(
        $form['field_settings'][$field_name],
        $input_name,
        $int_range_base,
        $disabled_state,
        $config['int_range'] ?? []
      );
    }
  }


  public function buildRangePairForm(
    array &$form,
    array $rangeable_fields,
    array $saved_settings,
    ?string $context_prefix
  ): void {
    $pair = $saved_settings['field_settings']['range_pair'] ?? [];
    $is_enabled = !empty($pair['enabled']);

    if ($context_prefix) {
      $enabled_input = ':input[name="' . $context_prefix . '[field_settings][range_pair][enabled]"]';
    } else {
      $enabled_input = ':input[name="field_settings[range_pair][enabled]"]';
    }
    $disabled_state = ['disabled' => [$enabled_input => ['checked' => FALSE]]];

    $form['field_settings']['range_pair'] = [
      '#type' => 'details',
      '#title' => $this->t('Range pair filter'),
      '#open' => $is_enabled,
      '#description' => $this->t('Exposes From/To inputs. A record matches when its [start,end] overlaps [from,to].'),
    ];

    $form['field_settings']['range_pair']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable range pair filter'),
      '#default_value' => $is_enabled,
    ];

    $form['field_settings']['range_pair']['reserved_keys_notice'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('The identifiers <em>from</em> and <em>to</em> are reserved for this filter. Make sure no other field in this filter uses them as its child field filter identifier.') . '</div>',
      '#states' => ['visible' => [$enabled_input => ['checked' => TRUE]]],
    ];

    $field_options = array_combine($rangeable_fields, $rangeable_fields);

    $form['field_settings']['range_pair']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fieldset title'),
      '#default_value' => $pair['label'] ?? $this->t('Date range'),
      '#description' => $this->t('Title shown above the from/to inputs.'),
      '#size' => 30,
      '#states' => $disabled_state,
    ];

    $form['field_settings']['range_pair']['start_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Start field'),
      '#options' => $field_options,
      '#default_value' => $pair['start_field'] ?? '',
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Child field holding the range start value (e.g. field_date_start).'),
      '#states' => $disabled_state,
    ];

    $form['field_settings']['range_pair']['from_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"From" label'),
      '#default_value' => $pair['from_label'] ?? $this->t('From'),
      '#size' => 20,
      '#states' => $disabled_state,
    ];

    $form['field_settings']['range_pair']['end_field'] = [
      '#type' => 'select',
      '#title' => $this->t('End field'),
      '#options' => $field_options,
      '#default_value' => $pair['end_field'] ?? '',
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Child field holding the range end value (e.g. field_date_end).'),
      '#states' => $disabled_state,
    ];

    $form['field_settings']['range_pair']['to_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('"To" label'),
      '#default_value' => $pair['to_label'] ?? $this->t('To'),
      '#size' => 20,
      '#states' => $disabled_state,
    ];

    if ($context_prefix) {
      $widget_input = $context_prefix . '[field_settings][range_pair][widget]';
      $int_range_base = $context_prefix . '[field_settings][range_pair][int_range]';
    } else {
      $widget_input = 'field_settings[range_pair][widget]';
      $int_range_base = 'field_settings[range_pair][int_range]';
    }

    $form['field_settings']['range_pair']['widget'] = [
      '#type' => 'radios',
      '#title' => $this->t('Widget type'),
      '#options' => [
        'textfield' => $this->t('Text field'),
        'select_range' => $this->t('Dropdown (consecutive integer range)'),
      ],
      '#default_value' => $pair['widget'] ?? 'textfield',
      '#states' => $disabled_state,
    ];

    $this->buildIntRangeSubForm(
      $form['field_settings']['range_pair'],
      $widget_input,
      $int_range_base,
      $disabled_state,
      $pair['int_range'] ?? [],
      ['max' => (int) date('Y'), 'use_current_year_max' => TRUE]
    );
  }


  /**
   * Builds the int_range sub-form elements into a parent form container.
   *
   * Shared by addWidgetSelector() (per-field) and buildRangePairForm() (pair).
   *
   * @param array &$parent
   *   The form container to attach int_range elements to.
   * @param string $widget_input_name
   *   Full HTML input name of the widget radio (used in #states visibility).
   * @param string $int_range_base
   *   Full HTML input name prefix for the int_range group (used to derive
   *   the use_current_year checkbox names for #states).
   * @param array $disabled_state
   *   Base #states disabled condition (merged into all element states).
   * @param array $saved
   *   Saved int_range values (min, max, use_current_year_min/max).
   * @param array $defaults
   *   Override defaults: keys min, max, use_current_year_min, use_current_year_max.
   */
  private function buildIntRangeSubForm(
    array &$parent,
    string $widget_input_name,
    string $int_range_base,
    array $disabled_state,
    array $saved,
    array $defaults = []
  ): void {
    $cur_year_min = $int_range_base . '[use_current_year_min]';
    $cur_year_max = $int_range_base . '[use_current_year_max]';

    $range_visible_state = array_merge($disabled_state, [
      'visible' => [':input[name="' . $widget_input_name . '"]' => ['value' => 'select_range']],
    ]);

    $min_state = array_merge($disabled_state, [
      'visible' => [
        ':input[name="' . $widget_input_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_input_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_min . '"]' => ['checked' => FALSE],
      ],
    ]);

    $max_state = array_merge($disabled_state, [
      'visible' => [
        ':input[name="' . $widget_input_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE],
      ],
      'required' => [
        ':input[name="' . $widget_input_name . '"]' => ['value' => 'select_range'],
        ':input[name="' . $cur_year_max . '"]' => ['checked' => FALSE],
      ],
    ]);

    $parent['int_range'] = ['#type' => 'container'];

    $parent['int_range']['min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum value'),
      '#default_value' => $saved['min'] ?? $defaults['min'] ?? 1,
      '#description' => $this->t('Starting value for the dropdown.'),
      '#size' => 10,
      '#states' => $min_state,
    ];

    $parent['int_range']['use_current_year_min'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use current year as minimum'),
      '#default_value' => $saved['use_current_year_min'] ?? $defaults['use_current_year_min'] ?? FALSE,
      '#description' => $this->t('Automatically set minimum to the current year (overrides minimum value above).'),
      '#states' => $range_visible_state,
    ];

    $parent['int_range']['max'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum value'),
      '#default_value' => $saved['max'] ?? $defaults['max'] ?? 10,
      '#description' => $this->t('Ending value for the dropdown.'),
      '#size' => 10,
      '#states' => $max_state,
    ];

    $parent['int_range']['use_current_year_max'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use current year as maximum'),
      '#default_value' => $saved['use_current_year_max'] ?? $defaults['use_current_year_max'] ?? FALSE,
      '#description' => $this->t('Automatically set maximum to the current year (overrides maximum value above).'),
      '#states' => $range_visible_state,
    ];
  }


  private function generateChildfieldFilterId(string $child_field_name): string {
    $key = preg_replace('/^(field_|rn_|calculated_)/', '', $child_field_name);
    return substr($key, 0, 20);
  }
}
