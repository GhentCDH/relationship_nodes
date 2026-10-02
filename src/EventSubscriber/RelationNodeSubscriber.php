<?php

namespace Drupal\relationship_nodes\EventSubscriber;

use Drupal\node\NodeInterface;
use Drupal\entity_events\EntityEventType;
use Drupal\entity_events\Event\EntityEvent;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationTitleGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets the automatic titles of relation nodes on save.
 */
class RelationNodeSubscriber implements EventSubscriberInterface {

  /**
   * The relation title generator.
   */
  protected RelationTitleGenerator $titleGenerator;

  /**
   * Constructs a RelationNodeSubscriber object.
   *
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\RelationTitleGenerator $titleGenerator
   *   The relation title generator.
   */
  public function __construct(RelationTitleGenerator $titleGenerator) {
    $this->titleGenerator = $titleGenerator;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityEventType::PRESAVE => ['setRelationTitle'],
    ];
  }

  /**
   * Sets the title in all translations of relation nodes with auto-title.
   *
   * @param \Drupal\entity_events\Event\EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name.
   */
  public function setRelationTitle(EntityEvent $event, string $event_name): void {
    $entity = $event->getEntity();
    if ($entity instanceof NodeInterface) {
      $this->titleGenerator->applyTitles($entity);
    }
  }

}
