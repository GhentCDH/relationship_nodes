<?php

namespace Drupal\relationship_nodes\Validation;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for formatting validation error messages.
 *
 * Converts error codes to human-readable messages with context.
 */
class ValidationResultFormatter {

  use StringTranslationTrait;

  /**
   * Formats validation errors into a readable message.
   *
   * @param string $name
   *   The entity or configuration name.
   * @param array $errors
   *   Array of errors with error_code and context keys.
   *
   * @return string
   *   Formatted error message.
   */
  public function formatValidationErrors(string $name, array $errors): string {
    $message = "Validation errors for {$name}:\n";
    $error_strings = [];
    foreach ($errors as $error) {
      if (isset($error['error_code']) && isset($error['context'])) {
        $error_string = "- " . $this->errorCodeToMessage($error['error_code'], $error['context']) . "\n";
        if (in_array($error_string, $error_strings)) {
          continue;
        }
        $error_strings[] = $error_string;
        $message .= $error_string;
      }
    }
    return rtrim($message, "\n");
  }

  /**
   * Converts error code to translated message.
   *
   * @param string $error_code
   *   The error code.
   * @param array $context
   *   Context variables for message replacement.
   *
   * @return string
   *   The translated error message.
   */
  protected function errorCodeToMessage(string $error_code, array $context): string {
    // Literal strings, so they can be found for translation.
    return (string) match ($error_code) {
      'no_field_storage' => $this->t('The field "@field" does not have a valid field storage configuration.', $context),
      'invalid_field_type' => $this->t('The field "@field" has an invalid field type.', $context),
      'invalid_cardinality' => $this->t('The field "@field" has an invalid cardinality setting.', $context),
      'invalid_target_type' => $this->t('The field "@field" has an invalid target entity type.', $context),
      'invalid_mirror_type' => $this->t('The vocabulary "@bundle" has no valid mirror type. Only "none", "entity_reference", and "string" are supported.', $context),
      'invalid_entity_type' => $this->t('Invalid entity type in relation configuration. Only "node_type" and "taxonomy_vocabulary" are allowed.', $context),
      'missing_field_config' => $this->t('The field "@field" exists, but its field configuration cannot be found.', $context),
      'no_field_config_file' => $this->t('No field configuration file is available for evaluation.', $context),
      'field_has_dependency' => $this->t('The field "@field" cannot be removed because the bundle "@bundle" depends on it.', $context),
      'multiple_target_bundles' => $this->t('The field "@field" in bundle "@bundle" can only target a single bundle.', $context),
      'field_cannot_be_required' => $this->t('The field "@field" in bundle "@bundle" cannot be required due to Inline Entity Form widget constraints.', $context),
      'missing_config_file_data' => $this->t('The field "@field" has a configuration file, but its content cannot be read.', $context),
      'missing_field_name_config' => $this->t('The Relationship Node module is missing the required field name configuration (see config/install).', $context),
      'disabled_with_dependencies' => $this->t('The vocabulary "@bundle" is used as a relation type in a relationship node. Remove this dependency before disabling Relationship Nodes.', $context),
      'orphaned_rn_field_settings' => $this->t('The field "@field" has relation settings, but is not defined in the module configuration.', $context),
      'invalid_relation_vocabulary' => $this->t('The field "@field" in bundle "@bundle" targets an invalid relation vocabulary.', $context),
      'mirror_field_bundle_mismatch' => $this->t('The mirror field "@field" in bundle "@bundle" must reference the same vocabulary as the original.', $context),
      default => $error_code,
    };
  }

}
