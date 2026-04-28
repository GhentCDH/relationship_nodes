<?php


namespace Drupal\relationship_nodes\RelationBundle;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\FieldConfigStorage;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;


/**
 * Service for retrieving relationship bundle information.
 *
 * Provides methods to analyze relationship configurations, target bundles,
 * and connection information between relation and target entities.
 */
class BundleInfoService {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityFieldManagerInterface $fieldManager;
  protected EntityTypeBundleInfoInterface $bundleInfo;
  protected FieldNameResolver $fieldNameResolver;
  protected BundleSettingsManager $settingsManager;
  protected RelationshipFieldManager $relationFieldManager;


  /**
   * Constructs a BundleInfoService object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param EntityFieldManagerInterface $fieldManager
   *   The entity field manager.
   * @param EntityTypeBundleInfoInterface $bundleInfo
   *   The entity type bundle info service.
   * @param FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager.
   * @param RelationshipFieldManager $relationFieldManager
   *   The field configurator.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $fieldManager,
    EntityTypeBundleInfoInterface $bundleInfo,
    FieldNameResolver $fieldNameResolver,
    BundleSettingsManager $settingsManager,
    RelationshipFieldManager $relationFieldManager
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fieldManager = $fieldManager;
    $this->bundleInfo = $bundleInfo;
    $this->fieldNameResolver = $fieldNameResolver;
    $this->settingsManager = $settingsManager;
    $this->relationFieldManager = $relationFieldManager;
  }


  /**
   * Gets relation bundle information for a bundle.
   *
   * @param string $bundle
   *   The bundle ID.
   * @param array $fields
   *   Optional array of field definitions.
   *
   * @return array
   *   Array containing relation bundle information.
   */
  public function getRelationBundleInfo(string $bundle, array $fields = []): array {
    $bundle_info = $this->settingsManager->getBundleInfo($bundle);    
    if (!$bundle_info || !$bundle_info->isRelation()) {
      return [];
    }

    if (empty($fields)) {
      $fields = $this->fieldManager->getFieldDefinitions('node', $bundle);
    }

    $related_bundles = [];


    foreach ($this->fieldNameResolver->getRelatedEntityFields() as $field_name) {
      if (!isset($fields[$field_name])) {
        continue;
      }

      // Check if field is actually a FieldConfig before passing it
      if (!$fields[$field_name] instanceof FieldConfig) {
        continue;
      }

      $related_bundles[$field_name] = $this->getFieldTargetBundles($fields[$field_name]);
    }

    $info = [
      'related_bundles_per_field' => $related_bundles,
      'has_relationtype' => false
    ];

    if (!$bundle_info->isTypedRelation()) {
      return $info;
    }

    $relation_type_field_name = $this->fieldNameResolver->getRelationTypeField();

    // Add null checks for field existence and type
    if (
      !isset($fields[$relation_type_field_name]) ||
      !$fields[$relation_type_field_name] instanceof FieldConfig
    ) {
      return $info;
    }

    $target_bundles = $this->getFieldTargetBundles($fields[$relation_type_field_name]);

    if (count($target_bundles) != 1) {
      return $info;
    }

    $vocab = reset($target_bundles);

    $info['has_relationtype'] = true;
    $info['vocabulary'] = $vocab;

    return $info;
  }


  /**
   * Gets relation information for a target bundle.
   *
   * @param string $target_bundle
   *   The target bundle ID.
   *
   * @return array
   *   Array of relation information keyed by relation bundle ID.
   */
  public function getRelationInfoForTargetBundle(string $target_bundle): array {
    $all_bundles_info = $this->bundleInfo->getBundleInfo('node');
    $relation_info = [];

    foreach ($all_bundles_info as $bundle_id => $bundle_array) {
      if (empty($bundle_array['relation_bundle']) || empty($bundle_array['relation_bundle']['related_bundles_per_field'])) {
        continue;
      }

      $related_bundles_per_field = $bundle_array['relation_bundle']['related_bundles_per_field'];
      $join_fields = [];
      $other_bundles = [];

      foreach ($related_bundles_per_field as $field_name => $related_bundles) {
        if (in_array($target_bundle, $related_bundles)) {
          $join_fields[] = $field_name;
        } else {
          $other_bundles = $related_bundles;
        }
      }

      if (empty($join_fields)) {
        continue;
      }

      $relation_info[$bundle_id] = [
        'join_fields' => $join_fields,
        'related_bundles' =>  count($join_fields) == 1 ? $other_bundles : [$target_bundle],
        'relation_bundle_info' => $bundle_array['relation_bundle'],
      ];
    }

    return $relation_info;
  }


  /**
   * Gets connection information between a relation and target bundle.
   *
   * @param string $relation_bundle
   *   The relation bundle ID.
   * @param string $target_bundle
   *   The target bundle ID.
   *
   * @return array
   *   Array containing join fields and relation info.
   */
  public function getBundleConnectionInfo(string $relation_bundle, string $target_bundle): array {
    $relation_info = $this->getRelationBundleInfo($relation_bundle);
    if (empty($relation_info) || empty($relation_info['related_bundles_per_field'])) {
      return [];
    }

    $join_fields = [];
    foreach ($relation_info['related_bundles_per_field'] as $field => $bundles_arr) {
      if (in_array($target_bundle, $bundles_arr)) {
        $join_fields[] = $field;
      }
    }

    return empty($join_fields) ? [] : ['join_fields' => $join_fields, 'relation_info' => $relation_info];
  }


  /**
   * Gets all relation bundles.
   *
   * @param string|null $entity_type_id
   *   Optional entity type ID to filter by.
   *
   * @return array
   *   Array of relation bundle entities keyed by bundle ID.
   */
  public function getAllRelationBundles(?string $entity_type_id = null): array {
    $entity_types = ['node_type', 'taxonomy_vocabulary'];
    if ($entity_type_id !== null  && !in_array($entity_type_id, $entity_types)) {
      return [];
    }

    $input = $entity_type_id !== null ? [$entity_type_id] : $entity_types;

    $result = [];
    foreach ($input as $entity_type) {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      if (!$storage instanceof EntityStorageInterface) {
        continue;
      }

      $all = $storage->loadMultiple();
      foreach ($all as $type) {
        $bundle_info = $this->settingsManager->getBundleInfo($type); 
        if ($bundle_info && $bundle_info->isRelation()) {
          $result[$type->id()] = $type;
        }
      }
    }
    return $result;
  }


  /**
   * Gets all relation bundles from configuration import storage.
   *
   * @param StorageInterface $config_storage
   *   The configuration storage.
   * @param string|null $entity_type_id
   *   Optional entity type ID to filter by.
   *
   * @return array
   *   Array of configuration data keyed by config name.
   */
    public function getAllCimRelationBundles(StorageInterface $config_storage, ?string $entity_type_id = null): array {
    $entity_types = ['node_type', 'taxonomy_vocabulary'];
    if ($entity_type_id !== null  && !in_array($entity_type_id, $entity_types)) {
      return [];
    }

    $input = $entity_type_id !== null ? [$entity_type_id] : $entity_types;

    $result = [];
    foreach ($input as $entity_type) {
      $prefix = $this->settingsManager->getEntityTypeConfigPrefix($entity_type);
      $all = $config_storage->listAll($prefix);
      foreach ($all as $config_name) {
        $config_data = $config_storage->read($config_name);
        if ($this->settingsManager->isCimRelationEntity($config_data)) {
          $result[$config_name] = $config_data;
        }
      }
    }
    return $result;
  }


  /**
   * Gets all typed relation node types.
   *
   * @return array
   *   Array of typed relation node type entities keyed by bundle ID.
   */
  public function getAllTypedRelationNodeTypes(): array {
    $result = [];
    $relation_node_types = $this->getAllRelationBundles('node_type');
    foreach ($relation_node_types as $bundle_id => $node_type) {
      $bundle_info = $this->settingsManager->getBundleInfo($node_type);    
      if ($bundle_info && $bundle_info->isTypedRelation()) {
        $result[$bundle_id] = $node_type;
      }
    }
    return $result;
  }


  /**
   * Gets all typed relation node types from configuration import storage.
   *
   * @param StorageInterface $config_storage
   *   The configuration storage.
   *
   * @return array
   *   Array of configuration data keyed by config name.
   */
  public function getAllCimTypedRelationNodeTypes(StorageInterface $config_storage): array {
    $result = [];
    $all_cim_bundles = $this->getAllCimRelationBundles($config_storage, 'node_type');
    foreach ($all_cim_bundles as $config_name => $config_data) {
      if ($this->settingsManager->isCimTypedRelationNodeType($config_data)) {
        $result[$config_name] = $config_data;
      }
    }
    return $result;
  }


  /**
   * Gets target bundles for a field configuration.
   *
   * @param FieldConfig $field_config
   *   The field configuration.
   *
   * @return array
   *   Array of target bundle IDs.
   */
  private function getFieldTargetBundles(FieldConfig $field_config): array {
    if ($field_config === null || $field_config->getType() != 'entity_reference') {
      return [];
    }

    $settings = $field_config->get('settings') ?? [];
    $handler_settings = $settings['handler_settings'] ?? [];
    $target_bundles = $handler_settings['target_bundles'] ?? [];

    return is_array($target_bundles) ? $target_bundles : [];
  }


  /**
   * Gets node types linked to a vocabulary.
   *
   * @param ConfigEntityBundleBase $vocab
   *   The vocabulary entity.
   *
   * @return array
   *   Array of node type entities keyed by node type ID.
   */
  public function getNodeTypesLinkedToVocab(ConfigEntityBundleBase $vocab): array {
    $bundle_info = $this->settingsManager->getBundleInfo($vocab);  
    if (!$bundle_info || !$bundle_info->isRelation()) {
      return [];
    }

    $node_types = $this->getAllTypedRelationNodeTypes();


    if (empty($node_types)) {
      return [];
    }

    $relation_type_field = $this->fieldNameResolver->getRelationTypeField() ?? null;
    $field_storage = $this->entityTypeManager->getStorage('field_config') ?? null;

    if (!is_string($relation_type_field) || !($field_storage instanceof FieldConfigStorage)) {
      return [];
    }
    $result = [];
    foreach ($node_types as $node_type_id => $node_type) {
      $field_config = $field_storage->load("node.{$node_type->id()}.$relation_type_field");
      if (!($field_config instanceof FieldConfig)) {
        continue;
      }
      $target_bundles = $this->getFieldTargetBundles($field_config);
      $target_bundle = reset($target_bundles);
      if ($target_bundle === $vocab->id()) {
        $result[$node_type_id] = $node_type;
      }
    }
    return $result;
  }


  /**
   * Gets all relation vocabularies from configuration import storage.
   *
   * @param StorageInterface $storage
   *   The configuration storage to read from (typically sync storage).
   * @param string|null $type
   *   Optional vocabulary type to filter by:
   *   - 'string': vocabularies using string mirror fields
   *   - 'entity_reference': vocabularies using term reference mirror fields
   *   - NULL: all relation vocabularies
   *
   * @return array
   *   Array of configuration data arrays keyed by config name.
   *   Keys are like 'taxonomy.vocabulary.relation_types'.
   *   Each value is the full config data array with 'third_party_settings', etc.
   */
  public function getAllCimRelationVocabs(StorageInterface $storage, ?string $type = null): array {
    $all_vocabs = $this->getAllCimRelationBundles($storage, 'taxonomy_vocabulary') ?? [];
    if ($type === null) {
      return $all_vocabs;
    }
    $result = [];
    foreach ($all_vocabs as $config_name => $config_data) {
      if ($this->settingsManager->getCimProperty($config_data, 'referencing_type') === $type) {
        $result[$config_name] = $config_data;
      }
    }
    return $result;
  }


  /**
   * Gets node types linked to a vocabulary from configuration import storage.
   *
   * @param string $config_name
   *   The configuration name.
   * @param StorageInterface $storage
   *   The configuration storage.
   *
   * @return array
   *   Array of configuration data keyed by config name.
   */
  public function getCimNodeTypesLinkedToVocab(string $config_name, StorageInterface $storage): array {
    $entity_classes = $this->settingsManager->getConfigFileEntityClasses($config_name);
    if (empty($entity_classes['entity_type_id']) || $entity_classes['entity_type_id'] !== 'taxonomy_vocabulary') {
      return [];
    }

    $node_types = $this->getAllCimTypedRelationNodeTypes($storage);
    if (empty($node_types)) {
      return [];
    }

    $relation_type_field = $this->fieldNameResolver->getRelationTypeField() ?? null;

    if (!is_string($relation_type_field)) {
      return [];
    }
    $result = [];

    foreach ($node_types as $node_config_name => $node_config_data) {
      $node_classes = $this->settingsManager->getConfigFileEntityClasses($node_config_name);
      $field_prefix = $this->relationFieldManager->getFieldConfigNamePrefix(
        'node',
        $node_classes['bundle'],
        true
      );

      $field_config = $storage->read($field_prefix . $relation_type_field);

      if (!($field_config) || empty($field_config['settings']['handler_settings']['target_bundles'])) {
        continue;
      }
      $target_bundles = $field_config['settings']['handler_settings']['target_bundles'];
      $target_bundle = reset($target_bundles);
      if ($target_bundle === $entity_classes['bundle']) {
        $result[$node_config_name] = $node_config_data;
      }
    }
    return $result;
  }
}