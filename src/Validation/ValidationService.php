<?php

namespace Drupal\relationship_nodes\Validation;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;

/**
 * Service for validating relationship nodes configuration.
 */
final class ValidationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FieldNameResolver $fieldResolver,
    private readonly RelationshipFieldManager $fieldManager,
    private readonly BundleInfoService $bundleInfoService,
    private readonly BundleSettingsManager $settingsManager,
    private readonly ValidationObjectFactory $validationFactory,
    private readonly ValidationResultFormatter $formatter,
  ) {}

  // ========== Form Validation ==========

  /**
   * Validate form state and display errors.
   */
  public function displayFormStateValidationErrors(array &$form, FormStateInterface $formState): void {
    $result = $this->validateFormStateBundle($formState);
    
    if (!$result->isValid()) {
      $entity = $formState->getFormObject()->getEntity();
      $message = $result->getFormattedErrors($this->formatter, $entity->id());
      $formState->setErrorByName('relationship_nodes', $message);
    }
  }

  /**
   * Validate bundle configuration from form state.
   */
  private function validateFormStateBundle(FormStateInterface $formState): ValidationResult {
    $validator = $this->validationFactory->fromFormState($formState);
    
    if (!$validator) {
      return ValidationResult::valid();
    }

    $bundleResult = $validator->validate();
    $fieldsResult = $this->validateFormStateFields($formState);

    return $bundleResult->merge($fieldsResult);
  }

  /**
   * Validate existing fields from form state.
   */
  private function validateFormStateFields(FormStateInterface $formState): ValidationResult {
    $entity = $formState->getFormObject()->getEntity();
    
    if (!$entity instanceof ConfigEntityBundleBase) {
      return ValidationResult::valid();
    }

    $rnSettings = $formState->getValue('relationship_nodes');
    return $this->validateEntityFields($entity, $rnSettings);
  }

  // ========== Entity Validation ==========

  /**
   * Validate a bundle entity and its fields.
   */
  public function validateBundleEntity(ConfigEntityBundleBase $entity): ValidationResult {
    $validator = $this->validationFactory->fromEntity($entity);
    $bundleResult = $validator->validate();
    $fieldsResult = $this->validateEntityFields($entity);

    return $bundleResult->merge($fieldsResult);
  }

  /**
   * Validate all fields belonging to a bundle entity.
   */
  private function validateEntityFields(
    ConfigEntityBundleBase $entity,
    ?array $rnSettings = null
  ): ValidationResult {
    $fieldsStatus = $this->fieldManager->getBundleFieldsStatus($entity, $rnSettings);
    $existingFields = $fieldsStatus['existing'] ?? [];

    $results = [];
    foreach ($existingFields as $fieldName => $fieldInfo) {
      if (!isset($fieldInfo['field_config'])) {
        $results[] = ValidationResult::fromErrorCode('missing_field_config', [
          '@field' => $fieldName,
          '@bundle' => $entity->id(),
        ]);
        continue;
      }

      $results[] = $this->validateFieldConfig($fieldInfo['field_config']);
    }

    return ValidationResult::mergeAll($results);
  }

  // ========== Field Validation ==========

  /**
   * Validate field storage configuration.
   */
  public function validateFieldStorage(FieldStorageConfig $storage): ValidationResult {
    $validator = $this->validationFactory->fromFieldStorage($storage);
    return $validator->validate();
  }

  /**
   * Validate field configuration (with optional storage validation).
   */
  public function validateFieldConfig(
    FieldConfig $fieldConfig,
    bool $includeStorage = true
  ): ValidationResult {
    $results = [];

    // Validate storage if requested
    if ($includeStorage) {
      $storage = $fieldConfig->getFieldStorageDefinition();
      
      if (!$storage instanceof FieldStorageConfig) {
        return ValidationResult::fromErrorCode('no_field_storage', [
          '@field' => $fieldConfig->getName(),
          '@bundle' => $fieldConfig->getTargetBundle(),
        ]);
      }

      $results[] = $this->validateFieldStorage($storage);
    }

    // Validate field config
    $validator = $this->validationFactory->fromFieldConfig($fieldConfig);
    $results[] = $validator->validate();

    return ValidationResult::mergeAll($results);
  }

  // ========== Complete Validation ==========

  /**
   * Validate all relation bundles and fields in the system.
   */
  public function validateAllRelationConfig(): ValidationResult {
    return ValidationResult::mergeAll([
      $this->validateAllBundles(),
      $this->validateAllFields(),
    ]);
  }

  /**
   * Validate all relation bundles.
   */
  private function validateAllBundles(): ValidationResult {
    $results = [];
    
    foreach ($this->bundleInfoService->getAllRelationBundles() as $bundleName => $entity) {
      $results[] = $this->validateBundleEntity($entity);
    }

    return ValidationResult::mergeAll($results);
  }

  /**
   * Validate all relation fields.
   */
  private function validateAllFields(): ValidationResult {
    $results = [];
    $rnFields = $this->fieldManager->getAllRnCreatedFields();
    $validFieldNames = $this->fieldResolver->getAllRelationFieldNames();

    foreach ($rnFields as $fieldId => $field) {
      $fieldName = $field->getName();
      
      // Validate the field itself
      if ($field instanceof FieldStorageConfig) {
        $results[] = $this->validateFieldStorage($field);
      } elseif ($field instanceof FieldConfig) {
        $results[] = $this->validateFieldConfig($field);
      }

      // Check for orphaned fields
      if (!in_array($fieldName, $validFieldNames, true)) {
        $context = ['@field' => $fieldName];
        
        if ($field instanceof FieldConfig) {
          $context['@bundle'] = $field->getTargetBundle();
        }

        $results[] = ValidationResult::fromErrorCode('orphaned_rn_field_settings', $context);
      }
    }

    return ValidationResult::mergeAll($results);
  }
}