<?php

namespace Drupal\relationship_nodes\Validation;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;
use Drupal\relationship_nodes\Validation\Bundle\BundleValidator;
use Drupal\relationship_nodes\Validation\Field\FieldConfigValidator;
use Drupal\relationship_nodes\Validation\Field\FieldStorageValidator;

/**
 * Factory for creating validation objects.
 */
final class ValidationObjectFactory {

  public function __construct(
    private readonly FieldNameResolver $fieldResolver,
    private readonly RelationshipFieldManager $fieldManager,
    private readonly BundleSettingsManager $settingsManager,
    private readonly BundleInfoService $bundleInfoService,
  ) {}

  // ========== Bundle Validators ==========

  /**
   * Creates validation object from a bundle entity.
   */
  public function fromEntity(ConfigEntityBundleBase $entity): BundleValidator {
    return new BundleValidator(
      $entity->getEntityTypeId(),
      $this->settingsManager->getProperties($entity),
      $this->bundleInfoService->getNodeTypesLinkedToVocab($entity),
      $this->fieldResolver,
      $entity->id()
    );
  }

  /**
   * Creates validation object from form state.
   */
  public function fromFormState(FormStateInterface $formState): ?BundleValidator {
    $entity = $formState->getFormObject()->getEntity();
    
    if (!$entity instanceof ConfigEntityBundleBase) {
      return null;
    }

    return new BundleValidator(
      $entity->getEntityTypeId(),
      $formState->getValue('relationship_nodes'),
      $this->bundleInfoService->getNodeTypesLinkedToVocab($entity),
      $this->fieldResolver,
      $entity->id()
    );
  }

  /**
   * Creates validation object from bundle configuration file.
   */
  public function fromBundleConfigFile(string $configName, StorageInterface $storage): ?BundleValidator {
    $configData = $storage->read($configName);
    $entityClasses = $this->settingsManager->getConfigFileEntityClasses($configName);
    
    if (empty($entityClasses)) {
      return null;
    }

    $rnSettings = $configData['third_party_settings']['relationship_nodes'] ?? [];

    return new BundleValidator(
      $entityClasses['entity_type_id'],
      $rnSettings,
      $this->bundleInfoService->getCimNodeTypesLinkedToVocab($configName, $storage),
      $this->fieldResolver,
      $entityClasses['bundle'] ?? null
    );
  }

  // ========== Field Config Validators ==========

  /**
   * Creates validation object from field configuration.
   */
  public function fromFieldConfig(FieldConfig $fieldConfig): FieldConfigValidator {
    return new FieldConfigValidator(
      $fieldConfig->getName(),
      $fieldConfig->getTargetBundle(),
      $fieldConfig->isRequired(),
      $fieldConfig->getType(),
      $fieldConfig->getSetting('handler_settings')['target_bundles'] ?? null,
      null,
      $this->fieldResolver,
      $this->fieldManager,
      $this->settingsManager
    );
  }

  /**
   * Creates validation object from field configuration file.
   */
  public function fromFieldConfigConfigFile(array $configData, StorageInterface $storage): FieldConfigValidator {
    return new FieldConfigValidator(
      $configData['field_name'],
      $configData['bundle'],
      $configData['required'],
      $configData['field_type'],
      $configData['settings']['handler_settings']['target_bundles'] ?? null,
      $storage,
      $this->fieldResolver,
      $this->fieldManager,
      $this->settingsManager
    );
  }

  // ========== Field Storage Validators ==========

  /**
   * Creates validation object from field storage.
   */
  public function fromFieldStorage(FieldStorageConfig $storage): FieldStorageValidator {
    return new FieldStorageValidator(
      $storage->getName(),
      $storage->getType(),
      $storage->getCardinality(),
      $storage->getSetting('target_type') ?? null,
      $this->fieldManager
    );
  }

  /**
   * Creates validation object from field storage configuration file.
   */
  public function fromFieldStorageConfigFile(array $configData): FieldStorageValidator {
    return new FieldStorageValidator(
      $configData['field_name'],
      $configData['type'],
      $configData['cardinality'],
      $configData['settings']['target_type'] ?? null,
      $this->fieldManager
    );
  }
}