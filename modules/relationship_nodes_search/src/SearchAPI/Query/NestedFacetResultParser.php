<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

use Drupal\elasticsearch_connector\SearchAPI\Query\FacetResultParser;
use Drupal\search_api\Query\QueryInterface;

/**
 * Parses facet results, including facets on nested relationship fields.
 *
 * Replaces elasticsearch_connector.facet_result_parser. Regular facets are
 * parsed by the parent class. Nested facets use the aggregation structure of
 * NestedQueryStructureBuilder::buildNestedAggregation(), which the parent
 * class cannot read, and are counted per indexed item (reverse_nested)
 * instead of per relation.
 */
class NestedFacetResultParser extends FacetResultParser {

  /**
   * {@inheritdoc}
   */
  public function parseFacetResult(QueryInterface $query, array $response): array {
    $facet_data = parent::parseFacetResult($query, $response);

    foreach (array_keys($query->getOption('search_api_facets', [])) as $facet_id) {
      $nested = $response['aggregations'][$facet_id . '_filtered'][$facet_id . '_nested'] ?? NULL;
      if ($nested === NULL) {
        continue;
      }
      $facet_data[$facet_id] = array_map(fn(array $bucket): array => [
        'count' => $bucket['parents']['doc_count'] ?? $bucket['doc_count'] ?? 0,
        'filter' => empty($bucket['key']) ? '!' : sprintf('"%s"', $bucket['key']),
      ], $nested[$facet_id]['buckets'] ?? []);
    }

    return $facet_data;
  }

}
