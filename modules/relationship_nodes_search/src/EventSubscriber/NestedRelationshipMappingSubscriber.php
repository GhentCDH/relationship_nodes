<?php

namespace Drupal\relationship_nodes_search\EventSubscriber;

use Drupal\elasticsearch_connector\Event\FieldMappingEvent;
use Drupal\elasticsearch_connector\Event\SupportsDataTypeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 * Event subscriber for Elasticsearch nested relationship field mapping.
 *
 * Configures Elasticsearch to use 'nested' type for relationship fields,
 * enabling nested object queries and aggregations on relationship data.
 */
class NestedRelationshipMappingSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
        FieldMappingEvent::class => 'onFieldMapping',
        SupportsDataTypeEvent::class => 'onSupportsDataType',
    ];
  }


  /**
   * Marks relationship_nodes_search_nested_relationship as supported.
   *
   * @param SupportsDataTypeEvent $event
   *   The supports data type event.
   */
  public function onSupportsDataType(SupportsDataTypeEvent $event): void {
    if ($event->getType() !== 'relationship_nodes_search_nested_relationship') {
      return;
    }

    $event->setIsSupported(TRUE);
  }


  /**
   * Maps relationship fields to Elasticsearch nested type.
   *
   * @param FieldMappingEvent $event
   *   The field mapping event.
   */
  public function onFieldMapping(FieldMappingEvent $event): void {
    $sapi_fld = $event->getField();
    
    if ($sapi_fld->getType() !== 'relationship_nodes_search_nested_relationship') {
      return;
    }

    // Map to Elasticsearch nested type for relationship data.
    // Properties are automatically mapped from nested field configuration.
    $event->setParam([
      'type' => 'nested',
    ]);
  }
}