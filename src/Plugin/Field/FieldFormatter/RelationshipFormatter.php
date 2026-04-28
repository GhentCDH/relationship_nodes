<?php

namespace Drupal\relationship_nodes\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\relationship_nodes\Display\Configurator\FormatterConfigurator;
use Drupal\relationship_nodes\Display\RelationshipDataBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'relationship_formatter' formatter.
 *
 * Displays relationship nodes with their connected entities and metadata.
 * 
 * Supports:
 * - Calculated fields (resolved at render time based on viewing context)
 * - Real fields (direct values from relation nodes)
 * - Sorting and grouping of relationships
 * - Configurable display modes (raw ID, label, link)
 * 
 * The formatter uses FormatterConfigurator to build configuration
 * forms and RelationshipDataBuilder to process and render the data.
 *
 * @FieldFormatter(
 *   id = "relationship_formatter",
 *   label = @Translation("Relationship Formatter"),
 *   description = @Translation("Display relationship nodes with their connected entities."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class RelationshipFormatter extends EntityReferenceFormatterBase implements ContainerFactoryPluginInterface {

  protected RelationshipDataBuilder $displayBuilder;
  protected FormatterConfigurator $configurator;

  /**
   * Constructs a RelationshipFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\relationship_nodes\Display\RelationshipDataBuilder $displayBuilder
   *   The relationship data display builder service.
   * @param \Drupal\relationship_nodes\Display\Configurator\FormatterConfigurator $configurator
   *   The nested field formatter configurator service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    RelationshipDataBuilder $displayBuilder,
    FormatterConfigurator $configurator
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->displayBuilder = $displayBuilder;
    $this->configurator = $configurator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('relationship_nodes.relationship_data_builder'),
      $container->get('relationship_nodes.formatter_configurator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'field_settings' => [],
      'sort_by_field' => '',
      'group_by_field' => '',
    ] + parent::defaultSettings();
  }

  
 /**
 * {@inheritdoc}
 */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    // Get relation bundle
    $relation_bundle = $this->getRelationBundle();
    if (empty($relation_bundle)) {
      $this->configurator->addErrorMessage($elements, $this->t('Cannot determine relation bundle for this field.'));
      return $elements;
    }

    // Get available field names using configurator
    $field_names = $this->configurator->getAvailableFieldNames($relation_bundle);
    if (empty($field_names)) {
      $this->configurator->addErrorMessage($elements, $this->t('No relationship fields available.'));
      return $elements;
    }

    // Ensure settings structure exists
    $settings = $this->getSettings();
    if (!isset($settings['field_settings'])) {
      $settings['field_settings'] = [];
    }

    // PREPARE: Get field configurations with formatter context
    $field_configs = $this->configurator->prepareFormatterFieldConfigurations(
      $relation_bundle,
      $field_names,
      $settings
    );

    // Extract global settings
    $global_settings = [
      'sort_by_field' => $this->getSetting('sort_by_field'),
      'group_by_field' => $this->getSetting('group_by_field'),
    ];

    // RENDER: Build the configuration form
    $this->configurator->buildConfigurationForm(
      $elements,
      $field_configs,
      $global_settings,
      [
        // 'wrapper_key' => NULL, ← VERWIJDERD
        'show_template' => FALSE,
        'show_grouping' => TRUE,
        'show_sorting' => TRUE,
      ]
    );

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $relation_bundle = $this->getRelationBundle();
    if (empty($relation_bundle)) {
      $summary[] = $this->t('Configuration error');
      return $summary;
    }

    // Get available field names using configurator
    $field_names = $this->configurator->getAvailableFieldNames($relation_bundle);
    if (empty($field_names)) {
      $summary[] = $this->t('No fields configured');
      return $summary;
    }

    // Ensure settings structure exists
    $settings = $this->getSettings();
    if (!isset($settings['field_settings'])) {
      $settings['field_settings'] = [];
    }

    // Prepare field configurations
    $field_configs = $this->configurator->prepareFormatterFieldConfigurations(
      $relation_bundle,
      $field_names,
      $settings
    );

    // Build summary using configurator
    $global_settings = [
      'sort_by_field' => $this->getSetting('sort_by_field'),
      'group_by_field' => $this->getSetting('group_by_field'),
    ];

    $formatter_summary = $this->configurator->buildSettingsSummary($field_configs, $global_settings);

    return array_merge($summary, $formatter_summary);
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    if ($items->isEmpty()) {
      return $elements;
    }

    // Collect relation nodes
    $relation_nodes = [];
    foreach ($items as $item) {
      if ($relation_node = $item->entity) {
        $relation_nodes[] = $relation_node;
      }
    }

    if (empty($relation_nodes)) {
      return $elements;
    }

    // Get relation bundle
    $relation_bundle = $this->getRelationBundle();
    if (empty($relation_bundle)) {
      return $elements;
    }

    // Get available field names using configurator
    $field_names = $this->configurator->getAvailableFieldNames($relation_bundle);
    if (empty($field_names)) {
      return $elements;
    }

    // Ensure settings structure exists
    $settings = $this->getSettings();
    if (!isset($settings['field_settings'])) {
      $settings['field_settings'] = [];
    }

    // Prepare field configurations
    $field_configs = $this->configurator->prepareFormatterFieldConfigurations(
      $relation_bundle,
      $field_names,
      $settings
    );

    // Get viewing context (the entity that owns this field)
    $viewing_node = $items->getEntity();

    // Build relationship data with viewing context for calculated fields
    $rel_data = $this->displayBuilder->buildRelationshipData(
      $relation_nodes,
      [
        'field_configs' => $field_configs,
        'viewing_node' => $viewing_node,
      ]
    );

    $relationships = $rel_data['items'];

    // Apply sorting if configured
    if ($sort_field = $this->getSetting('sort_by_field')) {
      $relationships = $this->displayBuilder->sortByField($relationships, $sort_field);
    }

    // Apply grouping if configured
    $grouped = [];
    if ($group_field = $this->getSetting('group_by_field')) {
      $grouped = $this->displayBuilder->groupByField($relationships, $group_field);
    }

    // Build field metadata for template
    $fields_metadata = $this->configurator->buildFieldsMetadata($field_configs);
    $elements[0] = [
      '#theme' => 'relationship_field',
      '#items' => $relationships,
      '#groups' => $grouped,
      '#fields' => $fields_metadata,
      '#summary' => [
        'total' => count($relation_nodes),
        'has_groups' => !empty($grouped),
        'group_count' => count($grouped),
      ],
      '#attached' => [
        'library' => ['relationship_nodes/relationship_field'],
      ],
    ];
    $rel_data['cache']->applyTo($elements[0]);
    return $elements;
  }

  /**
   * Gets the relation bundle from the field definition.
   * 
   * Extracts the target bundle configuration from the entity reference field.
   * For relationship formatters, this should be a relation node bundle.
   *
   * @return string|null
   *   The relation bundle machine name, or NULL if not properly configured.
   */
  protected function getRelationBundle(): ?string {
    $target_bundles = $this->fieldDefinition->getSetting('handler_settings')['target_bundles'] ?? [];
    
    if (empty($target_bundles)) {
      return NULL;
    }

    // Get first target bundle (relation bundle)
    return reset($target_bundles);
  }
}