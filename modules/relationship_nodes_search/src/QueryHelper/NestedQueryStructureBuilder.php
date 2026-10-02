<?php

namespace Drupal\relationship_nodes_search\QueryHelper;

use Drupal\search_api\Entity\Index;

/**
 * Service for building Elasticsearch nested aggregations.
 *
 * Handles the complexity of creating aggregations for nested fields,
 * ensuring proper field paths and structure for Elasticsearch queries.
 */
class NestedQueryStructureBuilder {

  protected ElasticMappingInspector $mappingInspector;

  /**
   * Constructs a NestedQueryStructureBuilder object.
   *
   * @param \Drupal\relationship_nodes_search\QueryHelper\ElasticMappingInspector $mappingInspector
   *   The Elasticsearch mapping inspector service.
   */
  public function __construct(ElasticMappingInspector $mappingInspector) {
    $this->mappingInspector = $mappingInspector;
  }

  /**
   * Builds a terms aggregation on a field of nested relationship objects.
   *
   * Structure, matching what NestedFacetResultParser reads:
   * @code
   *   {facet}_filtered: filter (other facets' filters, on the indexed items)
   *     {facet}_nested: nested (path: the relationship field)
   *       {facet}: terms (the child field)
   *         parents: reverse_nested (counts items instead of relations)
   * @endcode
   *
   * @param \Drupal\search_api\Entity\Index $index
   *   The Search API index.
   * @param string $field_id
   *   The facet field: "parent_field:child_field".
   * @param int $size
   *   The maximum number of buckets.
   * @param array|null $filter
   *   The filter from other active facets, or NULL.
   *
   * @return array
   *   The aggregation.
   */
  public function buildNestedAggregation(Index $index, string $field_id, int $size = 10000, ?array $filter = NULL): array {
    [$parent, $child] = explode(':', $field_id, 2);
    $query_field_path = $this->getElasticQueryFieldPath($index, $parent, $child);

    return [
      $field_id . '_filtered' => [
        // The filter runs on the indexed items, outside the nested context.
        'filter' => $filter ?? ['match_all' => new \stdClass()],
        'aggs' => [
          $field_id . '_nested' => [
            'nested' => ['path' => $parent],
            'aggs' => [
              $field_id => [
                'terms' => [
                  'field' => $query_field_path,
                  'size' => $size,
                ],
                'aggs' => [
                  'parents' => ['reverse_nested' => new \stdClass()],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds a nested filter structure.
   *
   * @param string $parent_path
   *   Parent field path.
   * @param array $subfilters
   *   Array of subfilters (must already be combined with bool/must/should).
   *
   * @return array
   *   Elasticsearch nested filter structure.
   */
  public function buildNestedFilter(string $parent_path, array $subfilters): array {
    return [
      'nested' => [
        'path' => $parent_path,
        'query' => $subfilters,
      ],
    ];
  }

  /**
   * Combines multiple filters with boolean conjunction.
   *
   * @param array $filters
   *   Array of filter structures to combine.
   * @param string $conjunction
   *   Conjunction type: 'AND' or 'OR'.
   *
   * @return array
   *   Combined filter structure, or empty array if no filters provided.
   */
  public function combineFilters(array $filters, string $conjunction): array {
    if (empty($filters)) {
      return [];
    }

    if (count($filters) === 1) {
      return reset($filters);
    }

    $bool_key = strtoupper($conjunction) === 'OR' ? 'should' : 'must';

    return [
      'bool' => [
        $bool_key => $filters,
      ],
    ];
  }

  /**
   * Returns the correct field path to use in a query (with or without ".keyword").
   *
   * @param \Drupal\search_api\Entity\Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The parent field name.
   * @param string $child_fld_nm
   *   The nested child field name.
   *
   * @return string
   *   The complete field path for querying (e.g., "parent.child.keyword").
   */
  public function getElasticQueryFieldPath(Index $index, string $sapi_fld_nm, string $child_fld_nm): string {
    $path_base = $sapi_fld_nm . '.' . $child_fld_nm;
    if ($this->needsKeywordSuffix($index, $sapi_fld_nm, $child_fld_nm)) {
      return $path_base . '.keyword';
    }
    return $path_base;
  }

  /**
   * Check if a field needs the ".keyword" suffix for aggregations or filters.
   *
   * @param \Drupal\search_api\Entity\Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The parent field name.
   * @param string $child_fld_nm
   *   The nested child field name.
   *
   * @return bool
   *   TRUE if ".keyword" is needed, FALSE otherwise.
   */
  protected function needsKeywordSuffix(Index $index, string $sapi_fld_nm, string $child_fld_nm): bool {
    $mapping = $this->mappingInspector->getFieldMapping($index, $sapi_fld_nm, $child_fld_nm);

    if (!$mapping) {
      return FALSE;
    }

    // Already a keyword field - no suffix needed.
    if (isset($mapping['type']) && $mapping['type'] === 'keyword') {
      return FALSE;
    }

    // Text field with keyword subfield - suffix needed.
    if (isset($mapping['type']) && $mapping['type'] === 'text') {
      return isset($mapping['fields']['keyword']);
    }

    return FALSE;
  }

}
