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


  private const ERROR_MESSAGES = [
    'no_field_storage' => 'The field "@field" does not have a valid field storage configuration.',
    'invalid_field_type' => 'The field "@field" has an invalid field type.',
    'invalid_cardinality' => 'The field "@field" has an invalid cardinality setting.',
    'invalid_target_type' => 'The field "@field" has an invalid target entity type.',
    'invalid_mirror_type' => 'The vocabulary "@bundle" has no valid mirror type. Only "none", "entity_reference", and "string" are supported.',
    'invalid_entity_type' => 'Invalid entity type in relation configuration. Only "node_type" and "taxonomy_vocabulary" are allowed.',
    'missing_field_config' => 'The field "@field" exists, but its field configuration cannot be found.',
    'no_field_config_file' => 'No field configuration file is available for evaluation.',
    'field_has_dependency' => 'The field "@field" cannot be removed because the bundle "@bundle" depends on it.',
    'multiple_target_bundles' => 'The field "@field" in bundle "@bundle" can only target a single bundle.',
    'field_cannot_be_required' => 'The field "@field" in bundle "@bundle" cannot be required due to Inline Entity Form widget constraints.',
    'missing_config_file_data' => 'The field "@field" has a configuration file, but its content cannot be read.',
    'missing_field_name_config' => 'The Relationship Node module is missing the required field name configuration (see config/install).',
    'disabled_with_dependencies' => 'The vocabulary "@bundle" is used as a relation type in a relationship node. Remove this dependency before disabling Relationship Nodes.',
    'orphaned_rn_field_settings' => 'The field "@field" has relation settings, but is not defined in the module configuration.',
    'invalid_relation_vocabulary' => 'The field "@field" in bundle "@bundle" targets an invalid relation vocabulary.',
    'mirror_field_bundle_mismatch' => 'The mirror field "@field" in bundle "@bundle" must reference the same vocabulary as the original.'
  ];


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
    $error_message = self::ERROR_MESSAGES[$error_code] ?? $error_code;
    return $this->t($error_message, $context);
  }  
}