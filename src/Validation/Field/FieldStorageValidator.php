<?php

namespace Drupal\relationship_nodes\Validation\Field;

use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;
use Drupal\relationship_nodes\Validation\ValidationResult;

/**
 * Validator for field storage configuration.
 */
final class FieldStorageValidator {

  public function __construct(
    private readonly string $fieldName,
    private readonly string $fieldType,
    private readonly int $cardinality,
    private readonly ?string $targetType,
    private readonly RelationshipFieldManager $fieldManager,
  ) {}

  /**
   * Validates the field storage configuration.
   */
  public function validate(): ValidationResult {
    $requiredSettings = $this->fieldManager->getRequiredFieldConfiguration($this->fieldName);

    if (!$requiredSettings) {
      return ValidationResult::valid();
    }

    return ValidationResult::mergeAll([
      $this->validateFieldType($requiredSettings),
      $this->validateCardinality($requiredSettings),
      $this->validateTargetType($requiredSettings),
    ])->withContext(['@field' => $this->fieldName]);
  }

  /**
   * Validates the field type.
   *
   * @param array $required
   *   The required field settings.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateFieldType(array $required): ValidationResult {
    return $this->fieldType === $required['type']
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('invalid_field_type');
  }

  /**
   * Validates the field cardinality.
   *
   * @param array $required
   *   The required field settings.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateCardinality(array $required): ValidationResult {
    return $this->cardinality == $required['cardinality']
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('invalid_cardinality');
  }

  /**
   * Validates the target entity type of a reference field.
   *
   * @param array $required
   *   The required field settings.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateTargetType(array $required): ValidationResult {
    if (!isset($required['target_type'])) {
      return ValidationResult::valid();
    }

    return $this->targetType == $required['target_type']
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('invalid_target_type');
  }

}
