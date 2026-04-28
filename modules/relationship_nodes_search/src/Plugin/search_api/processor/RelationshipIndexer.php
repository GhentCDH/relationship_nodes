<?php

namespace Drupal\relationship_nodes_search\Plugin\search_api\processor;


use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\relationship_nodes_search\SearchAPI\Processor\RelationProcessorProperty;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\search_api\SearchApiException;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\relationship_nodes\RelationData\TermHelper\MirrorProvider;
use Drupal\relationship_nodes_search\Views\Parser\NestedFieldResultViewsParser;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api\IndexInterface;


/**
 * Adds nested relationship data to specified fields.
 *
 * @SearchApiProcessor(
 *   id = "relationship_indexer",
 *   label = @Translation("Relationship Indexer"),
 *   description = @Translation("Nests relationship data into specified fields."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */

class RelationshipIndexer extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityFieldManagerInterface $entityFieldManager;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected BundleInfoService $bundleInfoService;
  protected FieldNameResolver $fieldResolver;
  protected BundleSettingsManager $settingsManager;
  protected MirrorProvider $mirrorProvider;
  protected NestedFieldResultViewsParser $resultParser;
  protected CalculatedFieldHelper $calculatedFieldHelper; 


  /**
   * Constructs a RelationshipIndexer object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager service.
   * @param LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param BundleInfoService $bundleInfoService
   *   The relation bundle info service.
   * @param FieldNameResolver $fieldResolver
   *   The field name resolver service.
   * @param BundleSettingsManager $settingsManager
   *   The relation bundle settings manager service.
   * @param MirrorProvider $mirrorProvider
   *   The mirror term provider service.
   * @param NestedFieldResultViewsParser $resultParser
   *   The child field entity reference helper service.
   * @param CalculatedFieldHelper $calculatedFieldHelper
   *   The calculated field helper service.
   */
  public function __construct(
    array $configuration, 
    $plugin_id, 
    $plugin_definition, 
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entityFieldManager,
    LoggerChannelFactoryInterface $loggerFactory, 
    BundleInfoService $bundleInfoService,
    FieldNameResolver $fieldResolver, 
    BundleSettingsManager $settingsManager, 
    MirrorProvider $mirrorProvider,
    NestedFieldResultViewsParser $resultParser,
    CalculatedFieldHelper $calculatedFieldHelper
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entityFieldManager;
    $this->loggerFactory = $loggerFactory;
    $this->bundleInfoService = $bundleInfoService;
    $this->fieldResolver = $fieldResolver;
    $this->settingsManager = $settingsManager;
    $this->mirrorProvider = $mirrorProvider;
    $this->resultParser = $resultParser;
    $this->calculatedFieldHelper = $calculatedFieldHelper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container, 
    array $configuration, 
    $plugin_id, 
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('logger.factory'),
      $container->get('relationship_nodes.bundle_info_service'),
      $container->get('relationship_nodes.field_name_resolver'),
      $container->get('relationship_nodes.bundle_settings_manager'),
      $container->get('relationship_nodes.mirror_provider'),
      $container->get('relationship_nodes_search.nested_field_result_views_parser'),
      $container->get('relationship_nodes.calculated_field_helper')
    );
  }


  /**
   * {@inheritdoc}
   */
    public static function supportsIndex(IndexInterface $index) {
    // Check if the index has entity datasources.
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId()) {
        return TRUE;
      }
    }
    return FALSE;
  }


  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    if (!$datasource || !$datasource->getEntityTypeId()) {
      return [];
    }

    $index_bundles = $datasource->getConfiguration()['bundles']['selected']  ?? [];
    
    $relation_bundles = [];
    foreach($index_bundles as $index_bundle){
      $related_relationships = $this->bundleInfoService->getRelationInfoForTargetBundle($index_bundle);
      if (!is_array($related_relationships)) {
        continue;
      }
      foreach($related_relationships as $relation_bundle => $info){
        if(!in_array($relation_bundle, $relation_bundles)){
          $relation_bundles[] = $relation_bundle;
        }
      }
    }

    $calc_fld_nms = $this->calculatedFieldHelper->getCalculatedFieldNames(NULL, NULL, TRUE);
    $properties = [];

    foreach($relation_bundles as $relationship_node_type){
      $definition = [
        'label' => $this->t('Related nodes of type @type', ['@type' => $relationship_node_type]),
        'description' => $this->t('All related @type nodes, with selectable fields.', ['@type' => $relationship_node_type]),
        'type' => 'array', 
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
        'definition_class_settings' => [
          'bundle' => $relationship_node_type,
        ],
      ];
      
      $property = new RelationProcessorProperty(
        $definition, 
        $this->entityFieldManager,
        $this->loggerFactory->get('relationship_nodes_search'),
        $calc_fld_nms
      );
      $properties["relationship_info__{$relationship_node_type}"] = $property;
    }

    return $properties;
  }


  /**
   * Cf Partially based on code of ReverseEntityReferences
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    try {
      $entity = $item->getOriginalObject()->getValue();

    }
    catch (SearchApiException $e) {
      $this->loggerFactory->get('relationship_nodes_search')->error(
        'Failed to get entity from search item: @message',
        ['@message' => $e->getMessage()]
      );
      return;
    }

    $item_relation_info_list = $this->bundleInfoService->getRelationInfoForTargetBundle($entity->getType());

    if (!($entity instanceof EntityInterface) || empty($item_relation_info_list)) {
      return;
    }

    $prefix = 'relationship_info__';
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($item->getFields() as $sapi_fld) {
      $relation_nodetype_name = $sapi_fld->getPropertyPath();     
      if ($sapi_fld->getDatasourceId() != $item->getDatasourceId() || !str_starts_with($relation_nodetype_name, $prefix) || !isset($sapi_fld->getConfiguration()['nested_fields'])) {
        continue;
      }

      $child_fld_configs = $sapi_fld->getConfiguration()['nested_fields'];
      $relationship_node_type = substr($relation_nodetype_name, strlen($prefix));
      
      if(!is_array($child_fld_configs) || empty($child_fld_configs) || !isset($item_relation_info_list[$relationship_node_type])){
        continue;
      }

      $relation_info = $item_relation_info_list[$relationship_node_type];
      $serialized = [];

      foreach($relation_info['join_fields'] as $join_field){
        if(!in_array($join_field, $this->fieldResolver->getRelatedEntityFields())){
          $this->loggerFactory->get('relationship_nodes_search')->error(
            'Invalid join field name: @field',
            ['@field' => $join_field]
          );
          continue;
        }
        
        $result = $node_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $relationship_node_type)
          ->condition($join_field, $entity->id())
          ->execute();
        
        if(empty($result)){
          continue;
        }

        try {
        $entities = $node_storage->loadMultiple($result);
        }
        catch (\Exception $e) {
          $this->loggerFactory->get('relationship_nodes_search')->error(
            'Failed to load relationship entities for node @nid (type: @type): @message',
            [
              '@nid' => $entity->id(),
              '@type' => $relationship_node_type,
              '@message' => $e->getMessage()
            ]
          );
          continue;
        }
      
        if (empty($entities)) {
          $this->loggerFactory->get('relationship_nodes_search')->warning(
            'Query found @count relationship nodes but loadMultiple returned empty for node @nid (type: @type)',
            [
              '@count' => count($result),
              '@nid' => $entity->id(),
              '@type' => $relationship_node_type
            ]
          );
          continue;
        }

        $calc_fld_nms = $this->calculatedFieldHelper->getCalculatedFieldNames(NULL, NULL, TRUE);
       
        foreach($entities as $relationship_entity){
          $nested_values = [];
          foreach ($child_fld_configs as $child_fld_nm => $child_fld_config){
            if(in_array($child_fld_nm, $calc_fld_nms)){
              continue; // calculated fields are processed below
            }
            try {
              $field_values = $relationship_entity->get($child_fld_nm)->getValue();
            }
            catch (\Exception $e) {
              $this->loggerFactory->get('relationship_nodes_search')->warning(
                'Failed to get field value for @field on relationship node @rel_nid: @message',
                [
                  '@field' => $child_fld_nm,
                  '@rel_nid' => $relationship_entity->id(),
                  '@message' => $e->getMessage()
                ]
              );
              $nested_values[$child_fld_nm] = NULL;
              continue;
            }
            if (empty($field_values)) {
              $nested_values[$child_fld_nm] = NULL;
              continue;
            }

            $values = [];
            $drupal_field_info = $child_fld_config['drupal_field'];
            $is_ref = $drupal_field_info['type'] === 'entity_reference';
            $target_type = $is_ref ? $drupal_field_info['target_type'] : NULL;

            foreach ($field_values as $field_value) {
              $extracted = $this->extractSingleValue($field_value, $target_type);
              if ($extracted !== NULL) {
                // Format volgens configured type
                $formatted = $this->mapSearchApiFieldTypeToElasticType($extracted, $child_fld_config['type']);
                if ($formatted !== NULL) {
                  $values[] = $formatted;
                }
              }
            }
            
            $nested_values[$child_fld_nm] = count($values) === 1 ? reset($values) : $values;
          }

          try {
            $this->fillCalculatedFields($nested_values, $entity, $relationship_entity, $join_field, $item->getLanguage());
          }
          catch (\Exception $e) {
            $this->loggerFactory->get('relationship_nodes_search')->error(
              'Failed to fill calculated fields for relationship @rel_nid: @message',
              [
                '@rel_nid' => $relationship_entity->id(),
                '@message' => $e->getMessage()
              ]
            );
          }

          $serialized[] = $nested_values;
        }   
      }
      if(empty($serialized)){
        $sapi_fld->setValues([[]]);
      } else {
        $sapi_fld->setValues($serialized);
      }
    }  
  }


  /**
   * Fills calculated fields for a relationship.
   *
   * @param array $nested_values
   *   The nested values array (passed by reference).
   * @param EntityInterface $entity
   *   The target entity.
   * @param EntityInterface $relationship_entity
   *   The relationship entity.
   * @param string $join_field
   *   The join field name.
   */
  protected function fillCalculatedFields(
    array &$nested_values, 
    EntityInterface $entity, 
    EntityInterface $relationship_entity, 
    string $join_field,
    string $langcode
  ): void { 
    $calc_fld_nms = $this->calculatedFieldHelper->getCalculatedFieldNames();
    
    $nested_values[$calc_fld_nms['this_entity']['id']] = isset($nested_values[$join_field]) ? $nested_values[$join_field] : '';
    $nested_values[$calc_fld_nms['this_entity']['name']] = $entity->label();

    $node_storage = $this->entityTypeManager->getStorage('node');
    $other_field = $this->fieldResolver->getOppositeRelatedEntityField($join_field);
    $other_parsed = $this->resultParser->parseEntityReferenceString($nested_values[$other_field] ?? NULL);

    $related_entity = !empty($other_parsed['id']) ? $node_storage->load($other_parsed['id']) : NULL;
    if (!empty($related_entity)) {
      $related_label = $related_entity->hasTranslation($langcode)
        ? $related_entity->getTranslation($langcode)->label()
        : $related_entity->label();
      $nested_values[$calc_fld_nms['related_entity']['name']] = $related_label;
      $nested_values[$calc_fld_nms['related_entity']['id']] = isset($nested_values[$other_field]) ? $nested_values[$other_field] : '';
    }

    $relation_field = $this->fieldResolver->getRelationTypeField();
    $bundle_info = $this->settingsManager->getBundleInfo($relationship_entity->getType());
    if ($bundle_info && $bundle_info->isTypedRelation() && !empty($nested_values[$relation_field])) {
      $relation_parsed = $this->resultParser->parseEntityReferenceString($nested_values[$relation_field]);

      if ($join_field === $this->fieldResolver->getRelatedEntityFields(2) && !empty($relation_parsed['id'])) {
        $nested_values[$calc_fld_nms['relation_type']['name']] = $this->mirrorProvider->getMirrorLabelFromId($relation_parsed['id'], $langcode);
      } else {
        $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
        $relation_term = !empty($relation_parsed['id']) ? $term_storage->load($relation_parsed['id']) : NULL;
        if (!empty($relation_term)) {
          $term_label = $relation_term->hasTranslation($langcode)
            ? $relation_term->getTranslation($langcode)->getName()
            : $relation_term->getName();
          $nested_values[$calc_fld_nms['relation_type']['name']] = $term_label;
        }
      }
    }
  }


  /**
   * Extracts a single value from a field value array.
   *
   * Handles different field value structures:
   * - Entity references: returns 'entity_type/id' format
   * - Simple values: returns the 'value' key
   * - Arrays: returns first element
   *
   * @param mixed $value
   *   The field value to extract from.
   * @param string|null $target_type
   *   The target entity type for entity reference fields.
   *
   * @return mixed|null
   *   The extracted value, or NULL if empty.
   */
  protected function extractSingleValue(mixed $value, ?string $target_type = NULL): mixed {
    if (empty($value)) {
        return NULL;
    }

    if (isset($value['target_id'])) {
        if(empty($target_type)){
            return $value['target_id'];
        }
        return $target_type . '/' . $value['target_id'];   
    }
    
    if (isset($value['value'])) {
        return $value['value'];
    }
    
    if (is_array($value)) {
        return reset($value) ?: NULL;
    }
    
    return $value;
  }  

  /**
   * Formats a value according to its Search API data type.
   *
   * @param mixed $value
   *   The value to format.
   * @param string $type
   *   The Search API data type.
   *
   * @return mixed
   *   The formatted value appropriate for indexing.
   */
  protected function mapSearchApiFieldTypeToElasticType($value, string $type) {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    switch ($type) {
      case 'integer':
        return is_numeric($value) ? (int) $value : NULL;

      case 'decimal':
        return is_numeric($value) ? (float) $value : NULL;

      case 'boolean':
        return (bool) $value;

      case 'date':
        // Convert to ISO 8601 string instead of timestamp
        if (is_numeric($value)) {
          // Unix timestamp → ISO string
          return date('c', (int) $value);  // '2025-12-26T00:00:00+00:00'
        }
        // Already a date string
        $timestamp = strtotime($value);
        return $timestamp !== FALSE ? date('c', $timestamp) : NULL;
      case 'string':
      case 'text':
      default:
        return (string) $value;
    }
  }
}