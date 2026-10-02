<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\relationship_nodes\Form\Entity\ParentNodeContext;
use Drupal\node\Entity\Node;

/**
 * Service for validating relationship entities.
 */
class RelationEntityValidator {

  protected ParentNodeContext $parentNodeContext;
  protected RelationInfo $nodeInfoService;
  protected ForeignKeyResolver $foreignKeyResolver;

  /**
   * Constructs a RelationEntityValidator object.
   *
   * @param \Drupal\relationship_nodes\Form\Entity\ParentNodeContext $parentNodeContext
   *   The parent node context.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo $nodeInfoService
   *   The node info service.
   * @param ForeignKeyResolver $foreignKeyResolver
   *   The foreign key field resolver.
   */
  public function __construct(
    ParentNodeContext $parentNodeContext,
    RelationInfo $nodeInfoService,
    ForeignKeyResolver $foreignKeyResolver,
  ) {
    $this->parentNodeContext = $parentNodeContext;
    $this->nodeInfoService = $nodeInfoService;
    $this->foreignKeyResolver = $foreignKeyResolver;
  }

  /**
   * Checks the validity of a relation entity.
   *
   * @param \Drupal\node\Entity\Node $relation_entity
   *   The relation node to validate.
   *
   * @return string|null
   *   Error type ('incomplete' or 'selfReferring') or NULL if valid.
   */
  public function checkRelationsValidity(Node $relation_entity): ?string {
    $related_entities = $this->nodeInfoService->getRelatedEntityValues($relation_entity);
    if ($related_entities === NULL) {
      return NULL;
    }

    $new_relation = FALSE;
    if ($relation_entity->isNew()) {
      $current_node = $this->parentNodeContext->getParentNode();
      $new_relation = TRUE;
      if ($current_node instanceof Node && $current_node !== $relation_entity) {
        // Relation is added in a subform (IEF)
        $foreign_key_field = $this->foreignKeyResolver->getEntityForeignKeyField($relation_entity, $current_node);
        if ($foreign_key_field) {
          $related_entities[$foreign_key_field] = [$current_node->id()];
        }
      }
    }
    if (count($related_entities) != 2 && !$new_relation) {
      return 'incomplete';
    }

    $related_entities = array_values($related_entities);
    foreach ($related_entities[0] as $reference) {
      if (in_array($reference, $related_entities[1] ?? [])) {
        return 'selfReferring';
      }
    }
    return NULL;
  }

}
