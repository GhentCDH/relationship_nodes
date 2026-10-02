<?php

namespace Drupal\relationship_nodes_search\EventSubscriber;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\entity_events\EntityEventType;
use Drupal\entity_events\Event\EntityEvent;
use Drupal\node\Entity\Node;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;
use Drupal\relationship_nodes\Entity\OriginalEntityTrait;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber that triggers Search API reindexing for relationship changes.
 *
 * The relationship indexer copies data of relation nodes, of the nodes on
 * the other side and of relation type terms into the index of each related
 * node. These nodes are marked for reindexing when:
 * - a relation node is created, updated, or deleted;
 * - a related node's title or published status changes (a deleted node's
 *   relations are deleted, which is covered by the first case);
 * - a relation type term's name or mirror changes.
 */
class ReindexTargetsOnRelationUpdate implements EventSubscriberInterface {

  use OriginalEntityTrait;

  protected EntityTypeManagerInterface $entityTypeManager;
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected BundleSettingsManager $settingsManager;
  protected RelationInfo $nodeInfoService;
  protected FieldNameResolver $fieldNameResolver;

  /**
   * Constructs a ReindexTargetsOnRelationUpdate object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager $settingsManager
   *   The relation bundle settings manager.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo $nodeInfoService
   *   The relation node info service.
   * @param \Drupal\relationship_nodes\RelationField\FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    LoggerChannelFactoryInterface $loggerFactory,
    BundleSettingsManager $settingsManager,
    RelationInfo $nodeInfoService,
    FieldNameResolver $fieldNameResolver,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
    $this->loggerFactory = $loggerFactory;
    $this->settingsManager = $settingsManager;
    $this->nodeInfoService = $nodeInfoService;
    $this->fieldNameResolver = $fieldNameResolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityEventType::INSERT => ['trackRelatedEntitiesForReindexing'],
      EntityEventType::UPDATE => [
        ['trackRelatedEntitiesForReindexing'],
        ['trackTargetNodeChanges'],
        ['trackRelationTypeChanges'],
      ],
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
   * @param \Drupal\entity_events\Event\EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name (INSERT, UPDATE, or PREDELETE).
   */
  public function trackRelatedEntitiesForReindexing(EntityEvent $event, string $event_name): void {
    // Only process if entity is a recognized relation node type.
    $entity = $event->getEntity();
    $bundleInfo = $this->settingsManager->getBundleInfo($entity->bundle(), 'node');
    if (!$entity instanceof Node || !$bundleInfo || !$bundleInfo->isRelation()) {
      return;
    }

    // Get the currently related entity IDs from this relation node.
    $related_entity_values = $this->nodeInfoService->getRelatedEntityValues($entity);
    // Collect IDs from both old and new entity references for reindexing.
    $all_ids = [];

    // If this is an UPDATE event, include IDs from the original entity as well.
    $original = $this->getOriginalEntity($entity);
    if ($event_name === EntityEventType::UPDATE && $original instanceof Node) {
      $old_values = $this->nodeInfoService->getRelatedEntityValues($original) ?? [];
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

    $sapi_count = $this->reindexNodes($unique_ids);
    if (!$sapi_count) {
      return;
    }

    // Invalidate cache for this specific relation bundle.
    $relation_bundle = $entity->bundle();
    $this->invalidateRelationshipCache($relation_bundle, $unique_ids);
    $this->logReindexOperation($event_name, $entity->id(), $relation_bundle, $sapi_count);
  }

  /**
   * Reindexes the nodes related to a node whose title or status changed.
   *
   * Their index documents contain this node's title, and only contain
   * relations to it while it is published.
   *
   * @param \Drupal\entity_events\Event\EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name.
   */
  public function trackTargetNodeChanges(EntityEvent $event, string $event_name): void {
    $entity = $event->getEntity();
    if (!$entity instanceof Node) {
      return;
    }
    $bundle_info = $this->settingsManager->getBundleInfo($entity->bundle(), 'node');
    if ($bundle_info && $bundle_info->isRelation()) {
      return;
    }
    $original = $this->getOriginalEntity($entity);
    if (!$original instanceof Node || !$this->labelOrStatusChanged($entity, $original)) {
      return;
    }

    $nids = [];
    foreach ($this->nodeInfoService->getAllReferencingRelations($entity) ?? [] as $relations) {
      foreach ($relations as $relation) {
        $nids = array_merge($nids, $this->getRelatedNodeIds($relation));
      }
    }
    // The node itself is reindexed by Search API.
    $nids = array_diff(array_unique($nids), [$entity->id()]);
    $this->reindexNodes($nids);
  }

  /**
   * Reindexes both sides of relations whose relation type term changed.
   *
   * The index contains the relation type's name, and on the reverse side
   * the name of its mirror, so relations typed with the term's mirror are
   * included as well.
   *
   * @param \Drupal\entity_events\Event\EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name.
   */
  public function trackRelationTypeChanges(EntityEvent $event, string $event_name): void {
    $term = $event->getEntity();
    if (!$term instanceof TermInterface) {
      return;
    }
    $bundle_info = $this->settingsManager->getBundleInfo($term->bundle(), 'taxonomy_term');
    if (!$bundle_info || !$bundle_info->isRelation()) {
      return;
    }
    $original = $this->getOriginalEntity($term);
    $mirror_fields = array_filter(array_values((array) $this->fieldNameResolver->getMirrorFields()));
    if (!$original instanceof TermInterface || !$this->labelOrStatusChanged($term, $original, $mirror_fields)) {
      return;
    }

    $term_ids = [(int) $term->id()];
    $mirror_ref = $this->fieldNameResolver->getMirrorFields('entity_reference');
    foreach ([$term, $original] as $version) {
      if ($mirror_ref && $version->hasField($mirror_ref) && !$version->get($mirror_ref)->isEmpty()) {
        $term_ids[] = (int) $version->get($mirror_ref)->target_id;
      }
    }

    $relation_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition($this->fieldNameResolver->getRelationTypeField(), array_unique($term_ids), 'IN')
      ->execute();
    if (empty($relation_ids)) {
      return;
    }

    $nids = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($relation_ids) as $relation) {
      $nids = array_merge($nids, $this->getRelatedNodeIds($relation));
    }
    $this->reindexNodes(array_unique($nids));
  }

  /**
   * Checks whether an entity's label, status or given fields changed.
   *
   * All translations are compared, including added or removed ones.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The updated entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface $original
   *   The entity before the update.
   * @param string[] $fields
   *   Additional field names to compare.
   *
   * @return bool
   *   TRUE if something the index depends on changed.
   */
  protected function labelOrStatusChanged(ContentEntityInterface $entity, ContentEntityInterface $original, array $fields = []): bool {
    $langcodes = array_unique(array_merge(
      array_keys($entity->getTranslationLanguages()),
      array_keys($original->getTranslationLanguages())
    ));
    foreach ($langcodes as $langcode) {
      if (!$entity->hasTranslation($langcode) || !$original->hasTranslation($langcode)) {
        return TRUE;
      }
      $new = $entity->getTranslation($langcode);
      $old = $original->getTranslation($langcode);
      if ($new->label() !== $old->label()) {
        return TRUE;
      }
      if ($new instanceof EntityPublishedInterface && $old instanceof EntityPublishedInterface && $new->isPublished() !== $old->isPublished()) {
        return TRUE;
      }
      foreach ($fields as $field) {
        if ($new->hasField($field) && !$new->get($field)->equals($old->get($field))) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Returns the IDs of the nodes on both sides of a relation.
   *
   * @param \Drupal\node\Entity\Node $relation
   *   The relation node.
   *
   * @return int[]
   *   The node IDs.
   */
  protected function getRelatedNodeIds(Node $relation): array {
    $nids = [];
    foreach ($this->nodeInfoService->getRelatedEntityValues($relation) ?? [] as $ids) {
      if (!empty($ids) && is_array($ids)) {
        $nids = array_merge($nids, $ids);
      }
    }
    return $nids;
  }

  /**
   * Marks nodes for reindexing in all their translations.
   *
   * @param array $nids
   *   Node IDs.
   *
   * @return int
   *   The number of Search API items marked.
   */
  protected function reindexNodes(array $nids): int {
    if (empty($nids)) {
      return 0;
    }
    // Search API IDs are strings in the format "nid:langcode" (e.g., "101:en").
    $sapi_ids = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $nid => $node) {
      foreach (array_keys($node->getTranslationLanguages()) as $language_code) {
        $sapi_ids[] = $nid . ':' . $language_code;
      }
    }
    if ($sapi_ids) {
      $this->trackItemsInIndexes($sapi_ids);
    }
    return count($sapi_ids);
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
      // Only indexes that contain relationship data need reindexing.
      if (!$index->status() || !$index->isValidDatasource('entity:node') || !$index->isValidProcessor('relationship_indexer')) {
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
    // Invalidate general relationship options cache.
    $cache_tags = ['relationship_filter_options'];

    // Add specific tags for this relation bundle.
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
