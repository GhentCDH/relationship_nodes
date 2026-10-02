<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\relationship_nodes\Kernel\Stub\KeywordMappingInspector;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Sets up a Search API index with a relationship field, without a server.
 *
 * No Elasticsearch server is needed: the mapping comes from a stub that
 * reports the child fields as keyword fields.
 */
abstract class SearchKernelTestBase extends RelationshipNodesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_ui',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'search_api',
    'facets',
    'facets_exposed_filters',
    'better_exposed_filters',
    'elasticsearch_connector',
    'entity_events',
    'inline_entity_form',
    'relationship_nodes',
    'relationship_nodes_search',
  ];

  /**
   * The nested relationship field of the test index.
   */
  protected const NESTED = 'relationship_info__rel_person_person__nested';

  /**
   * The test index.
   */
  protected IndexInterface $index;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->register('relationship_nodes_search.elastic_mapping_inspector', KeywordMappingInspector::class)
      ->addArgument($container->getDefinition('logger.factory') ? new Reference('logger.factory') : NULL);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api']);

    $child = fn(string $label) => ['type' => 'string', 'label' => $label];
    // The related entity fields, from which the related name is derived.
    $reference = fn(string $field) => $child($field) + [
      'drupal_field' => ['machine_name' => $field, 'type' => 'entity_reference', 'target_type' => 'node'],
    ];
    $this->index = Index::create([
      'id' => 'test_index',
      'name' => 'Test index',
      'datasource_settings' => ['entity:node' => []],
      'processor_settings' => ['relationship_indexer' => []],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        static::NESTED => [
          'label' => 'Person relations',
          'datasource_id' => 'entity:node',
          'property_path' => 'relationship_info__rel_person_person',
          'type' => 'relationship_nodes_search_nested_relationship',
          'configuration' => [
            'nested_fields' => [
              'rn_related_entity_1' => $reference('rn_related_entity_1'),
              'rn_related_entity_2' => $reference('rn_related_entity_2'),
              'calculated_related_name' => $child('Related name'),
              'calculated_relation_type_name' => $child('Relation type'),
            ],
          ],
        ],
      ],
    ]);
    $this->index->save();
  }

}
