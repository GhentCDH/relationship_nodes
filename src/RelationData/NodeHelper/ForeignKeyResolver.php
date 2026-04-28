<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;

/**
 * Service for resolving foreign key fields in relationship nodes.
 */
class ForeignKeyResolver {

  protected RouteMatchInterface $routeMatch;
  protected BundleInfoService $bundleInfoService;
  protected RelationInfo $nodeInfoService;


  /**
   * Constructs a ForeignKeyResolver object.
   *
   * @param RouteMatchInterface $routeMatch
   *   The current route match.
   * @param BundleInfoService $bundleInfoService
   *   The bundle info service.
   * @param RelationInfo $nodeInfoService
   *   The node info service.
   */
  public function __construct(
    RouteMatchInterface $routeMatch,
    BundleInfoService $bundleInfoService,
    RelationInfo $nodeInfoService,
  ) {
    $this->routeMatch = $routeMatch;
    $this->bundleInfoService = $bundleInfoService;
    $this->nodeInfoService = $nodeInfoService;
  }


  /**
   * Gets the default foreign key field for a relation bundle.
   *
   * @param string $relation_bundle
   *   The relation bundle ID.
   * @param string|null $target_bundle
   *   The target bundle ID.
   *
   * @return string|null
   *   The foreign key field name or NULL.
   */
  public function getDefaultBundleForeignKeyField(string $relation_bundle, string $target_bundle = null): ?string {       
    if (!$target_bundle) {
      $target_entity = $this->ensureTargetNode();
      if (!($target_entity instanceof NodeInterface)) {
        return null;
      }
      $target_bundle = $target_entity->getType();
    }        
    
    $connection_info = $this->bundleInfoService->getBundleConnectionInfo($relation_bundle, $target_bundle) ?? [];
    return $this->connectionInfoToForeignKey($connection_info);
  }


  public function getEntityForeignKeyField(NodeInterface $relation_entity, ?NodeInterface $target_entity = NULL): ?string {
    $target_entity = $this->ensureTargetNode($target_entity);
    if (!$target_entity) {
      return null;
    }
    $relation_type = $relation_entity->getType();
    $target_entity_type = $target_entity->getType();
    if ($relation_entity->isNew() || $target_entity->isNew()) {
      $connection_info = $this->bundleInfoService->getBundleConnectionInfo($relation_type, $target_entity_type) ?? [];
    } else {
      $connection_info = $this->nodeInfoService->getEntityConnectionInfo($relation_entity, $target_entity) ?? [];
    }
    return $this->connectionInfoToForeignKey($connection_info);
  }


  /**
   * Gets the foreign key field from an entity form.
   *
   * @param NodeInterface $relation_node
   *   The entity form array.
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The foreign key field name or NULL.
   */
  public function getEntityFormForeignKeyField(NodeInterface $relation_node, FormStateInterface $form_state): ?string {
    $form_entity = $form_state->getFormObject()->getEntity();
    return $this->getEntityForeignKeyField($relation_node,  $form_entity);   
  }


  /**
   * Ensures a target node is available.
   *
   * @param NodeInterface|null $node
   *   The node or NULL.
   *
   * @return NodeInterface|null
   *   The node or NULL.
   */
  private function ensureTargetNode(?NodeInterface $node = null): ?NodeInterface {
    if ($node instanceof NodeInterface) {
      return $node;
    }
    $current_node = $this->routeMatch->getParameter('node');
    return $current_node instanceof NodeInterface ? $current_node : null;
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
      return null;
    }
    $join_fields = $connection_info['join_fields'];

    if (!is_array($join_fields)) {
      return null;
    }
    return $join_fields[0] ?? null;
  }
}