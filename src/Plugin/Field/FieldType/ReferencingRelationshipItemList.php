<?php

namespace Drupal\relationship_nodes\Plugin\Field\FieldType;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
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
 */
class ReferencingRelationshipItemList extends EntityReferenceFieldItemList implements CacheableDependencyInterface {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() : void {
    $related_nodes = $this->collectExistingRelations();
    if (empty($related_nodes)) {
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
  public function collectExistingRelations(): array {
    $current_node = $this->getParent()->getEntity() ?? NULL;
    if (!($current_node instanceof Node) || $current_node->isNew()) {
      return [];
    }
    $relation_bundle = $this->definition['bundle'] ?? '';
    if (empty($relation_bundle)) {
      return [];
    }
    $join_fields = $this->getSettings()['join_field'] ?? [];
    if (empty($join_fields)) {
      return [];
    }
    $relations_by_field = $this->getRelationInfoService()->getReferencingRelations($current_node, $relation_bundle, $join_fields, TRUE) ?? [];
    if (empty($relations_by_field)) {
      return [];
    }

    // Sort each group by weight and flatten.
    return $this->getRelationWeightManager()->sortByWeight($relations_by_field);
  }

  /**
   * {@inheritdoc}
   *
   * Entity reference items save new referenced entities before the host
   * entity is saved. Relations reference their parent node, so they are saved
   * after it by RelationSync::saveDeferredRelations() instead. This computed
   * field stores nothing itself.
   */
  public function preSave() {
  }

  /**
   * {@inheritdoc}
   *
   * The list depends on relation nodes stored elsewhere, so it must be
   * invalidated whenever a relation node of this bundle is created, changed or
   * deleted, also when the list is empty. FormatterBase::view() bubbles this
   * metadata for every formatter.
   */
  public function getCacheTags() {
    $relation_bundle = $this->definition['bundle'] ?? '';
    return $relation_bundle ? ['node_list:' . $relation_bundle] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
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
