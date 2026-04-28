<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;


/**
 * Service for updating field configuration UI elements.
 *
 * Overrides edit operations and local tasks for relationship node fields.
 */
class FieldUiManager {

  use StringTranslationTrait;

  protected EntityTypeManagerInterface $entityTypeManager;
  protected RouteMatchInterface $routeMatch;
  protected FieldNameResolver $fieldResolver;
  protected BundleSettingsManager $settingsManager;
  protected RelationshipFieldManager $relationFieldManager;
  

  /**
   * Constructs a FieldUiManager object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param RouteMatchInterface $routeMatch
   *   The current route match.
   * @param FieldNameResolver $fieldResolver
   *   The field name resolver.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager.
   * @param RelationshipFieldManager $relationFieldManager
   *   The field configurator.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager, 
    RouteMatchInterface $routeMatch, 
    FieldNameResolver $fieldResolver,
    BundleSettingsManager $settingsManager, 
    RelationshipFieldManager $relationFieldManager
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->routeMatch = $routeMatch;
    $this->fieldResolver = $fieldResolver;
    $this->settingsManager = $settingsManager;
    $this->relationFieldManager = $relationFieldManager;
  }
  

  /**
   * Gets the relation field configuration URL.
   *
   * @param FieldConfig $field_config
   *   The field configuration.
   *
   * @return Url|null
   *   The URL or NULL.
   */
  public function getRelationFieldConfigUrl(FieldConfig $field_config): ?Url {    
    $url_info = $this->getDefaultRoutingInfo($field_config->getTargetEntityTypeId());

    if (empty($url_info)) {
      return null;
    }

    return Url::fromRoute($url_info['rn_field_edit_route'],[
      $url_info['bundle_param_key'] => $field_config->getTargetBundle(), 
      'field_config' => $field_config->id(),
    ]);
  }


  /**
   * Gets the relation field delete URL.
   *
   * @param FieldConfig $field_config
   *   The field configuration.
   *
   * @return Url|null
   *   The URL or NULL.
   */
  public function getRelationFieldDeleteUrl(FieldConfig $field_config): ?url {
    $url = Url::fromRoute('relationship_nodes.rn_field_delete',['field_config' => $field_config->id(),]);
    return $url ?? null;
  }


  /**
   * Overrides edit operations for relation fields.
   *
   * @param array $row
   *   The table row (passed by reference).
   * @param FieldConfig $field_config
   *   The field configuration.
   * @param array $original_operations
   *   The original operations array.
   */
  public function overrideOperationsEdit(array &$row, FieldConfig $field_config, array $original_operations): void {   
    if (!$this->relationFieldManager->isRnCreatedField($field_config)) {
      return;
    }
    
    if (!in_array($row['data']['field_name'], $this->fieldResolver->getAllRelationFieldNames())) {
      return;
    }

    unset($row['data']['operations']);
    unset($row['class']['menu-disabled']); 
    
    $row['data'] = $row['data'] + $original_operations; 
    $url = $this->getRelationFieldConfigUrl($field_config);
    $row['data']['operations']['data']['#links']['edit']['url'] = $url;

    if (!$this->currentRouteIsRelationEntity()) {
      $delete_url = $this->getRelationFieldDeleteUrl($field_config);
      $row['data']['operations']['data']['#links']['delete'] = [
        'title'=> t('Delete'),
        'weight' => 999, 
        'url' => $delete_url,
      ];
    }  
  }


  /**
   * Overrides local tasks edit for relation fields.
   *
   * @param array $local_tasks
   *   The local tasks array (passed by reference).
   */
  public function overrideLocalTasksEdit(&$local_tasks): void {
    $field_config = $this->routeMatch->getParameter('field_config');

    if (!$field_config instanceof FieldConfig || !$this->relationFieldManager->isRnCreatedField($field_config)) {
      return;
    }

    $routing_info = $this->getDefaultRoutingInfo($field_config->getTargetEntityTypeId());

    if (empty($routing_info)) {
      return;
    }

    $route_name = $local_tasks[$routing_info['field_edit_local_task']]['route_name'];
    if (empty($route_name) ||  $route_name !== $routing_info['field_edit_form_route']) {
      return;
    }

    if (!in_array($field_config->getName(), $this->fieldResolver->getAllRelationFieldNames())) {
      return;
    }

    $local_tasks[$routing_info['field_edit_local_task']]['route_name'] = $routing_info['rn_field_edit_route'];
  }

  
  /**
   * Gets the bundle from the current route.
   *
   * @return NodeType|Vocabulary|null
   *   The bundle entity or NULL.
   */
  public function getBundleFromCurrentRoute(): NodeType|Vocabulary|null {
    $entity_type_id = $this->routeMatch->getParameter('entity_type_id');
    switch ($entity_type_id) {
      case 'node':
        $bundle = $this->routeMatch->getParameter('node_type');
        break;
      case 'taxonomy_term':
        $bundle = $this->routeMatch->getParameter('taxonomy_vocabulary');
        break;
      default:
        $bundle = null;
        break;
    }
    if (is_string($bundle)) {
      $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);
      $bundle = $entity_storage->load($bundle);
    }

    if (!($bundle instanceof NodeType || $bundle instanceof Vocabulary)) {
      return null;
    }
    return $bundle;
  }


  /**
   * Gets the redirect URL for a field configuration.
   *
   * @param FieldConfig $field_config
   *   The field configuration.
   *
   * @return Url
   *   The redirect URL.
   */
  public function getRedirectUrl(FieldConfig $field_config): Url {
    $entity_type = $field_config->getTargetEntityTypeId();
    $bundle = $field_config->getTargetBundle();

    switch ($entity_type) {
      case 'node':
        $url = Url::fromRoute('entity.node.field_ui_fields', ['node_type' => $bundle]);
        break;
      case 'taxonomy_term':
        $url = Url::fromRoute('entity.taxonomy_term.field_ui_fields', ['taxonomy_vocabulary' => $bundle]);
        break;
      default:
        $url = Url::fromRoute('<front>');
        break;
    }
    return $url;
  }


  /**
   * Checks if the current route is for a relation entity.
   *
   * @return bool
   *   TRUE if current route is for a relation entity, FALSE otherwise.
   */
  public function currentRouteIsRelationEntity(): bool {
    $bundle_entity = $this->getBundleFromCurrentRoute();
    if (!$bundle_entity) {
      return false;
    }
    $bundle_info = $this->settingsManager->getBundleInfo($bundle_entity);  
    return $bundle_info && $bundle_info->isRelation();
  }


  /**
   * Gets default routing information for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   Array of routing information.
   */
  public function getDefaultRoutingInfo(string $entity_type_id): array {
    $mapping = [
      'node' => [
        'bundle_param_key' => 'node_type',
      ],
      'taxonomy_term' => [
        'bundle_param_key' => 'taxonomy_vocabulary',
      ]
    ];

    if (!isset($mapping[$entity_type_id])) {
      return [];
    }

    return $mapping[$entity_type_id] + [
      'rn_field_edit_route' => 'relationship_nodes.relation_' . $entity_type_id . '_field_form',
      'field_edit_form_route' => 'entity.field_config.' . $entity_type_id . '_field_edit_form',
      'field_ui_fields_route' => 'entity.' . $entity_type_id . '.field_ui_fields',
      'field_edit_local_task' => 'field_ui.fields:field_edit_'. $entity_type_id,
    ];
  }
}