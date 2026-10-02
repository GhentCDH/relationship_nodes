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
    private readonly ?string $bundleId = NULL,
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

    // Add bundle context if provided.
    return $this->bundleId
      ? $result->withContext(['@bundle' => $this->bundleId])
      : $result;
  }

  /**
   * Checks whether the bundle is a node type or a vocabulary.
   *
   * @return bool
   *   TRUE for node types and vocabularies.
   */
  private function isRelevantEntityType(): bool {
    return in_array($this->entityTypeId, ['node_type', 'taxonomy_vocabulary'], TRUE);
  }

  /**
   * Checks that a vocabulary is not disabled while node types use it.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateDependencies(): ValidationResult {
    if ($this->entityTypeId === 'taxonomy_vocabulary' && !empty($this->dependentBundles)) {
      return ValidationResult::fromErrorCode('disabled_with_dependencies');
    }
    return ValidationResult::valid();
  }

  /**
   * Validates an enabled relation bundle.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateEnabledBundle(): ValidationResult {
    return ValidationResult::mergeAll([
      $this->validateFieldNameConfig(),
      $this->validateMirrorType(),
    ]);
  }

  /**
   * Validates the field name configuration for the bundle type.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateFieldNameConfig(): ValidationResult {
    if ($this->entityTypeId === 'node_type') {
      return $this->validateNodeTypeFields();
    }

    if ($this->entityTypeId === 'taxonomy_vocabulary') {
      return $this->validateVocabularyFields();
    }

    return ValidationResult::valid();
  }

  /**
   * Validates the field name configuration of a relation node type.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateNodeTypeFields(): ValidationResult {
    if (!$this->validBasicRelationConfig()) {
      return ValidationResult::fromErrorCode('missing_field_name_config');
    }

    if (!empty($this->rnSettings['typed_relation']) && !$this->validTypedRelationConfig()) {
      return ValidationResult::fromErrorCode('missing_field_name_config');
    }

    return ValidationResult::valid();
  }

  /**
   * Validates the field name configuration of a relation vocabulary.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateVocabularyFields(): ValidationResult {
    return $this->validRelationVocabConfig()
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('missing_field_name_config');
  }

  /**
   * Validates the mirror type of a relation vocabulary.
   *
   * @return \Drupal\relationship_nodes\Validation\ValidationResult
   *   The validation result.
   */
  private function validateMirrorType(): ValidationResult {
    if ($this->entityTypeId !== 'taxonomy_vocabulary') {
      return ValidationResult::valid();
    }

    $referencing = $this->rnSettings['referencing_type'] ?? NULL;

    if (empty($referencing)) {
      return ValidationResult::valid();
    }

    $validTypes = ['none', 'entity_reference', 'string'];

    return in_array($referencing, $validTypes, TRUE)
      ? ValidationResult::valid()
      : ValidationResult::fromErrorCode('invalid_mirror_type');
  }

  /**
   * Checks the configuration of the related entity field names.
   *
   * @return bool
   *   TRUE if all related entity field names are configured.
   */
  private function validBasicRelationConfig(): bool {
    return $this->validChildFieldConfig(
      $this->fieldResolver->getRelatedEntityFields(),
      'related_entity_fields'
    );
  }

  /**
   * Checks the configuration of the relation type field names.
   *
   * @return bool
   *   TRUE if the relation type and mirror field names are configured.
   */
  private function validTypedRelationConfig(): bool {
    return !empty($this->fieldResolver->getRelationTypeField())
      && $this->validRelationVocabConfig();
  }

  /**
   * Checks the configuration of the mirror field names.
   *
   * @return bool
   *   TRUE if all mirror field names are configured.
   */
  private function validRelationVocabConfig(): bool {
    return $this->validChildFieldConfig(
      $this->fieldResolver->getMirrorFields(),
      'mirror_fields'
    );
  }

  /**
   * Checks that all configured field names of a group are set.
   *
   * @param array $fields
   *   The field names, keyed by configuration key.
   * @param string $configKey
   *   The configuration key of the group.
   *
   * @return bool
   *   TRUE if every configured field name is set.
   */
  private function validChildFieldConfig(array $fields, string $configKey): bool {
    if (!is_array($fields)) {
      return FALSE;
    }

    $subfields = $this->fieldResolver->getConfig($configKey);

    if (empty($subfields) || !is_array($subfields)) {
      return FALSE;
    }

    foreach (array_keys($subfields) as $subfield) {
      if (!array_key_exists($subfield, $fields) || empty($fields[$subfield])) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
