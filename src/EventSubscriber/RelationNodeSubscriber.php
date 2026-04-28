<?php

namespace Drupal\relationship_nodes\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\entity_events\EntityEventType;
use Drupal\entity_events\Event\EntityEvent;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 * Event subscriber for relation node operations.
 */
class RelationNodeSubscriber implements EventSubscriberInterface {

  protected EntityTypeManagerInterface $entityTypeManager;  
  protected BundleSettingsManager $settingsManager;
  protected RelationInfo $nodeInfoService;


  /**
   * Constructs a RelationNodeSubscriber object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager service.
   * @param RelationInfo $nodeInfoService
   *   The node info service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    BundleSettingsManager $settingsManager,
    RelationInfo $nodeInfoService
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->settingsManager = $settingsManager;
    $this->nodeInfoService = $nodeInfoService;
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
   * Sets the title for relation nodes automatically.
   *
   * @param EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name.
   */
  public function setRelationTitle(EntityEvent $event, string $event_name): void {
    $entity = $event->getEntity();

    if (!$entity instanceof Node) {
      return;
    }
    
    $bundle = $this->entityTypeManager->getStorage('node_type')->load($entity->bundle());
    $bundle_info = $this->settingsManager->getBundleInfo($bundle);
    if (
      !$bundle_info || !$bundle_info->isRelation() ||!$bundle_info->hasAutoTitle()) {
      return;
    }

    $entity->set('title', $this->generateRelationLabel($entity));
    
  }

  
  /**
   * Generates a label for a relation node.
   *
   * @param Node $relation_node
   *   The relation node.
   *
   * @return string
   *   The generated label.
   */
  private function generateRelationLabel(Node $relation_node): string {
    $related_entities = $this->nodeInfoService->getRelatedEntityValues($relation_node);
    if (empty($related_entities)) {
      return 'Relationship (no entities)';
    }
    $title_parts = [];
    $node_storage = $this->entityTypeManager->getStorage('node');
    foreach ($related_entities as $field_values) {
      $node_titles = [];
      foreach ($field_values as $nid) {
        $node = $node_storage->load($nid);
        if ($node instanceof Node) {
          $node_titles[] = $node->getTitle();
        }
      }
      if (!empty($node_titles)) {
        $title_parts[] = implode(', ', $node_titles);
      }
    }
    return 'Relationship '  . implode(' - ', $title_parts);
  }
}