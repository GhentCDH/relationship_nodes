<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

use Drupal\elasticsearch_connector\SearchAPI\Query\FacetParamBuilder;
use Drupal\search_api\Query\QueryInterface;
use Psr\Log\LoggerInterface;
use Drupal\relationship_nodes_search\QueryHelper\NestedQueryStructureBuilder;
use Drupal\search_api\Entity\Index;
use Drupal\relationship_nodes_search\FieldHelper\NestedIndexFieldHelper;


/**
 * Extended facet builder with nested field support.
 *
 * Decorates elasticsearch_connector.facet_builder (via the `decorates` key in
 * services.yml) rather than replacing it. This is intentional: replacing the
 * service entirely would break aggregations for all non-nested fields, which
 * make up the majority of facets on the site. The decorator intercepts only
 * nested relationship fields and delegates everything else to the parent class /
 * inner service, ensuring non-nested behaviour is completely unchanged.
 */
class NestedFacetParamBuilder extends FacetParamBuilder {

  protected NestedQueryStructureBuilder $queryBuilder;
  protected NestedIndexFieldHelper $nestedFieldHelper;


  /**
   * Constructs a NestedFacetParamBuilder object.
   *
   * @param LoggerInterface $logger
   *   The logger service.
   * @param NestedQueryStructureBuilder $queryBuilder
   *   The query structure builder service.
   * @param NestedIndexFieldHelper $nestedFieldHelper
   *   The nested field helper service.
   */
  public function __construct(
    LoggerInterface $logger, 
    NestedQueryStructureBuilder $queryBuilder,
    NestedIndexFieldHelper $nestedFieldHelper
  ) {
    parent::__construct($logger);
    $this->queryBuilder = $queryBuilder;
    $this->nestedFieldHelper = $nestedFieldHelper;
  }


  /**
   * {@inheritdoc}
   */
  public function buildFacetParams(QueryInterface $query, array $indexFields, array $facetFilters = []) {    
    $aggs = [];
    $facets = $query->getOption('search_api_facets', []);
    
    if (empty($facets)) {
      return $aggs;
    }

    $index = $query->getIndex();
    foreach ($facets as $facet_id => $facet) {
      $parsed_names = $this->nestedFieldHelper->validateNestedPath($index, $facet_id);

      $check_field = $parsed_names['parent'] ?? $facet['field'];
      if (!$this->checkFieldInIndex($indexFields, $check_field)) {
        continue;
      }

      if (empty($parsed_names['parent'])) {
        $aggs += $this->buildTermBucketAgg($facet_id, $facet, $facetFilters);
      } else {
        $result = $this->buildNestedTermBucketAgg($index, $facet_id, $facet, $facetFilters);
        $aggs += $result;
      }
    }
    return $aggs;
  }


  /**
   * Checks if a field exists in the index.
   *
   * @param array $indexFields
   *   Array of index fields.
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if field exists, FALSE otherwise.
   */
  protected function checkFieldInIndex(array $indexFields, string $field_name): bool {
    if (!isset($indexFields[$field_name])) {
      $this->logger->warning('Unknown facet field: %field', ['%field' => $field_name]);
      return FALSE;
    }
    return TRUE;
  }


  /**
   * Builds a nested bucket aggregation.
   *
   * Creates an Elasticsearch nested aggregation for fields within nested objects.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $facet_id
   *   The facet identifier.
   * @param array $facet
   *   The facet configuration.
   * @param array $postFilters
   *   Post-filters for facet interaction.
   *
   * @return array
   *   The nested aggregation structure.
   */
  protected function buildNestedTermBucketAgg(Index $index, string $facet_id, array $facet, array $postFilters): array {
    return $this->queryBuilder->buildNestedAggregation(
      $index, 
      $facet_id, 
      $this->getFacetSize($facet), 
      $this->buildPostFilter($facet_id, $facet, $postFilters)
    );
  }


  /**
   * Builds post filter for nested aggregation.
   *
   * Post filters allow facets to interact with each other (e.g., when multiple
   * facets are selected, each facet's counts reflect the other selections).
   *
   * @param string $facet_id
   *   The facet identifier.
   * @param array $facet
   *   The facet configuration.
   * @param array $postFilters
   *   Available post-filters.
   *
   * @return array|null
   *   Combined filter structure, or NULL if no filters apply.
   */
  protected function buildPostFilter(string $facet_id, array $facet, array $postFilters): ?array {
    $filters = [];

    foreach ($postFilters as $filter_facet_id => $filter) {
      // Skip the current facet if using OR operator
      // (OR facets should show all options regardless of selection)
      if ($filter_facet_id == $facet_id && ($facet['operator'] ?? 'and') === 'or') {
        continue;
      }
      $filters[] = $filter;
    }

    if (empty($filters)) {
      return NULL;
    }

    $conjunction = ($facet['operator'] ?? 'and') === 'or' ? 'OR' : 'AND';
    return $this->queryBuilder->combineFilters($filters, $conjunction);
  }


  /**
   * Gets the facet size from configuration.
   *
   * @param array $facet
   *   The facet configuration.
   *
   * @return int
   *   The facet size (0 means unlimited).
   */
  protected function getFacetSize(array $facet): int {
    $size = $facet['limit'] ?? self::DEFAULT_FACET_SIZE;
    return $size === 0 ? self::UNLIMITED_FACET_SIZE : $size;
  }

}