<?php

namespace Drupal\relationship_nodes\EventSubscriber;

use Drupal\node\Entity\Node;
use Drupal\entity_events\EntityEventType;
use Drupal\entity_events\Event\EntityEvent;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationSync;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Cleans up orphaned relation nodes when a target node is deleted.
 *
 * Only handles DELETE — not saves — so there is no interaction with
 * RelationNodeSubscriber (which only handles PRESAVE). The two subscribers
 * operate on different events and cannot trigger each other.
 */
class TargetNodeSubscriber implements EventSubscriberInterface {

  protected RelationInfo $nodeInfoService;
  protected RelationSync $syncService;


  /**
   * Constructs a TargetNodeSubscriber object.
   *
   * @param RelationInfo $nodeInfoService
   *   The node info service.
   * @param RelationSync $syncService
   *   The sync service.
   */
  public function __construct(
    RelationInfo $nodeInfoService, 
    RelationSync $syncService
  ) {
    $this->nodeInfoService = $nodeInfoService;
    $this->syncService = $syncService;
  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityEventType::DELETE => ['deleteOrphanedRelations']
    ];
  }


  /**
   * Deletes orphaned relation nodes when target nodes are deleted.
   *
   * @param EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name.
   */
  public function deleteOrphanedRelations(EntityEvent $event, string $event_name): void {
    $entity = $event->getEntity();
    if (!($entity instanceof Node)) {
      return;
    }
    $relations_per_type = $this->nodeInfoService->getAllReferencingRelations($entity) ?? [];
    if (!is_array($relations_per_type) || empty($relations_per_type)) {
      return;
    }
    $relation_ids = [];
    foreach ($relations_per_type as $relations) {
      $relation_ids = array_merge($relation_ids, array_keys($relations));
    }
    $this->syncService->deleteNodes($relation_ids);
  }
}
