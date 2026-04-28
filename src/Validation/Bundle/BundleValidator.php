<?php

namespace Drupal\relationship_nodes\Validation\Bundle;

use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\Validation\ValidationResult;

/**
 * Validator for bundle configuration.
 */
final class BundleValidator {

  public function __construct(
    private readonly ?string $entityTypeId,
    private readonly array $rnSettings,
    private readonly array $dependentBundles,
    private readonly FieldNameResolver $fieldResolver,
    private readonly ?string $bundleId = null,
  ) {}

  /**
   * Validates the bundle configuration.
   */
  public function validate(): ValidationResult {
    if (!$this->isRelevantEntityType()) {
      return ValidationResult::valid();
    }

    $result = empty($this->rnSettings['enabled'])
      ? $this->validateDependencies()
      : $this->validateEnabledBundle();

    // Add bundle context if provided
    return $this->bundleId
      ? $result->withContext(['@bundle' => $this->bundleId])
      : $result;
  }

  private function isRelevantEntityType(): bool {
    return in_array($this->entityTypeId, ['node_type', 'taxonomy_vocabulary'], true);
  }

  private function validateDependencies(): ValidationResult {
    if ($this->entityTypeId === 'taxonomy_vocabulary' && !empty($this->dependentBundles)) {
      return ValidationResult::fromErrorCode('disabled_with_dependencies');
    }
    return ValidationResult::valid();
  }

  private function validateEnabledBundle(): ValidationResult {
    return ValidationResult::mergeAll([
      $this->validateFieldNameConfig(),
      $this->validateMirrorType(),
    ]);
  }

  private function validateFieldNameConfig(): ValidationResult {
    if ($this->entityTypeId === 'node_type') {
      return $this->validateNodeTypeFields();
    }
    
    if ($this->entityTypeId === 'taxonomy_vocabulary') {
      return $this->validateVocabularyFields();
    }

    return ValidationResult::valid();
  }

  private function validateNodeTypeFields(): ValidationResult {
    if (!$this->validBasicRelationConfig()) {
      return ValidationResult::fromErrorCode('missing_field_name_config');
    }

    if (!empty($this->rnSettings['typed_relation']) && !$this->validTypedRelationConfig()) {
      return ValidationResult::fromErrorCode('missing_field_name_config');
    }

    return ValidationResult::valid();
  }

  private function validateVocabularyFields(): ValidationResult {
    return $this->validRelationVocabConfig()
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('missing_field_name_config');
  }

  private function validateMirrorType(): ValidationResult {
    if ($this->entityTypeId !== 'taxonomy_vocabulary') {
      return ValidationResult::valid();
    }

    $referencing = $this->rnSettings['referencing_type'] ?? null;
    
    if (empty($referencing)) {
      return ValidationResult::valid();
    }

    $validTypes = ['none', 'entity_reference', 'string'];
    
    return in_array($referencing, $validTypes, true)
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('invalid_mirror_type');
  }

  private function validBasicRelationConfig(): bool {
    return $this->validChildFieldConfig(
      $this->fieldResolver->getRelatedEntityFields(),
      'related_entity_fields'
    );
  }

  private function validTypedRelationConfig(): bool {
    return !empty($this->fieldResolver->getRelationTypeField())
      && $this->validRelationVocabConfig();
  }

  private function validRelationVocabConfig(): bool {
    return $this->validChildFieldConfig(
      $this->fieldResolver->getMirrorFields(),
      'mirror_fields'
    );
  }

  private function validChildFieldConfig(array $fields, string $configKey): bool {
    if (!is_array($fields)) {
      return false;
    }

    $subfields = $this->fieldResolver->getConfig($configKey);
    
    if (empty($subfields) || !is_array($subfields)) {
      return false;
    }

    foreach (array_keys($subfields) as $subfield) {
      if (!array_key_exists($subfield, $fields) || empty($fields[$subfield])) {
        return false;
      }
    }

    return true;
  }
}