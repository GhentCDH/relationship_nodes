<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\node\Entity\Node;
use Drupal\relationship_nodes_search\SearchAPI\Query\NestedParentFieldConditionGroup;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Integration tests against a real Elasticsearch server.
 *
 * Skipped unless RN_ELASTICSEARCH_URL is set, e.g. to http://localhost:9200.
 * The test index uses a random server prefix and is deleted afterwards.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class SearchElasticsearchTest extends RelationshipNodesKernelTestBase {

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
   * The relationship field of the index.
   */
  protected const NESTED = 'relationship_info__rel_person_person__nested';

  /**
   * The test index.
   */
  protected IndexInterface $index;

  /**
   * Test nodes by title.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected array $nodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $url = getenv('RN_ELASTICSEARCH_URL');
    if (!$url) {
      $this->markTestSkipped('Set RN_ELASTICSEARCH_URL to run the Elasticsearch integration tests.');
    }
    parent::setUp();
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api']);

    Server::create([
      'id' => 'es',
      'name' => 'Elasticsearch',
      'backend' => 'elasticsearch',
      'backend_config' => [
        'connector' => 'standard',
        'connector_config' => ['url' => $url, 'enable_debug_logging' => FALSE],
        'advanced' => [
          'fuzziness' => 'auto',
          'prefix' => 'rntest' . mt_rand() . '_',
          'suffix' => '',
          'synonyms' => [''],
        ],
      ],
    ])->save();

    $string = fn(string $label) => ['type' => 'string', 'label' => $label];
    $reference = fn(string $field, string $target) => $string($field) + [
      'drupal_field' => ['machine_name' => $field, 'type' => 'entity_reference', 'target_type' => $target],
    ];
    $this->index = Index::create([
      'id' => 'relations',
      'name' => 'Relations',
      'server' => 'es',
      'datasource_settings' => ['entity:node' => []],
      'processor_settings' => ['relationship_indexer' => []],
      'tracker_settings' => ['default' => []],
      'options' => ['index_directly' => FALSE, 'cron_limit' => 50],
      'field_settings' => [
        'type' => [
          'label' => 'Content type',
          'datasource_id' => 'entity:node',
          'property_path' => 'type',
          'type' => 'string',
        ],
        static::NESTED => [
          'label' => 'Person relations',
          'datasource_id' => 'entity:node',
          'property_path' => 'relationship_info__rel_person_person',
          'type' => 'relationship_nodes_search_nested_relationship',
          'configuration' => [
            'nested_fields' => [
              'rn_related_entity_1' => $reference('rn_related_entity_1', 'node'),
              'rn_related_entity_2' => $reference('rn_related_entity_2', 'node'),
              'rn_relation_type' => $reference('rn_relation_type', 'taxonomy_term'),
              'calculated_related_name' => $string('Related name'),
              'calculated_relation_type_name' => $string('Relation type'),
            ],
          ],
        ],
      ],
    ]);
    $this->index->save();

    $friend = $this->createRelationType('friend');
    $colleague = $this->createRelationType('colleague');
    foreach (['Ann', 'Bob', 'Carl', 'Dan'] as $title) {
      $this->nodes[$title] = $this->createPerson($title);
    }
    $this->createRelation($this->nodes['Ann'], $this->nodes['Bob'], $friend);
    $this->createRelation($this->nodes['Ann'], $this->nodes['Carl'], $colleague);
    // An unpublished relation is not indexed.
    $this->createRelation($this->nodes['Ann'], $this->nodes['Dan'], $friend, FALSE);
    $this->reindex();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->index)) {
      $this->client()->indices()->delete(['index' => $this->esIndex(), 'ignore_unavailable' => TRUE]);
    }
    parent::tearDown();
  }

  /**
   * Returns the Elasticsearch client.
   */
  protected function client() {
    return $this->index->getServerInstance()->getBackend()->getClient();
  }

  /**
   * Returns the Elasticsearch index name (with the server prefix).
   */
  protected function esIndex(): string {
    return $this->index->getServerInstance()->getBackend()->getBackendClient()->getIndexId($this->index);
  }

  /**
   * Indexes all tracked items and makes them searchable.
   */
  protected function reindex(): void {
    $this->index = Index::load('relations');
    $this->index->indexItems();
    $this->client()->indices()->refresh(['index' => $this->esIndex()]);
  }

  /**
   * Searches persons with relationship conditions; returns their titles.
   *
   * @param array $conditions
   *   Child field conditions: [child field, value, operator].
   */
  protected function search(array $conditions): array {
    $query = $this->index->query();
    $query->addCondition('type', 'person');
    $group = new NestedParentFieldConditionGroup('AND');
    $group->setParentFieldName(static::NESTED)
      ->setIndex($this->index)
      ->setQueryBuilder($this->container->get('relationship_nodes_search.nested_query_structure_builder'));
    foreach ($conditions as [$child, $value, $operator]) {
      $group->addChildFieldCondition($child, $value, $operator);
    }
    $query->addConditionGroup($group);
    $titles = [];
    foreach ($query->execute()->getResultItems() as $item) {
      $titles[] = $item->getOriginalObject()->getValue()->label();
    }
    sort($titles);
    return $titles;
  }

  /**
   * Mapping, filters, facets, reindexing and the index rebuild.
   */
  public function testSearch(): void {
    // The relationship field has an explicit nested mapping.
    $mapping = $this->client()->indices()->getMapping(['index' => $this->esIndex()])->asArray();
    $nested = $mapping[$this->esIndex()]['mappings']['properties'][static::NESTED];
    $this->assertSame('nested', $nested['type']);
    $this->assertSame('keyword', $nested['properties']['calculated_related_name']['type']);

    // Positive, single negative and combined conditions.
    $this->assertSame(['Ann'], $this->search([['calculated_related_name', 'Bob', '=']]));
    $this->assertSame(['Bob', 'Carl', 'Dan'], $this->search([['calculated_related_name', 'Bob', '<>']]));
    $carl = ['calculated_related_name', 'Carl', '='];
    $this->assertSame([], $this->search([$carl, ['calculated_relation_type_name', 'friend', '=']]));
    $this->assertSame(['Ann'], $this->search([$carl, ['calculated_relation_type_name', 'colleague', '=']]));
    // The unpublished relation to Dan is not indexed.
    $this->assertSame([], $this->search([['calculated_related_name', 'Dan', '=']]));

    // Nested facets count items, also with another facet's filter active.
    $facet_id = static::NESTED . ':calculated_relation_type_name';
    $facet = ['field' => $facet_id, 'limit' => 10, 'operator' => 'and', 'min_count' => 1, 'missing' => FALSE];
    $query = $this->index->query();
    $query->setOption('search_api_facets', [$facet_id => $facet]);
    $buckets = array_column($query->execute()->getExtraData('search_api_facets')[$facet_id] ?? [], 'count', 'filter');
    // Ann (friend of Bob, colleague of Carl), Bob (friend), Carl (colleague).
    ksort($buckets);
    $this->assertSame(['"colleague"' => 2, '"friend"' => 2], $buckets);

    // With another facet's filter: only persons (the same), or only relation
    // nodes (no person relations).
    $facet_buckets = function (string $type) use ($facet_id, $facet): array {
      $query = $this->index->query();
      $query->setOption('search_api_facets', [$facet_id => $facet]);
      $aggs = $this->container->get('elasticsearch_connector.facet_builder')
        ->buildFacetParams($query, $this->index->getFields(), ['type' => ['term' => ['type' => $type]]]);
      $response = $this->client()->search(['index' => $this->esIndex(), 'body' => ['size' => 0, 'aggs' => $aggs]])->asArray();
      $parsed = $this->container->get('elasticsearch_connector.facet_result_parser')->parseFacetResult($query, $response);
      $buckets = array_column($parsed[$facet_id] ?? [], 'count', 'filter');
      ksort($buckets);
      return $buckets;
    };
    $this->assertSame(['"colleague"' => 2, '"friend"' => 2], $facet_buckets('person'));
    $this->assertSame([], $facet_buckets(static::RELATION_BUNDLE));

    // Renaming a related node updates the index of the nodes related to it.
    $bob = Node::load($this->nodes['Bob']->id());
    $bob->setTitle('Robert')->save();
    $this->reindex();
    $this->assertSame(['Ann'], $this->search([['calculated_related_name', 'Robert', '=']]));

    // The post update rebuilds the index with the explicit mapping.
    \Drupal::moduleHandler()->loadInclude('relationship_nodes_search', 'php', 'relationship_nodes_search.post_update');
    $sandbox = [];
    do {
      relationship_nodes_search_post_update_explicit_nested_mapping($sandbox);
    } while (($sandbox['#finished'] ?? 1) < 1);
    $this->client()->indices()->refresh(['index' => $this->esIndex()]);
    $this->assertSame(['relations'], $sandbox['indexes']);
    $this->assertSame(['Ann'], $this->search([['calculated_related_name', 'Robert', '=']]));
  }

}
