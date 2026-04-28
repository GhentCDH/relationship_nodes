<?php

namespace Drupal\relationship_nodes\RelationBundle\Settings;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\NodeType;
use Drupal\relationship_nodes\RelationBundle\RelationBundleInfo;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Service for managing relationship bundle settings.
 *
 * Handles third-party settings for relationship nodes and vocabularies.
 */
class BundleSettingsManager {
  
  use StringTranslationTrait;

  protected EntityTypeManagerInterface $entityTypeManager;


  /**
   * Constructs a BundleSettingsManager object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }


  /**
   * Gets bundle info as value object.
   * 
   * 
   * @param ConfigEntityBundleBase|string $entity
   * 
   * @return RelationBundleInfo|null
   */
  public function getBundleInfo(ConfigEntityBundleBase|string $entity): ?RelationBundleInfo {
    if (is_string($entity)) {
      $entity = $this->ensureNodeType($entity) ?? $this->ensureVocab($entity);
    }
    
    if (!$entity instanceof ConfigEntityBundleBase) {
      return null;
    }
    
    $properties = $this->getProperties($entity) ?? [];
    return RelationBundleInfo::create($entity, $properties);
  }

  
  /**
   * Saves bundle info back to entity.
   * 
   * @param RelationBundleInfo $info
   */
  public function saveBundleInfo(RelationBundleInfo $info): void {
    $properties = $info->toArray();
    $this->setProperties($info->getBundle(), $properties);
  }


  /**
   * Gets a single property from a config entity.
   *
   * @param ConfigEntityBundleBase $entity
   *   The config entity.
   * @param string $property
   *   The property name.
   *
   * @return bool|string|null
   *   The property value or NULL.
   */
  public function getProperty(ConfigEntityBundleBase $entity, string $property): bool|string|null {
    if (!$this->isRelationProperty($property)) {
      return null;
    }
    return $entity->getThirdPartySetting('relationship_nodes', $property, null);
  }
  

  /**
   * Gets all properties from a config entity.
   *
   * @param ConfigEntityBundleBase $entity
   *   The config entity.
   *
   * @return array|null
   *   Array of properties or NULL.
   */
  public function getProperties(ConfigEntityBundleBase $entity): ?array {
    return $entity->getThirdPartySettings('relationship_nodes');
  }


  /**
   * Sets a single property on a config entity.
   *
   * @param ConfigEntityBundleBase $entity
   *   The config entity.
   * @param string $property
   *   The property name.
   * @param mixed $value
   *   The property value.
   */
  public function setProperty(ConfigEntityBundleBase $entity, string $property, mixed $value): void {
    if (!$this->isRelationProperty($property)) {
      return;
    }
    $entity->setThirdPartySetting('relationship_nodes', $property, $value);
    $entity->save();
  }


  /**
   * Sets multiple properties on a config entity.
   *
   * @param ConfigEntityBundleBase $entity
   *   The config entity.
   * @param array $properties
   *   Array of properties to set.
   */
  public function setProperties(ConfigEntityBundleBase $entity, array $properties): void {
    if (!empty($properties)) {
      foreach ($properties as $property => $value) {
        if (!$this->isRelationProperty($property)) {
          continue;
        }
        $entity->setThirdPartySetting('relationship_nodes', $property, $value);
      }
      $entity->save();
    } else {
      $rn_settings = $entity->getThirdPartySettings('relationship_nodes');
      foreach ($rn_settings as $rn_setting => $value) {
        $entity->unsetThirdPartySetting('relationship_nodes', $rn_setting);
      }
      $entity->save();
    }   
  }  


  /**
   * Ensures a node type entity is loaded.
   *
   * @param ConfigEntityBundleBase|string $node_type
   *   The node type entity or ID.
   *
   * @return NodeType|null
   *   The node type entity or NULL.
   */
  public function ensureNodeType(ConfigEntityBundleBase|string $node_type): ?NodeType { 
    if (is_string($node_type)) {
      $node_type = $this->entityTypeManager->getStorage('node_type')->load($node_type);
    }
    if (!$node_type instanceof NodeType) {
      return null;
    }
    return $node_type;
  }


  /**
   * Ensures a vocabulary entity is loaded.
   *
   * @param ConfigEntityBundleBase|string $vocab
   *   The vocabulary entity or ID.
   *
   * @return Vocabulary|null
   *   The vocabulary entity or NULL.
   */
  public function ensureVocab(ConfigEntityBundleBase|string $vocab): ?Vocabulary { 
    if (is_string($vocab)) {
      $vocab = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vocab);
    }
    if (!$vocab instanceof Vocabulary) {
      return null;
    }
    return $vocab;
  }

  
  /**
   * Checks if a property is a valid relation property.
   *
   * @param string $property
   *   The property name.
   *
   * @return bool
   *   TRUE if valid property, FALSE otherwise.
   */
  public function isRelationProperty(string $property): bool {
    $properties = ['rn_created', 'enabled', 'typed_relation', 'auto_title', 'referencing_type'];
    return in_array($property, $properties);
  }


  /**
   * Gets the entity type object class for an entity type.
   *
   * @param string|ConfigEntityBundleBase $entity_type
   *   The entity type ID or bundle entity.
   *
   * @return string|null
   *   The object class ('node' or 'taxonomy_term') or NULL.
   */
  public function getEntityTypeObjectClass(string|ConfigEntityBundleBase $entity_type): ?string {
    if ($entity_type instanceof ConfigEntityBundleBase) {
      $entity_type = $entity_type->getEntityTypeId();
    }   
    switch ($entity_type) {
      case 'node_type':
        return 'node';
      case 'taxonomy_vocabulary':
        return 'taxonomy_term';
      default:
        return null;
    }
  }


  /**
   * Gets the entity type class for an object class.
   *
   * @param string $object_class_name
   *   The object class name.
   *
   * @return string|null
   *   The entity type class or NULL.
   */
  public function getEntityTypeClass(string $object_class_name): ?string {
    switch ($object_class_name) {
      case 'node':
        return 'node_type';
      case 'taxonomy_term':
        return 'taxonomy_vocabulary';
      default:
        return null;
    }
  }


  /**
   * Gets entity classes from a configuration name.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return array|null
   *   Array containing bundle, entity_type_id, and object_class, or NULL.
   */
  public function getConfigFileEntityClasses(string $config_name): ?array {
    if (str_starts_with($config_name, 'node.type.')) {
      $bundle_name = substr($config_name, strlen('node.type.'));
      $entity_type_id = 'node_type';
    } elseif (str_starts_with($config_name, 'taxonomy.vocabulary.')) {
      $bundle_name = substr($config_name, strlen('taxonomy.vocabulary.'));
      $entity_type_id = 'taxonomy_vocabulary';
    } else {
      return null;
    }
    return [
      'bundle' => $bundle_name,
      'entity_type_id' => $entity_type_id,
      'object_class' => $this->getEntityTypeObjectClass($entity_type_id)
    ];
  }


  /**
   * Gets the configuration prefix for an entity type.
   *
   * @param string|ConfigEntityBundleBase $entity_type
   *   The entity type ID or bundle entity.
   *
   * @return string|null
   *   The configuration prefix or NULL.
   */
  public function getEntityTypeConfigPrefix(string|ConfigEntityBundleBase $entity_type): ?string {
    if ($entity_type instanceof ConfigEntityBundleBase) {
      $entity_type = $entity_type->getEntityTypeId();
    }   
    switch ($entity_type) {
      case 'node_type':
        return 'node.type.';
      case 'taxonomy_vocabulary':
        return  'taxonomy.vocabulary.';
      default:
        return null;
    }
  }


  /**
   * Gets a property from configuration import data.
   *
   * @param array $config_data
   *   The configuration data array.
   * @param string $property
   *   The property name.
   *
   * @return bool|string|null
   *   The property value or NULL.
   */
  public function getCimProperty(array $config_data, string $property): bool|string|null {
    if (!$this->isRelationProperty($property) || empty($this->getCimProperties($config_data))) {
      return null;
    }
    $properties = $this->getCimProperties($config_data);
    return isset($properties[$property]) ? $properties[$property] : null;
  }
  

  /**
   * Gets all properties from configuration import data.
   *
   * @param array $config_data
   *   The configuration data array.
   *
   * @return array|null
   *   Array of properties or NULL.
   */
  public function getCimProperties(array $config_data): ?array {
    return !empty($config_data['third_party_settings']['relationship_nodes'])
      ? $config_data['third_party_settings']['relationship_nodes']
      : null;
  }


  /**
   * Checks if configuration data represents a relation entity.
   *
   * @param array $config_data
   *   The configuration data array.
   *
   * @return bool
   *   TRUE if relation entity, FALSE otherwise.
   */
  public function isCimRelationEntity(array $config_data): bool {
    $value = $this->getCimProperty($config_data, 'enabled');
    return !empty($value);
  }


  /**
   * Checks if configuration data represents a typed relation node type.
   *
   * @param array $config_data
   *   The configuration data array.
   *
   * @return bool
   *   TRUE if typed relation node type, FALSE otherwise.
   */
  public function isCimTypedRelationNodeType(array $config_data) : bool {  
    if (!$this->isCimRelationEntity($config_data)) {
      return false;
    }

    $typed = $this->getCimProperty($config_data, 'typed_relation');

    return !empty($typed);
  }


  /**
   * Gets the relation vocabulary type from configuration data.
   *
   * @param array $config_data
   *   The configuration data array.
   *
   * @return string|null
   *   The vocabulary type or NULL.
   */
  public function getCimRelationVocabType(array $config_data): ?string {
    if (!$this->isCimRelationEntity($config_data) || !isset($config_data['referencing_type'])) {
      return null;
    }
    return $this->getCimProperty($config_data, 'referencing_type');
  }


  /**
   * Checks if configuration data represents a mirroring vocabulary.
   *
   * @param array $config_data
   *   The configuration data array.
   *
   * @return bool
   *   TRUE if mirroring vocabulary, FALSE otherwise.
   */
  public function isCimMirroringVocab(array $config_data): bool {
    $relation_vocab_type = $this->getCimRelationVocabType($config_data);
    return in_array($relation_vocab_type, ['string', 'entity_reference']);
  }
}