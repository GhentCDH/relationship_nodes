<?php

namespace Drupal\relationship_nodes_search\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Plugin\views\field\SearchApiStandard;
use Drupal\views\ResultRow;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\search_api\Entity\Index;
use Drupal\relationship_nodes_search\Views\Parser\NestedFieldResultViewsParser;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;
use Drupal\relationship_nodes_search\Views\Config\NestedFieldViewsFieldConfigurator;


/**
 * Views field plugin for displaying nested relationship data.
 *
 * @ViewsField("search_api_relationship_field")
 */
class RelationshipField extends SearchApiStandard implements ContainerFactoryPluginInterface {
    
  protected NestedFieldViewsFieldConfigurator $fieldConfigurator;
  protected NestedFieldResultViewsParser $resultParser;
  protected CalculatedFieldHelper $calculatedFieldHelper;

  /**
   * Constructs a RelationshipField object.
   *
   * @param array $configuration
   *    The plugin configuration.
   * @param string $plugin_id
   *    The plugin ID.
   * @param mixed $plugin_definition
   *    The plugin definition.
   * @param NestedFieldViewsFieldConfigurator $fieldConfigurator
   *    The field configurator service.
   * @param NestedFieldResultViewsParser $resultParser
   *    The child reference helper service.
   * @param CalculatedFieldHelper $calculatedFieldHelper
   *    The calculated field helper service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    NestedFieldViewsFieldConfigurator $fieldConfigurator,
    NestedFieldResultViewsParser $resultParser,
    CalculatedFieldHelper $calculatedFieldHelper
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fieldConfigurator = $fieldConfigurator;
    $this->resultParser = $resultParser;
    $this->calculatedFieldHelper = $calculatedFieldHelper;
  }
  

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('relationship_nodes_search.nested_field_views_field_configurator'),
      $container->get('relationship_nodes_search.nested_field_result_views_parser'),
      $container->get('relationship_nodes.calculated_field_helper')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    foreach($this->getDefaultRelationFieldOptions() as $option => $default){
      $options[$option] = ['default' => $default];
    }
    return $options;
  }


  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) { 
    parent::buildOptionsForm($form, $form_state);
    
    $config = $this->fieldConfigurator->validateAndPreparePluginForm(
      $this->getIndex(),
      $this->definition,
      $form
    );

    if (!$config) {
      return;
    }

    $this->fieldConfigurator->buildFieldDisplayForm(
      $form,
      $config['index'],
      $config['field_name'],
      $config['available_fields'],
      $this->options
    );
  }



  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);
    $this->fieldConfigurator->savePluginOptions(
      $form_state,
      $this->getDefaultRelationFieldOptions(),
      $this->options
    );
  }  


  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $values, $field = NULL) {
    $index = $this->getIndex();
    $sapi_fld_nm = $this->fieldConfigurator->getPluginParentFieldName($this->definition);      
    if (!$index instanceof Index || empty($sapi_fld_nm)) {
      return parent::getValue($values, $field);
    }

    $values_arr = get_object_vars($values);
    if(empty($values_arr) || !is_array($values_arr)){
      return parent::getValue($values, $field);
    }

    if(empty($values_arr[$sapi_fld_nm])){
      return parent::getValue($values, $field);
    }
    $value = $values_arr[$sapi_fld_nm];
    if(!is_array($value)){
      return parent::getValue($values, $field);
    }
    return $value;
  }


  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $nested_data = $this->getValue($values);
    if (empty($nested_data) || !is_array($nested_data)) {
      return '';
    }

    $template_data = $this->prepareTemplateData($nested_data);
    $theme_hook = str_replace('-', '_', $this->options['template']);
    
    return [
      '#theme' => $theme_hook,
      '#items' => $template_data['items'],
      '#groups' => $template_data['groups'],
      '#summary' => $template_data['summary'],
      '#fields' => $template_data['fields'],
      '#row' => $values,
      '#cache' => [
        'contexts' => ['languages:language_content'],
        'tags' => [
          'node_list',
          'relationship_nodes_search:relationships',
        ],
      ],
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }


  /**
   * {@inheritdoc}
   */
  public function renderItems($items) {
    return [];
  }


  /**
   * {@inheritdoc}
   */
  public function advancedRender(ResultRow $values) {
    return $this->render($values);
  }


  /**
   * {@inheritdoc}
   */
  public function render_item($count, $item) {
    return '';
  }


  /**
   * Prepares data to be sent to the twig template.
   *
   * @param array $nested_data
   *   The nested relationship data from the search index.
   *
   * @return array
   *   Template data array containing:
   *   - relationships: processed relationship items
   *   - grouped: relationships grouped by configured field
   *   - summary: metadata about the relationships
   *   - fields: field configuration and metadata
   */
  protected function prepareTemplateData(array $nested_data): array {
    $index = $this->getIndex();
    $sapi_fld_nm = $this->fieldConfigurator->getPluginParentFieldName($this->definition);
    
    if (!$index instanceof Index || empty($sapi_fld_nm)) {
      return $this->getEmptyTemplateData();
    }

    $field_settings = $this->options['field_settings'] ?? [];
    $relationships = $this->buildRelationshipsArray($nested_data, $field_settings, $index, $sapi_fld_nm);
    $relationships = $this->sortRelationships($relationships);
    $grouped = $this->groupRelationships($relationships);
    $child_field_metadata = $this->buildFieldsMetadata($field_settings);
    
    return [
      'items' => $relationships,
      'groups' => $grouped,
      'summary' => $this->buildSummary($relationships, $child_field_metadata, $grouped),
      'fields' => $child_field_metadata,
    ];
  }


  /**
   * Builds relationships array from nested data.
   *
   * Batch loads all referenced entities for performance, then processes
   * each relationship item's fields using the preloaded entity cache.
   *
   * @param array $nested_data
   *   The raw nested relationship data from the index.
   * @param array $field_settings
   *   Field configuration from the Views field settings.
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The Search API field name for the relationship.
   *
   * @return array
   *   Array of processed relationship items with resolved field values.
   */
  protected function buildRelationshipsArray(array $nested_data, array $field_settings, Index $index, string $sapi_fld_nm): array {
    if (empty($nested_data)) {
      return [];
    }

    // Step 1: Batch load all needed entities via helper service
    $preloaded_entities = $this->resultParser->batchLoadFromIndexedData(
      $nested_data, 
      $field_settings, 
      $index, 
      $sapi_fld_nm
    );
    
    // Step 2: Build relationships using cached entities
    $relationships = [];
    
    foreach ($nested_data as $item) {
      $item_with_values = [];
      foreach ($field_settings as $child_fld_nm => $settings) {
        if (empty($settings['enabled']) || !isset($item[$child_fld_nm])) {
          continue;
        }

        // Use helper's cache-aware processing
        $field_value = $this->resultParser->processFieldValuesWithCache(
          $item[$child_fld_nm], 
          $settings, 
          $preloaded_entities
        );
        
        if ($field_value !== NULL) {
          $item_with_values[$child_fld_nm] = $field_value;
        }
      }       

      if (!empty($item_with_values)) {
        $relationships[] = $item_with_values;
      }
    }   

    return $relationships;
  }


  /**
   * Sorts relationships based on configured sort field.
   *
   * @param array $relationships
   *   The relationships array to sort.
   *
   * @return array
   *   The sorted relationships array.
   */
  protected function sortRelationships(array $relationships): array {
    if (empty($this->options['sort_by_field']) || empty($relationships)) {
      return $relationships;
    }
    
    $sort_fld_nm = $this->options['sort_by_field'];
    usort($relationships, function($a, $b) use ($sort_fld_nm) {
      if (!isset($a[$sort_fld_nm]) || !isset($b[$sort_fld_nm])) {
        return 0;
      }
      
      $val_a = $a[$sort_fld_nm]['field_values'][0]['value'] ?? '';
      $val_b = $b[$sort_fld_nm]['field_values'][0]['value'] ?? '';
      
      return strcasecmp($val_a, $val_b);
    });  
    return $relationships;
  }


  /**
   * Groups relationships based on configured group field.
   *
   * @param array $relationships
   *   The relationships array to group.
   *
   * @return array
   *   Associative array with group keys and relationship arrays as values.
   */
  protected function groupRelationships(array $relationships): array {
    if (empty($this->options['group_by_field']) || empty($relationships)) {
      return [];
    }
    
    $grouped = [];
    $group_fld_nm  = $this->options['group_by_field'];
    
    foreach ($relationships as $item) {
      if (!isset($item[$group_fld_nm ])) {
        continue;
      }
      
      $group_key = $item[$group_fld_nm ]['field_values'][0]['value'] ?? 'ungrouped';
      
      if (!isset($grouped[$group_key])) {
        $grouped[$group_key] = [];
      }
      $grouped[$group_key][] = $item;
    }  
    return $grouped;
  }


  /**
   * Builds fields metadata for the template.
   *
   * @param array $field_settings
   *   Field configuration from Views settings.
   *
   * @return array
   *   Array of field metadata sorted by weight.
   */
  protected function buildFieldsMetadata(array $field_settings): array {
    $fields = [];
    
    foreach ($field_settings as $child_fld_nm => $settings) {
      if (empty($settings['enabled'])) {
        continue;
      }
      
      $fields[$child_fld_nm] = [
        'name' => $child_fld_nm,
        'label' => !empty($settings['label']) 
          ? $settings['label'] 
          :  $this->calculatedFieldHelper->formatCalculatedFieldLabel($child_fld_nm),
        'weight' => $settings['weight'] ?? 0,
        'hide_label' => !empty($settings['hide_label']),
        'display_mode' => $settings['display_mode'] ?? 'id',
        'multiple_separator' => $settings['multiple_separator'] ?? ', '
      ];
    }
    return $this->fieldConfigurator->sortFieldsByWeight($fields);
  }


  /**
   * Builds summary data for the template.
   *
   * @param array $relationships
   *   The processed relationships array.
   * @param array $fields
   *   The fields metadata.
   * @param array $grouped
   *   The grouped relationships array.
   *
   * @return array
   *   Summary array with total count, field list, and grouping info.
   */
  protected function buildSummary(array $relationships, array $fields, array $grouped): array {
    return [
      'total' => count($relationships),
      'fields' => array_keys($fields),
      'has_groups' => !empty($grouped),
      'group_count' => count($grouped),
    ];
  }


  /**
   * Gets empty template data structure.
   *
   * @return array
   *   Empty template data array with all required keys.
   */
  protected function getEmptyTemplateData(): array {
    return [
      'items' => [],
      'groups' => [],
      'summary' => [
        'total' => 0,
        'fields' => [],
        'has_groups' => FALSE,
        'group_count' => 0
      ],
      'fields' => [],
    ];
  }


  /**
   * Gets default options for the field configuration form in Views.
   *
   * @return array
   *   Array of default option values.
   */
  protected function getDefaultRelationFieldOptions(): array{
    return [
      'field_settings' => [],
      'sort_by_field' => '',
      'group_by_field' => '',
      'template' => 'relationship-field',
    ];
  }
}