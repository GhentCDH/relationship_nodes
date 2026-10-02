<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\relationship_nodes_search\SearchAPI\Query\NestedParentFieldConditionGroup;
use Drupal\search_api\Query\ConditionGroup;
use Drupal\search_api\Query\QueryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Elasticsearch queries built for relationship fields.
 *
 * No Elasticsearch server is needed: the tests check the generated query
 * structures, and the mapping comes from a stub.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class SearchQueryTest extends SearchKernelTestBase {

  /**
   * Builds the Elasticsearch filter for a relationship filter.
   *
   * @param array $conditions
   *   Child field conditions: [child field, value, operator].
   */
  protected function buildFilter(array $conditions): array {
    $group = new NestedParentFieldConditionGroup('AND');
    $group->setParentFieldName(static::NESTED)
      ->setIndex($this->index)
      ->setQueryBuilder($this->container->get('relationship_nodes_search.nested_query_structure_builder'));
    foreach ($conditions as [$child, $value, $operator]) {
      $group->addChildFieldCondition($child, $value, $operator);
    }
    $root = new ConditionGroup('AND');
    $root->addConditionGroup($group);
    $result = $this->container->get('elasticsearch_connector.query_filter_builder')
      ->buildFilters($root, $this->index->getFields());
    return $result['filters'];
  }

  /**
   * Positive, negative and combined relationship filters.
   */
  public function testFilters(): void {
    $path = static::NESTED . '.calculated_related_name';

    // One relation matches.
    $filter = $this->buildFilter([['calculated_related_name', 'Ann', '=']]);
    $this->assertSame(static::NESTED, $filter['nested']['path']);
    $this->assertSame([['term' => [$path => 'Ann']]], $filter['nested']['query']['bool']['must'] ?? [$filter['nested']['query']]);

    // A single negative condition: no relation matches.
    $this->assertEquals([
      'bool' => [
        'must_not' => [
        ['nested' => ['path' => static::NESTED, 'query' => ['term' => [$path => 'Ann']]]],
        ],
      ],
    ], $this->buildFilter([['calculated_related_name', 'Ann', '<>']]));

    // Combined conditions: one relation that matches all of them.
    $filter = $this->buildFilter([
      ['calculated_related_name', 'Ann', '='],
      ['calculated_relation_type_name', 'friend', '<>'],
    ]);
    $this->assertSame(static::NESTED, $filter['nested']['path']);
    $this->assertCount(2, $filter['nested']['query']['bool']['must']);
  }

  /**
   * Nested facets: filter > nested > terms, counted per item.
   */
  public function testFacets(): void {
    $facet_id = static::NESTED . ':calculated_related_name';
    // The test index has no server, so it is disabled and cannot create
    // queries; the facet builder and parser only read these two.
    $facets = [
      $facet_id => ['field' => $facet_id, 'limit' => 5, 'operator' => 'and', 'min_count' => 1, 'missing' => FALSE],
    ];
    $query = $this->createMock(QueryInterface::class);
    $query->method('getOption')->willReturnCallback(fn($name, $default = NULL) => $name === 'search_api_facets' ? $facets : $default);
    $query->method('getIndex')->willReturn($this->index);
    $post_filter = ['term' => ['type' => 'person']];
    $aggs = $this->container->get('elasticsearch_connector.facet_builder')
      ->buildFacetParams($query, $this->index->getFields(), ['type' => $post_filter]);

    $filtered = $aggs[$facet_id . '_filtered'];
    $this->assertEquals($post_filter, $filtered['filter']);
    $nested = $filtered['aggs'][$facet_id . '_nested'];
    $this->assertSame(static::NESTED, $nested['nested']['path']);
    $this->assertSame(static::NESTED . '.calculated_related_name', $nested['aggs'][$facet_id]['terms']['field']);
    $this->assertArrayHasKey('reverse_nested', $nested['aggs'][$facet_id]['aggs']['parents']);

    // The parser reads this structure and uses the per-item counts.
    $response = [
      'aggregations' => [
        $facet_id . '_filtered' => [
          $facet_id . '_nested' => [
            $facet_id => [
              'buckets' => [
            ['key' => 'Ann', 'doc_count' => 3, 'parents' => ['doc_count' => 2]],
              ],
            ],
          ],
        ],
      ],
    ];
    $parsed = $this->container->get('elasticsearch_connector.facet_result_parser')->parseFacetResult($query, $response);
    $this->assertSame([['count' => 2, 'filter' => '"Ann"']], $parsed[$facet_id]);
  }

  /**
   * Operators from older configurations map to working operators.
   */
  public function testLegacyOperators(): void {
    $operators = $this->container->get('relationship_nodes_search.filter_operator_helper');
    $this->assertSame('<>', $operators->sanitizeOperator('!='));
    $this->assertSame('=', $operators->sanitizeOperator('IN'));
    $this->assertSame('=', $operators->sanitizeOperator('bogus'));
    $this->assertArrayNotHasKey('BETWEEN', $operators->getRangeOperatorOptions());
  }

  /**
   * Only published relations to published nodes are indexed.
   */
  public function testIndexedValues(): void {
    $a = $this->createPerson('A');
    $b = $this->createPerson('B');
    $hidden = $this->createPerson('Hidden', FALSE);
    $alone = $this->createPerson('Alone');
    $this->createRelation($a, $b);
    $this->createRelation($a, $b, NULL, FALSE);
    $this->createRelation($a, $hidden);

    $names = function ($node): array {
      $id = 'entity:node/' . $node->id() . ':en';
      $objects = $this->index->loadItemsMultiple([$id]);
      $item = $this->container->get('search_api.fields_helper')->createItemFromObject($this->index, $objects[$id], $id);
      $values = $item->getField(static::NESTED)->getValues();
      return array_column($values, 'calculated_related_name');
    };
    $this->assertSame(['B'], $names($a));
    $this->assertSame(['A'], $names($b));
    $this->assertSame([], $names($alone));
  }

}
