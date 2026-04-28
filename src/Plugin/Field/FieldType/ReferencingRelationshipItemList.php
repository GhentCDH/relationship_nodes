<?php

namespace Drupal\relationship_nodes\Plugin\Field\FieldType;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\node\Entity\Node;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationWeightManager;



/**
 * Computed entity reference field listing all relation nodes for a bundle.
 *
 * This is the runtime list class that backs each
 * `computed_relationshipfield__*` virtual field. On first access it queries
 * the database for relation nodes that reference the host entity through
 * one of the configured join fields, then sorts them by stored weight.
 *
 * @FieldType(
 *   id = "referencing_relationship_item_list",
 *   label = @Translation("Referencing Relationship Item List"),
 *   description = @Translation("Field type: reference in two directions (referencing and referenced)."),
 * )
 */
class ReferencingRelationshipItemList extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;


  /**
   * {@inheritdoc}
   */
  protected function computeValue() : void {
    $related_nodes = $this->collectExistingRelations();
    if(empty($related_nodes)){
      return;
    }

    $delta = 0;
    foreach ($related_nodes as $target_id => $related_node) {
      $this->list[$delta] = $this->createItem($delta, ['target_id' => $target_id, 'entity' => $related_node]);
      $delta++;
    }     
  }   
  

  /**
   * Queries and returns all existing relation nodes for the host entity.
   *
   * @return array
   *   Relation node entities keyed by ID, sorted by weight.
   */
  public function collectExistingRelations(): array{
    $current_node = $this->getParent()->getEntity() ?? null;
    if(!($current_node instanceof Node) || $current_node->isNew()){
      return [];
    }
    $relation_bundle = $this->definition['bundle'] ?? '';
    if(empty($relation_bundle)){
      return [];
    }
    $join_fields = $this->getSettings()['join_field'] ?? [];
    if(empty($join_fields)){
      return [];
    }
    $relations_by_field = $this->getRelationInfoService()->getReferencingRelations($current_node, $relation_bundle, $join_fields, TRUE) ?? [];
    if (empty($relations_by_field)) {
      return [];
    }
    
    // Sort each group by weight and flatten
    return $this->getRelationWeightManager()->sortByWeight($relations_by_field);
  }


  /**
   * Returns the RelationInfo service.
   *
   * Accessed via the service container rather than constructor injection
   * because field item list classes are instantiated by Drupal's typed data
   * layer, which does not support DI constructor arguments.
   */
  protected function getRelationInfoService(): RelationInfo {
    return \Drupal::service('relationship_nodes.relation_info');
  }

  /**
   * Returns the RelationWeightManager service.
   */
  protected function getRelationWeightManager(): RelationWeightManager {
    return \Drupal::service('relationship_nodes.relation_weight_manager');
  }
}