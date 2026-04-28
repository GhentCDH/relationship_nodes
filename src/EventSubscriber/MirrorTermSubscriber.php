<?php

namespace Drupal\relationship_nodes\EventSubscriber;

use Drupal\taxonomy\TermInterface;
use Drupal\entity_events\EntityEventType;
use Drupal\entity_events\Event\EntityEvent;
use Drupal\relationship_nodes\RelationData\TermHelper\MirrorSync;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 * Event subscriber for mirror term operations.
 *
 * Handles automatic synchronization of mirror taxonomy terms.
 */
class MirrorTermSubscriber implements EventSubscriberInterface {

  protected MirrorSync $mirrorUpdater;
  protected BundleSettingsManager $settingsManager;


  /**
   * Constructs a MirrorTermSubscriber object.
   *
   * @param MirrorSync $mirrorUpdater
   *   The mirror updater service.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager service.
   */
  public function __construct(
    MirrorSync $mirrorUpdater,
    BundleSettingsManager $settingsManager
  ) {
    $this->mirrorUpdater = $mirrorUpdater;
    $this->settingsManager = $settingsManager;
  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityEventType::INSERT => ['addMirrorLogic'],
      EntityEventType::UPDATE => ['addMirrorLogic'],
      EntityEventType::DELETE => ['addMirrorLogic'],
    ];
  }


  /**
   * Adds mirror logic when terms are created, updated, or deleted.
   *
   * @param EntityEvent $event
   *   The entity event.
   * @param string $event_name
   *   The event name.
   */
  public function addMirrorLogic(EntityEvent $event, string $event_name): void {
    $term = $event->getEntity();
    if (!$term instanceof TermInterface) {
      return;      
    }

    $bundle_info = $this->settingsManager->getBundleInfo($term->bundle());    
    if (!$bundle_info || !$bundle_info->isRelation() || $bundle_info->getMirrorType() !== 'entity_reference') {
        return;
    }
    $hook = $this->mapEventNameToHook($event_name);
    if ($hook === null) {
      return;
    }
    $this->mirrorUpdater->setMirrorTermLink($term, $hook);
  }


  /**
   * Maps event name to hook name.
   *
   * @param string $event_name
   *   The event name.
   *
   * @return string|null
   *   The hook name or NULL.
   */
  private function mapEventNameToHook(string $event_name): ?string {
    return match ($event_name) {
      EntityEventType::INSERT => 'insert',
      EntityEventType::UPDATE => 'update',
      EntityEventType::DELETE => 'delete',
      default => null,
    };
  }
}
