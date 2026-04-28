<?php

namespace Drupal\relationship_nodes_search\QueryHelper;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for filter operator definitions and validation.
 * 
 * Provides operator options for Search API query conditions
 * and validates operator values.
 */
class FilterOperatorHelper {

  use StringTranslationTrait;

  /**
   * Get all available operator options.
   *
   * @return array
   *   All operator options keyed by operator value.
   */
  public function getOperatorOptions(): array {
    return [
      '=' => $this->t('Is equal to'),
      '!=' => $this->t('Is not equal to'),
      '<' => $this->t('Is less than'),
      '<=' => $this->t('Is less than or equal to'),
      '>' => $this->t('Is greater than'),
      '>=' => $this->t('Is greater than or equal to'),
      'IN' => $this->t('Is one of'),
      'NOT IN' => $this->t('Is not one of'),
      'BETWEEN' => $this->t('Is between'),
      'NOT BETWEEN' => $this->t('Is not between'),
      '<>' => $this->t('Contains'),
    ];
  }


  /**
   * Get operator options for text/string fields.
   *
   * @return array
   *   Text field operator options.
   */
  public function getTextOperatorOptions(): array {
    return [
      '=' => $this->t('Is equal to'),
      '!=' => $this->t('Is not equal to'),
      'IN' => $this->t('Is one of'),
      'NOT IN' => $this->t('Is not one of'),
      '<>' => $this->t('Contains'),
    ];
  }


  /**
   * Get operator options for range-capable fields (numeric, date).
   *
   * @return array
   *   Range operator options.
   */
  public function getRangeOperatorOptions(): array {
    return [
      '=' => $this->t('Is equal to'),
      '!=' => $this->t('Is not equal to'),
      '<' => $this->t('Is less than'),
      '<=' => $this->t('Is less than or equal to'),
      '>' => $this->t('Is greater than'),
      '>=' => $this->t('Is greater than or equal to'),
      'IN' => $this->t('Is one of'),
      'NOT IN' => $this->t('Is not one of'),
      'BETWEEN' => $this->t('Is between'),
      'NOT BETWEEN' => $this->t('Is not between'),
    ];
  }


  /**
   * Get operator options based on field capabilities.
   *
   * @param bool $supports_range
   *   Whether the field supports range operators.
   *
   * @return array
   *   Appropriate operator options for the field.
   */
  public function getOperatorOptionsForField(bool $supports_range): array {
    return $supports_range 
      ? $this->getRangeOperatorOptions() 
      : $this->getTextOperatorOptions();
  }


  /**
   * Check if an operator is valid.
   *
   * @param string $operator
   *   The operator to validate.
   *
   * @return bool
   *   TRUE if valid.
   */
  public function isValidOperator(string $operator): bool {
    return array_key_exists($operator, $this->getOperatorOptions());
  }


  /**
   * Check if an operator requires multiple values (BETWEEN, IN, etc.).
   *
   * @param string $operator
   *   The operator to check.
   *
   * @return bool
   *   TRUE if operator needs multiple values.
   */
  public function isMultiValueOperator(string $operator): bool {
    return in_array($operator, ['BETWEEN', 'NOT BETWEEN', 'IN', 'NOT IN']);
  }


  /**
   * Check if an operator is a range operator (BETWEEN, NOT BETWEEN).
   *
   * @param string $operator
   *   The operator to check.
   *
   * @return bool
   *   TRUE if operator is a range operator.
   */
  public function isRangeOperator(string $operator): bool {
    return in_array($operator, ['BETWEEN', 'NOT BETWEEN']);
  }


  /**
   * Get the default operator.
   *
   * @return string
   *   The default operator value.
   */
  public function getDefaultOperator(): string {
    return '=';
  }


  /**
   * Determines the operator for a field condition from config and form values.
   *
   * Checks if the operator should come from exposed form input or from
   * the field configuration, then sanitizes it.
   *
   * @param array $field_config
   *   The field configuration array.
   * @param string $child_filter_id
   *   The child field filter id.
   * @param array $form_values
   *   The form values array (typically $this->value from the filter).
   *
   * @return string
   *   The sanitized operator.
   */
  public function determineFieldOperator(array $field_config, string $child_filter_id, array $form_values): string {
    // First check exposed form value
    if (!empty($field_config['expose_field_operator']) && isset($form_values[$child_filter_id]['operator'])) {
      return $this->sanitizeOperator($form_values[$child_filter_id]['operator']);
    }
    
    // Fall back to configured operator
    if (!empty($field_config['field_operator'])) {
      return $this->sanitizeOperator($field_config['field_operator']);
    }
    
    // Return default if no operator configured
    return $this->getDefaultOperator();
  }


  /**
   * Validate and sanitize an operator value.
   * 
   * Returns the operator if valid, otherwise returns the default operator.
   *
   * @param string|null $operator
   *   The operator to validate.
   *
   * @return string
   *   The validated operator or default.
   */
  public function sanitizeOperator(?string $operator): string {
    if (empty($operator)) {
      return $this->getDefaultOperator();
    }
    
    return $this->isValidOperator($operator) ? $operator : $this->getDefaultOperator();
  }
}