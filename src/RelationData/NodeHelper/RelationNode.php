<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\RelationBundle\RelationBundleInfo;

/**
 * Wrapper that adds relation-specific behavior to nodes.
 * 
 * This is NOT a subclass of Node - it wraps/decorates it.
 * Use this when you need to work with relation-specific logic.
 */
final class RelationNode {

  private function __construct(
    private readonly NodeInterface $node,
    private readonly RelationBundleInfo $bundleInfo,
  ) {}

  /**
   * Creates a wrapper if the node is a relation node.
   * 
   * @return self|null
   *   The wrapper, or NULL if not a relation node.
   */
  public static function tryWrap(
    NodeInterface $node,
    RelationBundleInfo $bundleInfo
  ): ?self {
    if (!$bundleInfo->isRelation()) {
      return null;
    }
    
    return new self($node, $bundleInfo);
  }

  /**
   * Gets the underlying node entity.
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * Gets bundle info.
   */
  public function getBundleInfo(): RelationBundleInfo {
    return $this->bundleInfo;
  }

  /**
   * Checks if this is a typed relation.
   */
  public function isTyped(): bool {
    return $this->bundleInfo->isTypedRelation();
  }

  /**
   * Gets related entity IDs.
   * 
   * Encapsulates logic from RelationInfo::getRelatedEntityValues.
   */
  public function getRelatedEntityIds(): array {
    $result = [];
    
    $fields = ['rn_related_entity_1', 'rn_related_entity_2'];
    foreach ($fields as $fieldName) {
      if (!$this->node->hasField($fieldName)) {
        continue;
      }
      
      $values = $this->node->get($fieldName)->getValue();
      $ids = array_map(fn($item) => (int)$item['target_id'], $values);
      
      if (!empty($ids)) {
        $result[$fieldName] = $ids;
      }
    }
    
    return $result;
  }

  /**
   * Validates the relation structure.
   */
  public function validate(): array {
    $errors = [];
    
    $relatedEntities = $this->getRelatedEntityIds();
    
    // Check for incomplete relations (unless it's new)
    if (!$this->node->isNew() && count($relatedEntities) !== 2) {
      $errors[] = 'incomplete';
    }
    
    // Check for self-referencing
    if (count($relatedEntities) === 2) {
      $entities = array_values($relatedEntities);
      foreach ($entities[0] as $id) {
        if (in_array($id, $entities[1], true)) {
          $errors[] = 'selfReferring';
          break;
        }
      }
    }
    
    return $errors;
  }

  /**
   * Generates automatic title.
   */
  public function generateTitle(EntityTypeManagerInterface $entityTypeManager): string {
    $relatedEntities = $this->getRelatedEntityIds();
    
    if (empty($relatedEntities)) {
      return 'Relationship (no entities)';
    }
    
    $titleParts = [];
    $nodeStorage = $entityTypeManager->getStorage('node');
    
    foreach ($relatedEntities as $fieldValues) {
      $nodeTitles = [];
      foreach ($fieldValues as $nid) {
        $node = $nodeStorage->load($nid);
        if ($node instanceof NodeInterface) {
          $nodeTitles[] = $node->getTitle();
        }
      }
      if (!empty($nodeTitles)) {
        $titleParts[] = implode(', ', $nodeTitles);
      }
    }
    
    return 'Relationship ' . implode(' - ', $titleParts);
  }

  /**
   * Delegates to underlying node.
   */
  public function __call(string $method, array $args) {
    return $this->node->$method(...$args);
  }
}