<?php


namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\Entity\Node;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;


/**
 * Service for fetching information about relation nodes.
 *
 * Provides methods to inspect relation connections between nodes,
 * fetch join fields, referencing relations, and related entity values.
 */
class RelationInfo {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected RouteMatchInterface $routeMatch;
  protected FieldNameResolver $fieldNameResolver;
  protected BundleInfoService $bundleInfoService;
  protected BundleSettingsManager $settingsManager;


  /**
   * Constructs a RelationInfo object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param RouteMatchInterface $routeMatch
   *   The current route match.
   * @param FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   * @param BundleInfoService $bundleInfoService
   *   The bundle info service.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    RouteMatchInterface $routeMatch,
    FieldNameResolver $fieldNameResolver,
    BundleInfoService $bundleInfoService,
    BundleSettingsManager $settingsManager
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->routeMatch = $routeMatch;
    $this->fieldNameResolver = $fieldNameResolver;
    $this->bundleInfoService = $bundleInfoService;
    $this->settingsManager = $settingsManager;
  }


  /**
   * Returns the 'related entity' fields in the relation node that reference a given target node.
   *
   * @param Node $relation_node
   *   The relation node to inspect.
   * @param array $field_names
   *   List of field names to check for references.
   * @param Node|null $target_node
   *   The target node to check connections against.
   *
   * @return array
   *   Array of 'related entity' field names that reference the target node.
   */
  public function getJoinFields(Node $relation_node, array $field_names, ?Node $target_node = NULL): array {
    $result = [];
    $bundle_connections = $this->bundleInfoService->getBundleConnectionInfo($relation_node->getType(), $target_node->getType());
    
    if( empty($bundle_connections['join_fields'])) {
      return $result;
    }
    
    $target_id = $target_node->id();

    foreach ($field_names as $field) {
      if (in_array($field, $bundle_connections['join_fields'])) {
        $references = $relation_node->get($field)->getValue();
        foreach ($references as $ref) {
          if (isset($ref['target_id']) && $ref['target_id'] == $target_id) {
            $result[] = $field;
            break;
          }
        }
      }
    }
    return $result;
  }


  /**
   * Gets the connection info between a relation node and a target node.
   * 
   * Returns information about how a specific relation node connects to a target node,
   * including which fields create the connection and whether it's valid.
   * 
   * @param Node $relation_node
   *   The relation node to inspect (e.g., "Partnership between X and Y").
   * @param Node|null $target_node
   *   The target node to check connections against (e.g., node "X").
   *   If NULL, uses the current route parameter.
   *
   * @return array
   *   Array with keys:
   *   - 'relation_state': string
   *     * 'unrelated': no connection found
   *     * 'related': valid single connection found
   *     * 'Error: duplicate relations': multiple conflicting connections found
   *   - 'join_fields': array of field names that connect the nodes
   *     Example: ['rn_related_entity_1'] or ['rn_related_entity_1', 'rn_related_entity_2']
   *   - 'relation_info': array (optional) with relation bundle metadata
   *     * 'has_relationtype': bool
   *     * 'vocabulary': string (vocab machine name if typed relation)
   *   
   *   Returns empty array if target node is invalid.
   *   
   * @example
   *   // For a "Partnership" relation between Company A (nid:1) and Company B (nid:2)
   *   // When checking from Company A's perspective:
   *   $info = $service->getEntityConnectionInfo($partnership_node, $company_a_node);
   *   // Returns:
   *   // [
   *   //   'relation_state' => 'related',
   *   //   'join_fields' => ['rn_related_entity_1'],
   *   //   'relation_info' => ['has_relationtype' => TRUE, 'vocabulary' => 'partnership_types']
   *   // ]
   */
  public function getEntityConnectionInfo(Node $relation_node, ?Node $target_node = NULL): array {
    if (empty($target_node)) {
      $target_node = $this->routeMatch->getParameter('node');
    }

    if (!$target_node instanceof Node) {
      return [];
    }

    $bundle_connections = $this->bundleInfoService->getBundleConnectionInfo($relation_node->getType(), $target_node->getType());
    $result = ['relation_state' => 'unrelated'];

    if (empty($bundle_connections['join_fields'])) {
      return $result;
    }

    $connections = $this->getJoinFields($relation_node, $bundle_connections['join_fields'], $target_node) ?? [];

    switch (count($connections)) {
      case 0:
        break;
      case 1:
        $result = [
          'relation_state' => 'related',
          'join_fields' => $connections,
          'relation_info' => $bundle_connections['relation_info'] ?? [],
        ];
        break;
      default: 
        $result = [
          'relation_state' => 'Error: duplicate relations',
          'join_fields' => $connections,
        ];
    }

    return $result;
  }


  /**
   * Returns all relation nodes that reference a given target node through a specific relation bundle.
   *
   * @param Node $target_node
   * @param string $relation_bundle
   * @param array $join_fields
   *   Optional: list of 'related entity' fields through which the target node is referenced (in the relation bundle).
   * @param bool $group_by_field
   *   Optional: should the result be grouped by field (e.g. field_related_item => [], 'field related_item_2 = []) ->true, or flattened -> false.
   *
   * @return array
   *   Array of referencing relation node objects, keyed by their ID.
   */
  public function getReferencingRelations(Node $target_node, string $relation_bundle, array $join_fields = [], bool $group_by_field = false): array {
    $target_bundle = $target_node->getType();
    if (empty($join_fields)) {
      $connection_info = $this->bundleInfoService->getBundleConnectionInfo($relation_bundle, $target_bundle) ?? [];
      if (empty($connection_info['join_fields'])) {
        return [];
      }
      $join_fields = $connection_info['join_fields'];
    }
    $target_id = $target_node->id();
    $node_storage = $this->entityTypeManager->getStorage('node');
    $result = [];
    foreach ($join_fields as $join_field) {
      $relations = $node_storage->loadByProperties([
        'type' => $relation_bundle,
        $join_field => $target_id,
      ]);
      if (!empty($relations)) {
        $result[$join_field] = $relations;
      }
    }
    if($group_by_field){
      return $result;
    }
    $flattened = [];
    foreach($result as $field_result){
      $flattened = $flattened + $field_result;
    }
    return $flattened;
  }


  /**
   * Get a list of all nodes that are related to a given target node (grouped by the relation bundle that connects them).
   *
   * @param Node $target_node
   *
   * @return array
   *  Associative array of associative arrays.
   *  The outer array is keyed by the relation bundle names and has arrays of related nodes as value [node_id => Node,...].
   */
  public function getAllReferencingRelations(Node $target_node): array {
    $result = [];
    $target_bundle_info = $this->bundleInfoService->getRelationInfoForTargetBundle($target_node->getType());    
    
    if (empty($target_bundle_info)) {
      return $result;
    }

    foreach ($target_bundle_info as $relation_bundle => $relation_info) {
      $join_fields = isset($relation_info['join_fields']) ? $relation_info['join_fields'] : [];
      $bundle_result = $this->getReferencingRelations($target_node, $relation_bundle, $join_fields);
      if (!empty($bundle_result)) {
        $result[$relation_bundle] = $bundle_result;
      }
    }

    return $result;
  }


  /**
   * Returns the target entity IDs for all related entity fields in a relation node.
   *
   * @param Node $relation_node
   *
   * @return array|null
   *  Associative array of related enity field names => array of target IDs, or NULL if not a relation node type.
   *  E.g. ['related_entity_field_1' => 101, 'related_entity_field_2' => 202]
   */
  public function getRelatedEntityValues(Node $relation_node): ?array {   
    $bundle_info = $this->settingsManager->getBundleInfo($relation_node->getType());    
    if (!$bundle_info || !$bundle_info->isRelation()) {
      return null;
    }

    $result = [];
    foreach ($this->fieldNameResolver->getRelatedEntityFields() as $related_entity_field) {
      $related_field = $relation_node->get($related_entity_field);
      if (!$related_field instanceof EntityReferenceFieldItemList) {
        return null;    
      }
      $relation_references = $this->getFieldListTargetIds($related_field);
      if (empty($relation_references)) {
        continue;
      }
      $result[$related_entity_field] = $relation_references;
    }
    return $result;   
  }

  
  /**
 * Extracts target IDs from an entity reference field list.
   *
   * @param EntityReferenceFieldItemList $list
   *
   * @return array
   *   Array of target entity IDs.
   */
  public function getFieldListTargetIds(EntityReferenceFieldItemList $list): array {
    $result = []; 
    foreach ($list->getValue() as $item) {
      if (is_array($item) && isset($item['target_id'])) {
          $result[] = (int) $item['target_id'];
      }
    }
    return $result;
  }
}