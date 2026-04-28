<?php

namespace Drupal\relationship_nodes_search\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\entity_events\EntityEventType;
use Drupal\entity_events\Event\EntityEvent;
use Drupal\node\Entity\Node;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 * Event subscriber that triggers Search API reindexing for relationship changes.
 *
 * When a relation node (linking two entities) is created, updated, or deleted,
 * this subscriber ensures that all affected target nodes are marked for
 * reindexing in Search API.
 */
class ReindexTargetsOnRelationUpdate implements EventSubscriberInterface {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected BundleSettingsManager $settingsManager;
  protected RelationInfo $nodeInfoService;


  /**
   * Constructs a ReindexTargetsOnRelationUpdate object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param BundleSettingsManager $settingsManager
   *   The relation bundle settings manager.
   * @param RelationInfo $nodeInfoService
   *   The relation node info service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    LoggerChannelFactoryInterface $loggerFactory,
    BundleSettingsManager $settingsManager,
    RelationInfo $nodeInfoService
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
    $this->loggerFactory = $loggerFactory;
    $this->settingsManager = $settingsManager;
    $this->nodeInfoService = $nodeInfoService;
  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityEventType::INSERT => ['trackRelatedEntitiesForReindexing'],
      EntityEventType::UPDATE => ['trackRelatedEntitiesForReindexing'],
      EntityEventType::PREDELETE => ['trackRelatedEntitiesForReindexing'],
    ];
  }


  /**
   * Tracks related entities for Search API reindexing.
   *
   * When a relation node changes, identifies all affected target entities
   * and marks them for reindexing. For UPDATE events, includes both old
   * and new target entities.
   *
   * @param EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name (INSERT, UPDATE, or PREDELETE).
   */
  public function trackRelatedEntitiesForReindexing(EntityEvent $event, string $event_name): void {
    // Only process if entity is a recognized relation node type.
    $entity = $event->getEntity();
    $bundleInfo = $this->settingsManager->getBundleInfo($entity->bundle());
    if (!$entity instanceof Node || !$bundleInfo || !$bundleInfo->isRelation()) {
      return;
    }

    // Get the currently related entity IDs from this relation node.
    $related_entity_values = $this->nodeInfoService->getRelatedEntityValues($entity);
    // Collect IDs from both old and new entity references for reindexing.
    $all_ids = [];

    // If this is an UPDATE event, include IDs from the original entity as well.
    if ($event_name === EntityEventType::UPDATE && property_exists($entity, 'original')) {
      $old_values = $this->nodeInfoService->getRelatedEntityValues($entity->original) ?? [];
      foreach ($old_values as $ids) {
        if (!empty($ids) && is_array($ids)) {
          $all_ids = array_merge($all_ids, $ids);
        }
      }
    }


    // Merge the new/current related IDs.
    if (!empty($related_entity_values)) {
      foreach ($related_entity_values as $ids) {
        if (!empty($ids) && is_array($ids)) {
          $all_ids = array_merge($all_ids, $ids);
        }
      }
    }

    // Remove duplicates and ensure we have IDs to reindex.
    $unique_ids = array_unique($all_ids);
    if (empty($unique_ids)) {
      return;
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $target_nodes = $node_storage->loadMultiple($unique_ids);

    // Search API IDs are strings in the format "nid:langcode" (e.g., "101:en").
    $sapi_ids = [];
    foreach ($target_nodes as $nid => $target_node) {
      // Get the languages that are available for a specific target node.
      $node_languages = array_keys($target_node->getTranslationLanguages());
      foreach ($node_languages as $language_code) {
        $sapi_ids[] = $nid . ':' . $language_code;
      }
    }

    if (empty($sapi_ids)) {
      return;
    }

    $this->trackItemsInIndexes($sapi_ids);

    // Invalidate cache for this specific relation bundle.
    $relation_bundle = $entity->bundle();
    $this->invalidateRelationshipCache($relation_bundle, $unique_ids);
    $this->logReindexOperation($event_name, $entity->id(), $relation_bundle, count($sapi_ids));
  }


  /**
   * Tracks items in all active Search API indexes.
   *
   * @param array $sapi_ids
   *   Array of Search API item IDs (format: 'nid:langcode').
   */
  protected function trackItemsInIndexes(array $sapi_ids): void {
    $index_storage = $this->entityTypeManager->getStorage('search_api_index');
    $indexes = $index_storage->loadMultiple();

    foreach ($indexes as $index) {
      if (!$index->status() || !$index->isValidDatasource('entity:node')) {
        continue;
      }
      $index->trackItemsUpdated('entity:node', $sapi_ids);
    }
  }


  /**
   * Invalidates dropdown option caches for affected relationships.
   *
   * @param string $relation_bundle
   *   The relation bundle machine name.
   * @param array $affected_node_ids
   *   Array of affected node IDs.
   */
  protected function invalidateRelationshipCache(string $relation_bundle, array $affected_node_ids): void {
    // Invalidate general relationship options cache
    $cache_tags = ['relationship_filter_options'];
    
    // Add specific tags for this relation bundle
    $cache_tags[] = 'relationship_filter_options:' . $relation_bundle;
    
    // Add tags for affected nodes (if they have relationship fields displayed)
    foreach ($affected_node_ids as $nid) {
      $cache_tags[] = 'relationship_filter_options:node:' . $nid;
    }
    
    $this->cacheTagsInvalidator->invalidateTags($cache_tags);
  }


  /**
   * Logs reindex operation for debugging.
   *
   * @param string $event_type
   *   The event type (INSERT, UPDATE, or PREDELETE).
   * @param int $relation_id
   *   The relation node ID.
   * @param string $bundle
   *   The relation bundle machine name.
   * @param int $affected_count
   *   Number of affected Search API items.
   */
  protected function logReindexOperation(string $event_type, int $relation_id, string $bundle, int $affected_count): void {
    $this->loggerFactory->get('relationship_nodes_search')->info(
      'Reindexing triggered by @event on relation node @id (bundle: @bundle). Affected items: @count',
      [
        '@event' => $event_type,
        '@id' => $relation_id,
        '@bundle' => $bundle,
        '@count' => $affected_count,
      ]
    );
  }
}