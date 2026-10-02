<?php

namespace Drupal\relationship_nodes\EventSubscriber;

use Drupal\node\Entity\Node;
use Drupal\entity_events\EntityEventType;
use Drupal\entity_events\Event\EntityEvent;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationSync;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationTitleGenerator;
use Drupal\relationship_nodes\Entity\OriginalEntityTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps relation nodes in line with the nodes they relate.
 *
 * - When a node is deleted, its relation nodes are deleted.
 * - When a node's title changes, the automatic titles of its relation nodes
 *   are updated. Relation nodes themselves are skipped, so saving them does
 *   not trigger this again.
 */
class TargetNodeSubscriber implements EventSubscriberInterface {

  use OriginalEntityTrait;

  protected RelationInfo $nodeInfoService;
  protected RelationSync $syncService;
  protected RelationTitleGenerator $titleGenerator;


  /**
   * Constructs a TargetNodeSubscriber object.
   *
   * @param RelationInfo $nodeInfoService
   *   The node info service.
   * @param RelationSync $syncService
   *   The sync service.
   * @param RelationTitleGenerator $titleGenerator
   *   The relation title generator.
   */
  public function __construct(
    RelationInfo $nodeInfoService, 
    RelationSync $syncService,
    RelationTitleGenerator $titleGenerator
  ) {
    $this->nodeInfoService = $nodeInfoService;
    $this->syncService = $syncService;
    $this->titleGenerator = $titleGenerator;
  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityEventType::DELETE => ['deleteOrphanedRelations'],
      EntityEventType::UPDATE => ['updateRelationTitles'],
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


  /**
   * Updates the automatic titles of a node's relations after a rename.
   *
   * @param EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name.
   */
  public function updateRelationTitles(EntityEvent $event, string $event_name): void {
    $entity = $event->getEntity();
    if (!$entity instanceof Node || $this->nodeInfoService->getRelatedEntityValues($entity) !== NULL) {
      return;
    }
    $original = $this->getOriginalEntity($entity);
    if (!$original instanceof Node || !$this->labelsChanged($entity, $original)) {
      return;
    }
    foreach ($this->nodeInfoService->getAllReferencingRelations($entity) ?? [] as $relations) {
      foreach ($relations as $relation) {
        if ($this->titleGenerator->applyTitles($relation)) {
          $relation->save();
        }
      }
    }
  }


  /**
   * Checks whether the title of a node changed in any translation.
   *
   * @param Node $entity
   *   The saved node.
   * @param Node $original
   *   The node before the save.
   *
   * @return bool
   *   TRUE if a title changed, or a translation was added or removed.
   */
  protected function labelsChanged(Node $entity, Node $original): bool {
    $new = array_keys($entity->getTranslationLanguages());
    $old = array_keys($original->getTranslationLanguages());
    if (array_diff($new, $old) || array_diff($old, $new)) {
      return TRUE;
    }
    foreach ($new as $langcode) {
      if ($entity->getTranslation($langcode)->label() !== $original->getTranslation($langcode)->label()) {
        return TRUE;
      }
    }
    return FALSE;
  }
}
