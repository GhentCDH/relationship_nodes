<?php

namespace Drupal\relationship_nodes\Validation\Field;

use Drupal\Core\Config\StorageInterface;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;
use Drupal\relationship_nodes\Validation\ValidationResult;

/**
 * Validator for field configuration.
 */
final class FieldConfigValidator {

  public function __construct(
    private readonly string $fieldName,
    private readonly string $bundle,
    private readonly bool $required,
    private readonly string $fieldType,
    private readonly ?array $targetBundles,
    private readonly ?StorageInterface $storage,
    private readonly FieldNameResolver $fieldResolver,
    private readonly RelationshipFieldManager $fieldManager,
    private readonly BundleSettingsManager $settingsManager,
  ) {}

  /**
   * Validates the field configuration.
   */
  public function validate(): ValidationResult {
    $requiredSettings = $this->fieldManager->getRequiredFieldConfiguration($this->fieldName);

    if (!$requiredSettings) {
      return ValidationResult::valid();
    }

    $validations = [
      $this->validateTargetBundles(),
      $this->validateFieldRequired(),
      $this->validateFieldType($requiredSettings),
      $this->validateSelfReferencingMirrorField(),
    ];

    if ($requiredSettings['type'] === 'entity_reference') {
      $validations[] = $this->validateRelationVocabTarget();
    }

    return ValidationResult::mergeAll($validations)
      ->withContext([
        '@field' => $this->fieldName,
        '@bundle' => $this->bundle,
      ]);
  }

  private function validateTargetBundles(): ValidationResult {
    if (empty($this->targetBundles) || count($this->targetBundles) === 1) {
      return ValidationResult::valid();
    }
    return ValidationResult::fromErrorCode('multiple_target_bundles');
  }

  private function validateFieldRequired(): ValidationResult {
    return $this->required
      ? ValidationResult::fromErrorCode('field_cannot_be_required')
      : ValidationResult::valid();
  }

  private function validateFieldType(array $required): ValidationResult {
    return $this->fieldType === $required['type']
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('invalid_field_type');
  }

  private function validateSelfReferencingMirrorField(): ValidationResult {
    $mirrorField = $this->fieldResolver->getMirrorFields('entity_reference');
    
    if ($this->fieldName !== $mirrorField) {
      return ValidationResult::valid();
    }

    if (empty($this->targetBundles) || key($this->targetBundles) === $this->bundle) {
      return ValidationResult::valid();
    }

    return ValidationResult::fromErrorCode('mirror_field_bundle_mismatch');
  }

  private function validateRelationVocabTarget(): ValidationResult {
    if ($this->fieldName !== $this->fieldResolver->getRelationTypeField()) {
      return ValidationResult::valid();
    }

    if (empty($this->targetBundles)) {
      return ValidationResult::valid();
    }

    foreach ($this->targetBundles as $vocabName => $vocabLabel) {
      if (!$this->isValidRelationVocab($vocabName)) {
        return ValidationResult::fromErrorCode('invalid_relation_vocabulary');
      }
    }

    return ValidationResult::valid();
  }

  private function isValidRelationVocab(string $vocabName): bool {
    // Runtime check
    if (empty($this->storage)) {
      $bundleInfo = $this->settingsManager->getBundleInfo($vocabName);
      return $bundleInfo && $bundleInfo->isRelation();
    }

    // Config import check
    $configData = $this->storage->read('taxonomy.vocabulary.' . $vocabName);
    return !empty($configData) && $this->settingsManager->isCimRelationEntity($configData);
  }
}