<?php

// ============================================================
// 1. Value object: src/Display/RelationAvailability.php
// ============================================================
namespace Drupal\relationship_nodes\Display;

/**
 * Represents the availability of a relation node in a given language.
 *
 * Distinguishes between three states:
 * - AVAILABLE: all referenced entities have a translation in the requested
 *   language that the current user may view.
 * - LANGUAGE_UNAVAILABLE: all referenced entities exist and have at least one
 *   viewable translation, but not in the requested language.
 * - UNAVAILABLE: at least one referenced entity has no viewable translation
 *   in any language, or could not be loaded at all.
 */
class RelationAvailability {

  const AVAILABLE = 'available';
  const LANGUAGE_UNAVAILABLE = 'language_unavailable';
  const UNAVAILABLE = 'unavailable';

  /**
   * The availability status (one of the class constants).
   */
  private string $status;

  /**
   * Languages in which all referenced entities have a published translation.
   *
   * @var string[]
   */
  private array $availableLanguages;

  /**
   * Cache tags collected from all referenced entities.
   *
   * @var string[]
   */
  private array $cacheTags;

  /**
   * Cache contexts of the access checks on the referenced entities.
   *
   * @var string[]
   */
  private array $cacheContexts;

  public function __construct(string $status, array $availableLanguages = [], array $cacheTags = [], array $cacheContexts = []) {
    $this->status = $status;
    $this->availableLanguages = $availableLanguages;
    $this->cacheTags = $cacheTags;
    $this->cacheContexts = $cacheContexts;
  }

  /**
   * Returns TRUE if the relation is available in the requested language.
   */
  public function isAvailable(): bool {
    return $this->status === self::AVAILABLE;
  }

  /**
   * Returns TRUE if referenced entities exist but lack the requested language.
   */
  public function isLanguageUnavailable(): bool {
    return $this->status === self::LANGUAGE_UNAVAILABLE;
  }

  /**
   * Returns TRUE if at least one referenced entity has no published translation
   * at all, or could not be loaded.
   */
  public function isUnavailable(): bool {
    return $this->status === self::UNAVAILABLE;
  }

  /**
   * Returns the raw status string.
   */
  public function getStatus(): string {
    return $this->status;
  }

  /**
   * Returns languages in which all referenced entities have a published
   * translation. Empty when status is UNAVAILABLE.
   *
   * @return string[]
   */
  public function getAvailableLanguages(): array {
    return $this->availableLanguages;
  }

  /**
   * Returns cache tags for all referenced entities.
   *
   * @return string[]
   */
  public function getCacheTags(): array {
    return $this->cacheTags;
  }

  /**
   * Returns cache contexts of the access checks on the referenced entities.
   *
   * @return string[]
   */
  public function getCacheContexts(): array {
    return $this->cacheContexts;
  }

}
