<?php

namespace Drupal\relationship_nodes\RelationBundle;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Value object for relation bundle configuration.
 * 
 * Encapsulates all bundle-related logic in one place.
 */
final class RelationBundleInfo {

  private function __construct(
    private readonly ConfigEntityBundleBase $bundle,
    private readonly string $entityTypeId,
    private readonly string $bundleId,
    private readonly bool $isRelation,
    private readonly bool $isTyped,
    private readonly bool $autoTitle,
    private readonly ?string $mirrorType,
  ) {}

  /**
   * Creates a RelationBundleInfo from a bundle entity and its third-party settings.
   *
   * @param ConfigEntityBundleBase $bundle
   *   The node type or vocabulary entity.
   * @param array $properties
   *   The relationship_nodes third-party settings for this entity.
   */
  public static function create(
    ConfigEntityBundleBase $bundle,
    array $properties
  ): self {
    $isRelation = !empty($properties['enabled']);
    
    return new self(
      bundle: $bundle,
      entityTypeId: $bundle->getEntityTypeId(),
      bundleId: $bundle->id(),
      isRelation: $isRelation,
      isTyped: $isRelation && !empty($properties['typed_relation']),
      autoTitle: !empty($properties['auto_title']),
      mirrorType: $properties['referencing_type'] ?? null,
    );
  }

  // ===== Public API =====

  public function getBundle(): ConfigEntityBundleBase {
    return $this->bundle;
  }

  public function getBundleId(): string {
    return $this->bundleId;
  }

  public function getEntityTypeId(): string {
    return $this->entityTypeId;
  }

  /**
   * Returns TRUE if this bundle is enabled as a relation bundle.
   */
  public function isRelation(): bool {
    return $this->isRelation;
  }

  /**
   * Returns TRUE if this is a typed relation (has a relation type vocabulary).
   */
  public function isTypedRelation(): bool {
    return $this->isTyped;
  }

  /**
   * Returns TRUE if relation nodes of this bundle get an auto-generated title.
   */
  public function hasAutoTitle(): bool {
    return $this->autoTitle;
  }

  /**
   * Returns the mirror field type ('string', 'entity_reference', 'none', or NULL).
   */
  public function getMirrorType(): ?string {
    return $this->mirrorType;
  }

  /**
   * Returns TRUE if this vocabulary has a configured bidirectional mirror field.
   */
  public function isMirroringVocab(): bool {
    return in_array($this->mirrorType, ['string', 'entity_reference'], true);
  }

  public function isNodeType(): bool {
    return $this->bundle instanceof NodeType;
  }

  public function isVocabulary(): bool {
    return $this->bundle instanceof Vocabulary;
  }

  /**
   * Returns the Drupal entity type ID for the bundle entity's object class.
   *
   * @return string
   *   'node' for node types, 'taxonomy_term' for vocabularies.
   *
   * @throws \InvalidArgumentException
   *   If the bundle entity type is not recognised.
   */
  public function getObjectClass(): string {
    return match($this->entityTypeId) {
      'node_type' => 'node',
      'taxonomy_vocabulary' => 'taxonomy_term',
      default => throw new \InvalidArgumentException("Unknown entity type: {$this->entityTypeId}"),
    };
  }

  
  /**
   * Returns the rn_* field names that must exist for this bundle configuration.
   *
   * @return array
   *   List of required field machine names, or empty array if not a relation.
   */
  public function getRequiredFieldNames(): array {
    if (!$this->isRelation) {
      return [];
    }
    
    $fields = [];
    
    if ($this->isNodeType()) {
      $fields[] = 'rn_related_entity_1';
      $fields[] = 'rn_related_entity_2';
      
      if ($this->isTyped) {
        $fields[] = 'rn_relation_type';
      }
    }
    
    if ($this->isVocabulary() && $this->mirrorType && $this->mirrorType !== 'none') {
      $fields[] = $this->mirrorType === 'string' 
        ? 'rn_mirror_string' 
        : 'rn_mirror_reference';
    }
    
    return $fields;
  }

  /**
   * Converts back to array for saving.
   */
  public function toArray(): array {
    $array = ['enabled' => $this->isRelation];
    
    if ($this->isRelation) {
      if ($this->isTyped) {
        $array['typed_relation'] = true;
      }
      if ($this->autoTitle) {
        $array['auto_title'] = true;
      }
      if ($this->mirrorType !== null) {
        $array['referencing_type'] = $this->mirrorType;
      }
    }
    
    return $array;
  }
}