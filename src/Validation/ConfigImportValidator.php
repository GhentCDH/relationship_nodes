<?php

namespace Drupal\relationship_nodes\Validation;

use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageInterface;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;

/**
 * Service for validating relationship nodes during configuration import.
 */
final class ConfigImportValidator {

  public function __construct(
    private readonly FieldNameResolver $fieldResolver,
    private readonly RelationshipFieldManager $fieldManager,
    private readonly BundleInfoService $bundleInfoService,
    private readonly BundleSettingsManager $settingsManager,
    private readonly ValidationObjectFactory $validationFactory,
    private readonly ValidationResultFormatter $formatter,
  ) {}

  // ========== Public API for Event Subscribers ==========

  /**
   * Validate and display bundle configuration import errors.
   */
  public function displayBundleCimValidationErrors(
    string $configName,
    ConfigImporterEvent $event,
    StorageInterface $storage
  ): void {
    $result = $this->validateBundleConfig($configName, $storage);
    $this->logErrors($result, $configName, $event);
  }

  /**
   * Validate and display field dependency errors.
   */
  public function displayCimFieldDependenciesValidationErrors(
    string $configName,
    ConfigImporterEvent $event,
    StorageInterface $storage
  ): void {
    $result = $this->validateFieldDependencyConfig($configName, $storage);
    $this->logErrors($result, $configName, $event);
  }

  // ========== Bundle Validation ==========

  /**
   * Validate a single bundle configuration import.
   */
  private function validateBundleConfig(string $configName, StorageInterface $storage): ValidationResult {
    $validator = $this->validationFactory->fromBundleConfigFile($configName, $storage);
    
    if (!$validator) {
      return ValidationResult::valid();
    }

    $bundleResult = $validator->validate();
    $fieldsResult = $this->validateBundleFields($configName, $storage);

    return $bundleResult->merge($fieldsResult);
  }

  /**
   * Validate all fields for a bundle import.
   */
  private function validateBundleFields(string $configName, StorageInterface $storage): ValidationResult {
    $fieldsStatus = $this->fieldManager->getCimFieldsStatus($configName, $storage);
    $existingFields = $fieldsStatus['existing'] ?? [];

    $results = [];
    foreach ($existingFields as $fieldName => $fieldInfo) {
      $context = [
        '@field' => $fieldName,
        '@bundle' => $this->getBundleFromConfig($configName) ?? '',
      ];

      if (!isset($fieldInfo['config_file_data'])) {
        $results[] = ValidationResult::fromErrorCode('missing_config_file_data', $context);
        continue;
      }

      // Validate field storage
      $storageConfig = $this->getFieldStorageForField($fieldInfo['config_file_data'], $storage);
      if (!$storageConfig) {
        $results[] = ValidationResult::fromErrorCode('no_field_storage', $context);
        continue;
      }

      $results[] = $this->validateFieldStorageConfig($storageConfig);
      $results[] = $this->validateFieldConfigImport($fieldInfo['config_file_data'], $storage);
    }

    return ValidationResult::mergeAll($results);
  }

  // ========== Field Validation ==========

  /**
   * Validate field storage configuration import.
   */
  private function validateFieldStorageConfig(array $configData): ValidationResult {
    $validator = $this->validationFactory->fromFieldStorageConfigFile($configData);
    return $validator->validate();
  }

  /**
   * Validate field configuration import.
   */
  private function validateFieldConfigImport(array $configData, StorageInterface $storage): ValidationResult {
    $validator = $this->validationFactory->fromFieldConfigConfigFile($configData, $storage);
    return $validator->validate();
  }

  // ========== Field Dependencies ==========

  /**
   * Validate field dependencies during deletion.
   */
  private function validateFieldDependencyConfig(string $configName, StorageInterface $storage): ValidationResult {
    // If config exists, it's not being deleted
    if (!empty($storage->read($configName))) {
      return ValidationResult::valid();
    }

    $fieldInfo = $this->fieldManager->getConfigFileFieldClasses($configName);
    
    if (!$fieldInfo) {
      return ValidationResult::fromErrorCode('no_field_config_file');
    }

    $fieldName = $fieldInfo['field_name'];

    // Field storage being deleted - check if field configs still exist
    if ($fieldInfo['field_entity_class'] === 'storage') {
      return $this->validateFieldStorageDeletion($configName, $storage, $fieldName);
    }

    // Field config being deleted - check bundle dependencies
    return $this->validateFieldConfigDeletion($fieldInfo, $storage);
  }

  /**
   * Validate field storage deletion.
   */
  private function validateFieldStorageDeletion(
    string $configName,
    StorageInterface $storage,
    string $fieldName
  ): ValidationResult {
    $dependentFields = $this->getFieldConfigsForStorage($configName, $storage);
    
    if (!empty($dependentFields)) {
      return ValidationResult::fromErrorCode('no_field_storage', [
        '@field' => $fieldName,
      ]);
    }

    return ValidationResult::valid();
  }

  /**
   * Validate field config deletion.
   */
  private function validateFieldConfigDeletion(array $fieldInfo, StorageInterface $storage): ValidationResult {
    $fieldName = $fieldInfo['field_name'];
    $entityTypeId = $fieldInfo['entity_type_id'];
    $bundle = $fieldInfo['bundle'];

    $dependentBundles = $this->getBundlesDependingOnField($fieldName, $entityTypeId, $storage);

    // Check if the bundle being deleted is a dependent
    foreach ($dependentBundles as $configName => $configData) {
      $bundleId = $this->getBundleFromConfig($configName);
      
      if ($bundleId === $bundle) {
        return ValidationResult::fromErrorCode('field_has_dependency', [
          '@field' => $fieldName,
          '@bundle' => $bundle,
        ]);
      }
    }

    return ValidationResult::valid();
  }

  /**
   * Get bundles that depend on a field.
   */
  private function getBundlesDependingOnField(
    string $fieldName,
    string $entityTypeId,
    StorageInterface $storage
  ): array {
    if (!in_array($entityTypeId, ['node_type', 'taxonomy_vocabulary'], true)) {
      return [];
    }

    // Check which bundles need this field
    if ($fieldName === $this->fieldResolver->getRelationTypeField()) {
      return $this->bundleInfoService->getAllCimTypedRelationNodeTypes($storage);
    }

    if (in_array($fieldName, $this->fieldResolver->getRelatedEntityFields(), true)) {
      return $this->bundleInfoService->getAllCimRelationBundles($storage, $entityTypeId);
    }

    if ($fieldName === $this->fieldResolver->getMirrorFields('string')) {
      return $this->bundleInfoService->getAllCimRelationVocabs($storage, 'string');
    }

    if ($fieldName === $this->fieldResolver->getMirrorFields('entity_reference')) {
      return $this->bundleInfoService->getAllCimRelationVocabs($storage, 'entity_reference');
    }

    return [];
  }

  // ========== Complete Import Validation ==========

  /**
   * Validate all relation configuration in import.
   */
  public function validateAllImportConfig(StorageInterface $storage): ValidationResult {
    return ValidationResult::mergeAll([
      $this->validateAllBundleImports($storage),
      $this->validateAllFieldImports($storage),
    ]);
  }

  /**
   * Validate all bundle imports.
   */
  private function validateAllBundleImports(StorageInterface $storage): ValidationResult {
    $results = [];
    
    foreach ($this->bundleInfoService->getAllCimRelationBundles($storage) as $configName => $configData) {
      $results[] = $this->validateBundleConfig($configName, $storage);
    }

    return ValidationResult::mergeAll($results);
  }

  /**
   * Validate all field imports.
   */
  private function validateAllFieldImports(StorageInterface $storage): ValidationResult {
    $results = [];
    $rnFields = $this->fieldManager->getAllCimRnCreatedFields($storage);
    $validFieldNames = $this->fieldResolver->getAllRelationFieldNames();

    foreach ($rnFields as $configName => $configData) {
      $fieldInfo = $this->fieldManager->getConfigFileFieldClasses($configName);
      
      if (empty($fieldInfo['field_entity_class'])) {
        $results[] = ValidationResult::fromErrorCode('missing_config_file_data', [
          '@field' => $configName,
        ]);
        continue;
      }

      $fieldName = $fieldInfo['field_name'];
      $fieldClass = $fieldInfo['field_entity_class'];

      // Validate field
      if ($fieldClass === 'storage') {
        $results[] = $this->validateFieldStorageConfig($configData);
      } elseif ($fieldClass === 'field') {
        $results[] = $this->validateFieldConfigImport($configData, $storage);
      }

      // Check for orphaned fields
      if (!in_array($fieldName, $validFieldNames, true)) {
        $context = ['@field' => $fieldName];
        
        if ($fieldClass === 'field') {
          $context['@bundle'] = $configData['bundle'];
        }

        $results[] = ValidationResult::fromErrorCode('orphaned_rn_field_settings', $context);
      }
    }

    return ValidationResult::mergeAll($results);
  }

  // ========== Helper Methods ==========

  /**
   * Get field storage config for a field config.
   */
  private function getFieldStorageForField(array $fieldConfigData, StorageInterface $storage): ?array {
    $dependencies = $fieldConfigData['dependencies']['config'] ?? [];
    
    foreach ($dependencies as $dependency) {
      if (str_starts_with($dependency, 'field.storage.')) {
        return $storage->read($dependency);
      }
    }

    return null;
  }

  /**
   * Get field configs that depend on a storage.
   */
  private function getFieldConfigsForStorage(string $storageConfigName, StorageInterface $storage): array {
    $dependentFields = [];
    
    foreach ($storage->listAll('field.field.') as $fieldConfigName) {
      $fieldData = $storage->read($fieldConfigName);
      $dependencies = $fieldData['dependencies']['config'] ?? [];
      
      if (in_array($storageConfigName, $dependencies, true)) {
        $dependentFields[$fieldConfigName] = $fieldData;
      }
    }

    return $dependentFields;
  }

  /**
   * Get bundle ID from config name.
   */
  private function getBundleFromConfig(string $configName): ?string {
    $entityClasses = $this->settingsManager->getConfigFileEntityClasses($configName);
    return $entityClasses['bundle'] ?? null;
  }

  /**
   * Log validation errors to config importer.
   */
  private function logErrors(ValidationResult $result, string $configName, ConfigImporterEvent $event): void {
    if ($result->isValid()) {
      return;
    }

    $message = $result->getFormattedErrors($this->formatter, $configName);
    $event->getConfigImporter()->logError($message);
  }
}