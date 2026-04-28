<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\Entity\Node;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;

/**
 * Service for validating relationship entities.
 */
class RelationEntityValidator {

  protected RouteMatchInterface $routeMatch;
  protected RelationInfo $nodeInfoService;
  protected ForeignKeyResolver $foreignKeyResolver;


  /**
   * Constructs a RelationEntityValidator object.
   *
   * @param RouteMatchInterface $routeMatch
   *   The current route match.
   * @param RelationInfo $nodeInfoService
   *   The node info service.
   * @param ForeignKeyResolver $foreignKeyResolver
   *   The foreign key field resolver.
   */
  public function __construct(
    RouteMatchInterface $routeMatch,
    RelationInfo $nodeInfoService,
    ForeignKeyResolver $foreignKeyResolver
  ) {
    $this->routeMatch = $routeMatch;
    $this->nodeInfoService = $nodeInfoService;
    $this->foreignKeyResolver = $foreignKeyResolver;
  }


  /**
   * Checks the validity of a relation entity.
   *
   * @param Node $relation_entity
   *   The relation node to validate.
   *
   * @return string|null
   *   Error type ('incomplete' or 'selfReferring') or NULL if valid.
   */
  public function checkRelationsValidity(Node $relation_entity): ?string {
    $related_entities = $this->nodeInfoService->getRelatedEntityValues($relation_entity); 
    if ($related_entities === null) {
      return null;
    }

    $new_relation = false;
    if ($relation_entity->isNew()) {
      $current_node = $this->routeMatch->getParameter('node');
      $new_relation = true;
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
    return null;
  }
}