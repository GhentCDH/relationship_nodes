<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\relationship_nodes\Form\Entity\ParentNodeContext;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;

/**
 * Service for resolving foreign key fields in relationship nodes.
 */
class ForeignKeyResolver {

  /**
   * The parent node context.
   */
  protected ParentNodeContext $parentNodeContext;

  /**
   * The bundle info service.
   */
  protected BundleInfoService $bundleInfoService;

  /**
   * The relation info.
   */
  protected RelationInfo $nodeInfoService;

  /**
   * Constructs a ForeignKeyResolver object.
   *
   * @param \Drupal\relationship_nodes\Form\Entity\ParentNodeContext $parentNodeContext
   *   The parent node context.
   * @param \Drupal\relationship_nodes\RelationBundle\BundleInfoService $bundleInfoService
   *   The bundle info service.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo $nodeInfoService
   *   The node info service.
   */
  public function __construct(
    ParentNodeContext $parentNodeContext,
    BundleInfoService $bundleInfoService,
    RelationInfo $nodeInfoService,
  ) {
    $this->parentNodeContext = $parentNodeContext;
    $this->bundleInfoService = $bundleInfoService;
    $this->nodeInfoService = $nodeInfoService;
  }

  /**
   * Returns the related entity field of a relation that references a node.
   *
   * @param \Drupal\node\NodeInterface $relation_entity
   *   The relation node.
   * @param \Drupal\node\NodeInterface|null $target_entity
   *   The referenced node; defaults to the node whose relations are edited.
   *
   * @return string|null
   *   The field name, or NULL if none.
   */
  public function getEntityForeignKeyField(NodeInterface $relation_entity, ?NodeInterface $target_entity = NULL): ?string {
    $target_entity = $this->ensureTargetNode($target_entity);
    if (!$target_entity) {
      return NULL;
    }
    $relation_type = $relation_entity->getType();
    $target_entity_type = $target_entity->getType();
    if ($relation_entity->isNew() || $target_entity->isNew()) {
      $connection_info = $this->bundleInfoService->getBundleConnectionInfo($relation_type, $target_entity_type) ?? [];
    }
    else {
      $connection_info = $this->nodeInfoService->getEntityConnectionInfo($relation_entity, $target_entity) ?? [];
    }
    return $this->connectionInfoToForeignKey($connection_info);
  }

  /**
   * Gets the foreign key field from an entity form.
   *
   * @param \Drupal\node\NodeInterface $relation_node
   *   The entity form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The foreign key field name or NULL.
   */
  public function getEntityFormForeignKeyField(NodeInterface $relation_node, FormStateInterface $form_state): ?string {
    $form_entity = $form_state->getFormObject()->getEntity();
    return $this->getEntityForeignKeyField($relation_node, $form_entity);
  }

  /**
   * Ensures a target node is available.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The node or NULL.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node or NULL.
   */
  private function ensureTargetNode(?NodeInterface $node = NULL): ?NodeInterface {
    if ($node instanceof NodeInterface) {
      return $node;
    }
    $current_node = $this->parentNodeContext->getParentNode();
    return $current_node instanceof NodeInterface ? $current_node : NULL;
  }

  /**
   * Converts connection info to foreign key field name.
   *
   * @param array $connection_info
   *   The connection info array.
   *
   * @return string|null
   *   The foreign key field name or NULL.
   */
  private function connectionInfoToForeignKey(array $connection_info): ?string {
    if (empty($connection_info['join_fields'])) {
      return NULL;
    }
    $join_fields = $connection_info['join_fields'];

    if (!is_array($join_fields)) {
      return NULL;
    }
    return $join_fields[0] ?? NULL;
  }

}
