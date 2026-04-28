<?php

namespace Drupal\relationship_nodes_search\QueryHelper;

use Drupal\search_api\Query\ResultSetInterface;

/**
 * Service for parsing Elasticsearch nested facet results.
 *
 * Handles extraction and normalization of facet data from Elasticsearch
 * aggregation responses and Search API results. "Nested" refers to facets
 * on fields within Elasticsearch nested objects (relationship data).
 */
class NestedFacetResultParser {


  /**
   * Extracts facet values from Search API results.
   *
   * @param \Drupal\search_api\Query\ResultSetInterface $results
   *   The search results.
   * @param string $field_id
   *   The field identifier.
   *
   * @return array
   *   Raw facet values.
   */
  public function extractFacetValues(ResultSetInterface $results, string $field_id): array {
    $facets = $results->getExtraData('search_api_facets', []);
    
    if (empty($facets[$field_id])) {
      return [];
    }
    
    return array_column($facets[$field_id], 'filter');
  }


  /**
   * Extract and clean facet values from Search API results.
   *
   * Combines extraction with trimming of surrounding quotes.
   *
   * @param ResultSetInterface $results
   *   The search results.
   * @param string $field_id
   *   The field identifier.
   *
   * @return array
   *   Cleaned facet values.
   */
  public function extractTrimmedFacetValues(ResultSetInterface $results, string $field_id): array {
    $facet_data = $this->extractFacetValues($results, $field_id);
    return $this->trimQuotes($facet_data);
  }


  /**
   * Extracts unique values from aggregation buckets.
   *
   * @param array $buckets
   *   Array of Elasticsearch bucket objects.
   *
   * @return array
   *   Array of unique values (keys).
  */
  public function getUniqueValues(array $buckets): array {
    return array_column($buckets, 'key');
  }


  /**
   * Remove surrounding quotes from facet values.
   *
   * Elasticsearch sometimes returns string values wrapped in quotes.
   * This method strips those quotes for cleaner display.
   *
   * @param array $facet_data
   *   Array of facet result strings.
   *
   * @return array
   *   Cleaned facet results.
   */
  protected function trimQuotes(array $facet_data): array {
    foreach ($facet_data as $key => $result) {
      if (is_string($result) && strlen($result) >= 2 && str_starts_with($result, '"') && str_ends_with($result, '"')) {
        $facet_data[$key] = substr($result, 1, -1);
      }
    }
    return $facet_data;
  }
}