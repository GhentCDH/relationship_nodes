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
   * @param \Drupal\elasticsearch_connector\Event\SupportsDataTypeEvent $event
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
   * @param \Drupal\elasticsearch_connector\Event\FieldMappingEvent $event
   *   The field mapping event.
   */
  public function onFieldMapping(FieldMappingEvent $event): void {
    $sapi_fld = $event->getField();

    if ($sapi_fld->getType() !== 'relationship_nodes_search_nested_relationship') {
      return;
    }

    // Map the child fields explicitly, like elasticsearch_connector maps
    // regular fields. Otherwise Elasticsearch guesses the types from the first
    // indexed values (e.g. strings become text with a keyword subfield that
    // ignores values longer than 256 characters).
    $properties = [];
    foreach ($sapi_fld->getConfiguration()['nested_fields'] ?? [] as $child_name => $child_config) {
      $properties[$child_name] = $this->mapChildType($child_config['type'] ?? 'string');
    }
    $param = ['type' => 'nested'];
    if ($properties) {
      $param['properties'] = $properties;
    }
    $event->setParam($param);
  }

  /**
   * Maps a Search API data type to an Elasticsearch field mapping.
   *
   * Follows elasticsearch_connector's FieldMapper for the same types.
   *
   * @param string $type
   *   The Search API data type of the child field.
   *
   * @return array
   *   The Elasticsearch mapping.
   */
  protected function mapChildType(string $type): array {
    return match ($type) {
      'text' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]]],
      'integer', 'duration' => ['type' => 'integer'],
      'decimal' => ['type' => 'float'],
      'boolean' => ['type' => 'boolean'],
      'date' => ['type' => 'date', 'format' => 'strict_date_optional_time||epoch_second'],
      default => ['type' => 'keyword'],
    };
  }

}
