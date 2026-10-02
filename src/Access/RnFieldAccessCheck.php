<?php

namespace Drupal\relationship_nodes\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;

/**
 * Access check for the Relationship Nodes field edit and delete routes.
 *
 * Only fields managed by this module are accepted: fields created by it
 * (rn_created) with one of its field names, on a node type or vocabulary.
 * On routes with a bundle parameter, the field must belong to that bundle.
 * The user needs the permission matching the field's entity type.
 */
class RnFieldAccessCheck implements AccessInterface {

  /**
   * Bundle route parameter and permission per supported entity type.
   */
  protected const ENTITY_TYPES = [
    'node' => ['bundle_param' => 'node_type', 'permission' => 'administer content types'],
    'taxonomy_term' => ['bundle_param' => 'taxonomy_vocabulary', 'permission' => 'administer taxonomy'],
  ];

  /**
   * The field name resolver.
   */
  protected FieldNameResolver $fieldResolver;

  /**
   * The relationship field manager.
   */
  protected RelationshipFieldManager $relationFieldManager;

  /**
   * Constructs a RnFieldAccessCheck object.
   *
   * @param \Drupal\relationship_nodes\RelationField\FieldNameResolver $fieldResolver
   *   The field name resolver.
   * @param \Drupal\relationship_nodes\RelationField\RelationshipFieldManager $relationFieldManager
   *   The relationship field manager.
   */
  public function __construct(FieldNameResolver $fieldResolver, RelationshipFieldManager $relationFieldManager) {
    $this->fieldResolver = $fieldResolver;
    $this->relationFieldManager = $relationFieldManager;
  }

  /**
   * Checks access to an RN field route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $field_config = $route_match->getParameter('field_config');
    if (!$field_config instanceof FieldConfigInterface) {
      return AccessResult::forbidden('No field.');
    }

    $entity_type = $field_config->getTargetEntityTypeId();
    $info = static::ENTITY_TYPES[$entity_type] ?? NULL;
    $managed = $info
      && $this->relationFieldManager->isRnCreatedField($field_config)
      && in_array($field_config->getName(), $this->fieldResolver->getAllRelationFieldNames(), TRUE);
    if (!$managed) {
      return AccessResult::forbidden('Not a Relationship Nodes field.')->addCacheableDependency($field_config);
    }

    // Edit routes carry the bundle; the field must belong to it.
    $bundle = $route_match->getRawParameter($info['bundle_param']);
    $route_bundles = array_filter([
      $route_match->getRawParameter('node_type'),
      $route_match->getRawParameter('taxonomy_vocabulary'),
    ]);
    if ($route_bundles && $bundle !== $field_config->getTargetBundle()) {
      return AccessResult::forbidden('The field does not belong to this bundle.')->addCacheableDependency($field_config);
    }

    return AccessResult::allowedIfHasPermission($account, $info['permission'])
      ->addCacheableDependency($field_config);
  }

}
