<?php

namespace Drupal\relationship_nodes\RelationField;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigStorage;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;


/**
 * Service for configuring relationship node fields.
 *
 * Handles creation, validation, and management of relationship node fields.
 */
class RelationshipFieldManager {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FieldNameResolver $fieldNameResolver;
  protected BundleSettingsManager $settingsManager;

  
  /**
   * Constructs a FieldConfigurator object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FieldNameResolver $fieldNameResolver, 
    BundleSettingsManager $settingsManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fieldNameResolver = $fieldNameResolver;
    $this->settingsManager = $settingsManager;
  }


  /**
   * Gets required field configuration for a field name.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return array|null
   *   Array of field configuration or NULL.
   */
  public function getRequiredFieldConfiguration(string $field_name): ?array {
    if (in_array($field_name, $this->fieldNameResolver->getRelatedEntityFields())) {
      return [
        'type' => 'entity_reference',
        'target_type' => 'node',
        'cardinality' => 1,
      ];
    } elseif ($field_name === $this->fieldNameResolver->getRelationTypeField()) {
      return [
        'type' => 'entity_reference',
        'target_type' => 'taxonomy_term',
        'cardinality' => 1,
      ];
    } elseif ($field_name === $this->fieldNameResolver->getMirrorFields('string')) {
      return [
        'type' => 'string',
        'cardinality' => 1,
      ];
    } elseif ($field_name === $this->fieldNameResolver->getMirrorFields('entity_reference')) {
      return [
        'type' => 'entity_reference',
        'target_type' => 'taxonomy_term',
        'cardinality' => 1,
      ];
    }
    return null;
  }


  /**
   * Implements field updates for a bundle entity.
   *
   * @param ConfigEntityBundleBase $entity
   *   The bundle entity.
   *
   * @return array
   *   Array with keys 'checked', 'created', 'removed' containing field names.
   */
  public function implementFieldUpdates(ConfigEntityBundleBase $entity): array {
    $result = [];
    $fields_status = $this->getBundleFieldsStatus($entity); 
    $existing = $fields_status['existing'];      
    $missing = $fields_status['missing'];
    $remove = $fields_status['remove'];
    if (!empty($existing)) {
      $this->ensureFieldConfig($entity, $existing);
      $result['checked'] = $existing;
    }

    if (!empty($missing)) {
      $this->createFields($entity, $missing);
      $result['created'] = $missing;
    } 

    if (!empty($remove)) {
      $this->removeFields($entity, $remove);
      $result['removed'] = $remove;
    }

    return $result;
  }


  /**
   * Gets bundle field status.
   *
   * @param ConfigEntityBundleBase $entity
   *   The bundle entity.
   * @param array|null $rn_settings
   *   Optional relationship nodes settings.
   *
   * @return array|null
   *   Array with keys 'existing', 'missing', 'remove', or NULL.
   */
  public function getBundleFieldsStatus(ConfigEntityBundleBase $entity, ?array $rn_settings = null): ?array {
    $rn_settings = !empty($rn_settings) ? $rn_settings : $this->settingsManager->getProperties($entity);
    return $this->getFieldsStatus(
      $entity->getEntityTypeId(), 
      $entity->id(), 
      $rn_settings, 
      $this->entityTypeManager->getStorage('field_config')
    );
  }


  /**
   * Gets field status from configuration import.
   *
   * @param string $config_name
   *   The configuration name.
   * @param StorageInterface $storage
   *   The configuration storage.
   *
   * @return array|null
   *   Array with keys 'existing', 'missing', 'remove', or NULL.
   */
  public function getCimFieldsStatus(string $config_name, StorageInterface $storage): ?array {
    $config_data = $storage->read($config_name);
    $entity_classes = $this->settingsManager->getConfigFileEntityClasses($config_name);

    if (empty($config_data['third_party_settings']['relationship_nodes'])) {
      return null;
    } 
    $rn_settings = $config_data['third_party_settings']['relationship_nodes'];
    if (empty($rn_settings['enabled'])) {
      return null;
    }


    return $this->getFieldsStatus(
      $entity_classes['entity_type_id'],
      $entity_classes['bundle'],
      $rn_settings,
      $storage
    );
  }


  /**
   * Gets field status for an entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle_name
   *   The bundle name.
   * @param array $rn_settings
   *   Relationship nodes settings.
   * @param FieldConfigStorage|StorageInterface $storage
   *   The field storage.
   *
   * @return array|null
   *   Array with keys 'existing', 'missing', 'remove', or NULL.
   */
  public function getFieldsStatus(string $entity_type_id, string $bundle_name, array $rn_settings, FieldConfigStorage|StorageInterface $storage): ?array {
    $config_import = !($storage instanceof FieldConfigStorage);
    $config_prefix = $this->getFieldConfigNamePrefix($entity_type_id, $bundle_name, $config_import);
    if (empty($config_prefix)) {
      return null;
    }
    
    $existing = $missing = $remove = [];
    $required_fields = $this->getRequiredFields($entity_type_id, $rn_settings);

    foreach ($required_fields as $field_name => $settings) {
      $config = $config_import
        ? $storage->read($config_prefix . $field_name)
        : $storage->load($config_prefix . $field_name);
      if (!$config) {
        $missing[$field_name] = ['settings' => $settings];
      } else {
        $key = $config_import
          ? 'config_file_data'
          : 'field_config';
        $existing[$field_name] = [
          'settings' => $settings,
          $key => $config
        ];
      }

      if ($incompatible = $this->fieldNameResolver->getOppositeMirrorField($field_name)) {
          $field_to_remove = $config_import
              ? $storage->read($config_prefix . $incompatible)
              : $storage->load($config_prefix . $incompatible);
          if ($field_to_remove) $remove[] = $incompatible;
      }
    }
    return ['existing' => $existing, 'missing' => $missing, 'remove' => $remove];
  }


  /**
   * Gets required fields for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param array $rn_settings
   *   Relationship nodes settings.
   *
   * @return array
   *   Array of required field configurations keyed by field name.
   */
  public function getRequiredFields(string $entity_type_id, array $rn_settings): array {
    $fields = [];

    if(empty($rn_settings) || empty($rn_settings['enabled'])){
      return [];
    }

    if ($entity_type_id === 'node_type') {
      foreach ($this->fieldNameResolver->getRelatedEntityFields() as $field_name) {
        $config = $this->getRequiredFieldConfiguration($field_name);
        if ($config) {
          $fields[$field_name] = $config;
        }
      }
      if (!empty($rn_settings['typed_relation'])) {
        $field_name = $this->fieldNameResolver->getRelationTypeField();
        $config = $this->getRequiredFieldConfiguration($field_name);
        if ($config) {
          $fields[$field_name] = $config;
        }
      }
  } elseif ($entity_type_id === 'taxonomy_vocabulary') {
    if (!empty($rn_settings['referencing_type'])) {
        $type = $rn_settings['referencing_type'];
        if ($type !== 'none') {
          $field_name = $this->fieldNameResolver->getMirrorFields($type);
          if (is_string($field_name)) {
            $config = $this->getRequiredFieldConfiguration($field_name);
            if ($config) {
              $fields[$field_name] = $config;
            }
          }  
        }          
      }
    }
    return $fields;
  }


  /**
   * Checks if a field was created by relationship nodes module.
   *
   * @param FieldConfig|FieldStorageConfig $field
   *   The field entity.
   *
   * @return bool
   *   TRUE if created by module, FALSE otherwise.
   */
  public function isRnCreatedField(FieldConfig|FieldStorageConfig $field): bool {
    return (bool) $field->getThirdPartySetting('relationship_nodes', 'rn_created', FALSE);
  }


  /**
   * Checks if configuration data represents a module-created field.
   *
   * @param array $config_data
   *   The configuration data array.
   *
   * @return bool
   *   TRUE if created by module, FALSE otherwise.
   */
  public function isCimRnCreatedField(array $config_data): bool {
    $rn_created = $this->settingsManager->getCimProperty($config_data, 'rn_created');
    return !empty($rn_created);
  }


  /**
   * Gets all fields created by relationship nodes module.
   *
   * @param string|null $entity_type_id
   *   Optional entity type ID to filter by ('storage' or 'field').
   *
   * @return array
   *   Array of field entities keyed by field ID.
   */
  public function getAllRnCreatedFields(?string $entity_type_id = null): array {
    $entity_types = ['storage' => 'field_storage_config', 'field' => 'field_config'];
    if ($entity_type_id !== null && !in_array($entity_type_id, array_keys($entity_types))) {
      return [];
    }

    $input = $entity_type_id !== null 
      ? [$entity_type_id => $entity_types[$entity_type_id]] 
      : $entity_types;

    $result = []; 
    foreach ($input as $entity_type) {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      if (!$storage instanceof EntityStorageInterface) {
        continue;
      }
      $all = $storage->loadMultiple();
      foreach ($all as $type) {
        if ($type instanceof ConfigEntityBase && $this->isRnCreatedField($type)) {
          $result[$type->id()] = $type;
        } 
      }    
    }
    return $result;
  }


  /**
   * Gets all module-created fields from configuration import.
   *
   * @param StorageInterface $storage
   *   The configuration storage.
   * @param string|null $entity_type_id
   *   Optional entity type ID to filter by.
   *
   * @return array
   *   Array of configuration data keyed by config name.
   */
  public function getAllCimRnCreatedFields(StorageInterface $storage, ?string $entity_type_id = null): array {
    $entity_types = ['storage', 'field'];
    if ($entity_type_id !== null && !in_array($entity_type_id, $entity_types)) {
      return [];
    }

    $input = $entity_type_id !== null ? [$entity_type_id] : $entity_types;

    $result = []; 
    foreach ($input as $entity_type) {
      $all_fields = $storage->listAll('field.' . $entity_type . '.');
      foreach ($all_fields as $field_name) {
        $config_data = $storage->read($field_name);
        if ($config_data && $this->isCimRnCreatedField($config_data)) {
          $result[$field_name] = $config_data;
        }
      }
    }
    return $result;
  }


  /**
   * Creates fields for a bundle entity.
   *
   * @param ConfigEntityBundleBase $entity
   *   The bundle entity.
   * @param array $missing_fields
   *   Array of missing field configurations.
   */
  protected function createFields(ConfigEntityBundleBase $entity, array $missing_fields): void {
    $field_storage_config_storage = $this->entityTypeManager->getStorage('field_storage_config');
    $field_config_storage = $this->entityTypeManager->getStorage('field_config');

    $entity_type_id = $this->settingsManager->getEntityTypeObjectClass($entity);

    foreach ($missing_fields as $field_name => $field_arr) {
      $settings = $field_arr['settings'];
      $field_storage = $field_storage_config_storage->load("$entity_type_id.$field_name");
      if (!$field_storage) {
        $field_storage = $field_storage_config_storage->create([
          'field_name' => $field_name,
          'entity_type' => $entity_type_id,
          'type' => $settings['type'],
          'cardinality' => $settings['cardinality'],
          'settings' => isset($settings['target_type']) ? ['target_type' => $settings['target_type']] : [],
          'third_party_settings' => ['relationship_nodes' => ['rn_created'=> true]],
        ]);
        $field_storage->setLocked(true);
        $field_storage->save();
      }

      $field_config = $field_config_storage->load("$entity_type_id.{$entity->id()}.$field_name");
      if (!$field_config) {
          
        $self_target_settings = [];
        if ($field_name == $this->fieldNameResolver->getMirrorFields('entity_reference')) {
          $self_target_settings = [
            'handler' => 'default:taxonomy_term',
            'handler_settings' => [
              'target_bundles' => [$entity->id() => $entity->id()],
            ],
          ];
        }

        $field_config = $field_config_storage->create([
          'field_name' => $field_name,
          'bundle' => $entity->id(),
          'entity_type' => $entity_type_id,
          'label' => ucfirst(str_replace('_', ' ', $field_name)),
          'required' => false,
          'settings' =>  $self_target_settings,
          'third_party_settings' => ['relationship_nodes' => ['rn_created'=> true]],
        ]);
        $field_config->save();
      }
    }
  }


  /**
   * Removes fields from a bundle entity.
   *
   * @param ConfigEntityBundleBase $entity
   *   The bundle entity.
   * @param array $fields_to_remove
   *   Array of field names to remove.
   */
  protected function removeFields(ConfigEntityBundleBase $entity, array $fields_to_remove): void {
    $storage = $this->entityTypeManager->getStorage('field_config');
    $entity_type_id = $this->settingsManager->getEntityTypeObjectClass($entity);

    foreach ($fields_to_remove as $field_name) {
      $field_config = $storage->load("$entity_type_id.{$entity->id()}.$field_name");
      if ($field_config) $field_config->delete();
    }
  }


  /**
   * Ensures field configuration is properly set.
   *
   * @param ConfigEntityBundleBase $entity
   *   The bundle entity.
   * @param array $existing_fields
   *   Array of existing field data.
   */
  protected function ensureFieldConfig(ConfigEntityBundleBase $entity, array $existing_fields): void {
    foreach ($existing_fields as $field_arr) {
      $field_config = $field_arr['field_config'];
      $field_storage = $field_config->getFieldStorageDefinition();
      if (!$field_storage->isLocked()) {
        $field_storage->setLocked(true)->save();
      }
      if (!$field_storage->getThirdPartySetting('relationship_nodes', 'rn_created', false)) {
        $field_storage->setThirdPartySetting('relationship_nodes', 'rn_created', true)->save();
      }
      if (!$field_config->getThirdPartySetting('relationship_nodes', 'rn_created', false)) {
        $field_config->setThirdPartySetting('relationship_nodes', 'rn_created', true)->save();
      }
    }
  }


  /**
   * Gets the field configuration name prefix.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle_name
   *   The bundle name.
   * @param bool $config_import
   *   Whether this is for config import.
   *
   * @return string|null
   *   The prefix string or NULL.
   */
  public function getFieldConfigNamePrefix(string $entity_type_id, string $bundle_name, bool $config_import=false): ?string {
    $object_type = $this->settingsManager->getEntityTypeObjectClass($entity_type_id);
    if (!$object_type) {
      return null;
    }
    if ($config_import) {         
      return 'field.field.' . $object_type . '.' . $bundle_name . '.';
    } else {
      return $object_type . '.' . $bundle_name . '.';
    }
  }

  
  /**
   * Gets entity classes from a field configuration name.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return array|null
   *   Array containing field_entity_class, entity_type_id, bundle, field_name, or NULL.
   */
  public function getConfigFileFieldClasses(string $config_name): ?array {
    $parts = explode('.', $config_name);
    if ($parts[0] !== 'field' || !in_array($parts[1], ['field', 'storage']) || !in_array($parts[2], ['node', 'taxonomy_term'])) {
      return null;
    }
    if ($parts[1] === 'field') {
      return [
        'field_entity_class' => 'field',
        'entity_type_id' => $this->settingsManager->getEntityTypeClass($parts[2]),
        'bundle' => $parts[3],
        'field_name' => $parts[4],
      ];
    } elseif ($parts[1] === 'storage') {
      return [
        'field_entity_class' => 'storage',
        'entity_type_id' => $this->settingsManager->getEntityTypeClass($parts[2]),
        'bundle' => null,
        'field_name' => $parts[3],
      ];
    } else {
      return null;
    }
  }
}