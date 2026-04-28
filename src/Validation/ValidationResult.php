<?php

namespace Drupal\relationship_nodes\Validation;

/**
 * Immutable validation result.
 */
final class ValidationResult {

  private function __construct(
    private readonly array $errors,
  ) {}

  /**
   * Create valid result (no errors).
   */
  public static function valid(): self {
    return new self([]);
  }

  /**
   * Create invalid result from error codes.
   */
  public static function invalid(array $errors): self {
    return new self($errors);
  }

  /**
   * Create result from single error code.
   */
  public static function fromErrorCode(string $errorCode, array $context = []): self {
    return new self([[
      'error_code' => $errorCode,
      'context' => $context,
    ]]);
  }

  /**
   * Check if validation passed.
   */
  public function isValid(): bool {
    return empty($this->errors);
  }

  /**
   * Get all errors.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Merge two results.
   */
  public function merge(ValidationResult $other): self {
    if ($this->isValid() && $other->isValid()) {
      return self::valid();
    }
    
    return new self(array_merge($this->errors, $other->errors));
  }

  /**
   * Merge multiple results.
   */
  public static function mergeAll(array $results): self {
    $allErrors = [];
    foreach ($results as $result) {
      if (!$result instanceof self) {
        continue;
      }
      $allErrors = array_merge($allErrors, $result->errors);
    }
    
    return empty($allErrors) ? self::valid() : new self($allErrors);
  }

  /**
   * Map errors to add/modify context.
   */
  public function withContext(array $additionalContext): self {
    if ($this->isValid()) {
      return $this;
    }

    $mappedErrors = array_map(
      fn($error) => [
        'error_code' => $error['error_code'],
        'context' => array_merge($error['context'] ?? [], $additionalContext),
      ],
      $this->errors
    );

    return new self($mappedErrors);
  }


  /**
   * Get formatted error messages.
   */
  public function getFormattedErrors(ValidationResultFormatter $formatter, string $name): string {
    if ($this->isValid()) {
      return '';
    }
    
    return $formatter->formatValidationErrors($name, $this->errors);
  }
}