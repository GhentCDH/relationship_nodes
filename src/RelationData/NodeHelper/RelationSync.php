<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\Form\Entity\RelationFormHelper;

/**
 * Service for synchronizing relationship nodes.
 *
 * Handles binding relations to parent nodes, saving subform relations,
 * and removing orphaned relations.
 */
class RelationSync {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected RelationInfo $nodeInfoService;
  protected ForeignKeyResolver $foreignKeyResolver;
  protected RelationFormHelper $formHelper;
  protected RelationWeightManager $relationWeightManager;

  /**
   * Constructs a RelationSync object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo $nodeInfoService
   *   The node info service.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\ForeignKeyResolver $foreignKeyResolver
   *   The foreign key field resolver.
   * @param \Drupal\relationship_nodes\Form\Entity\RelationFormHelper $formHelper
   *   The form helper.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\RelationWeightManager $relationWeightManager
   *   The relation subform weight manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    RelationInfo $nodeInfoService,
    ForeignKeyResolver $foreignKeyResolver,
    RelationFormHelper $formHelper,
    RelationWeightManager $relationWeightManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->nodeInfoService = $nodeInfoService;
    $this->foreignKeyResolver = $foreignKeyResolver;
    $this->formHelper = $formHelper;
    $this->relationWeightManager = $relationWeightManager;
  }

  /**
   * Hard-deletes relation nodes and their associated weights.
   *
   * Relations are hard-deleted (not unpublished or soft-deleted) because a
   * relation node with a missing parent is structurally invalid — it can no
   * longer be displayed or edited meaningfully. Soft-deletion would leave
   * dangling references in the Elasticsearch index and confuse weight ordering.
   * Weights are removed first so the keyvalue store does not accumulate stale
   * entries for IDs that will never exist again.
   *
   * @param array $ids_to_remove
   *   Array of node IDs to delete.
   */
  public function deleteNodes(array $ids_to_remove): void {
    if (empty($ids_to_remove)) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($ids_to_remove as $id) {
      $node = $storage->load($id);
      $this->relationWeightManager->deleteAllWeights($id);
      if ($node instanceof NodeInterface) {
        $node->delete();
      }
    }
  }

  /**
   * Saves relation changes from the relation widgets of a saved parent node.
   *
   * Runs after the parent node is saved, so new relations can reference it.
   * Removed relations are only deleted once the parent is saved.
   *
   * @param \Drupal\node\NodeInterface $parent_node
   *   The saved parent node.
   * @param array $deferred_per_widget
   *   Per widget: 'entities' (items with 'entity', 'weight', 'needs_save')
   *   and 'delete' (removed relation nodes).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function saveDeferredRelations(NodeInterface $parent_node, array $deferred_per_widget, FormStateInterface $form_state): void {
    $handler = $this->entityTypeManager->getHandler('node', 'inline_form');
    foreach ($deferred_per_widget as $deferred) {
      foreach ($deferred['entities'] ?? [] as $entity_item) {
        $entity = $entity_item['entity'];
        $needs_save = $entity_item['needs_save'];
        $foreign_key = $this->foreignKeyResolver->getEntityForeignKeyField($entity, $parent_node);
        if ($foreign_key && $entity->hasField($foreign_key) && (int) $entity->get($foreign_key)->target_id !== (int) $parent_node->id()) {
          $entity->set($foreign_key, [['target_id' => $parent_node->id()]]);
          $needs_save = TRUE;
        }
        if ($needs_save) {
          $handler->save($entity);
        }
        // Always save the weight, also for reordered but unchanged relations.
        if ($entity->id() && $foreign_key) {
          $this->relationWeightManager->setWeight((int) $entity->id(), $foreign_key, $entity_item['weight']);
        }
      }
      $removed_ids = [];
      foreach ($deferred['delete'] ?? [] as $removed) {
        if ($removed instanceof NodeInterface && $removed->id()) {
          $removed_ids[] = $removed->id();
        }
      }
      // Also removes the weights of the deleted relations.
      $this->deleteNodes($removed_ids);
    }
  }

}
