<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

use Drupal\elasticsearch_connector\SearchAPI\Query\FilterBuilder;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\relationship_nodes_search\SearchAPI\Query\NestedChildFieldConditionGroup;
use Drupal\relationship_nodes_search\SearchAPI\Query\NestedConditionGroupBase;
use Psr\Log\LoggerInterface;
use Drupal\relationship_nodes_search\QueryHelper\NestedQueryStructureBuilder;


/**
 * Extended filter builder with nested field support.
 *
 * Decorates elasticsearch_connector.query_filter_builder (via the `decorates`
 * key in services.yml). Like NestedFacetParamBuilder, this is a decorator
 * rather than a replacement so that filter building for flat fields continues
 * to use the original service unchanged. The decorator only acts when it
 * encounters a NestedParentFieldConditionGroup in the condition tree; all other
 * condition groups are handled by the parent FilterBuilder.
 */
class NestedFilterBuilder extends FilterBuilder {
   
  protected NestedQueryStructureBuilder $queryBuilder;

  /**
   * Constructs a NestedFilterBuilder object.
   *
   * @param LoggerInterface $logger
   *   The logger service.
   * @param NestedQueryStructureBuilder $queryBuilder
   *   The query structure builder service.
   */
  public function __construct(
    LoggerInterface $logger,
    NestedQueryStructureBuilder $queryBuilder
  ) {
    parent::__construct($logger);
    $this->queryBuilder = $queryBuilder;
  }


  /**
   * {@inheritdoc}
   */
  public function buildFilters(ConditionGroupInterface $condition_group, array $index_fields) {
    if (!($condition_group instanceof NestedParentFieldConditionGroup)) {
      return parent::buildFilters($condition_group, $index_fields);
    }
    return $this->buildNestedFieldConditionFilters($condition_group, $index_fields);
  }


  /**
   * Recursively builds the subfilter array for a nested condition group.
   *
   * Handles NestedChildFieldConditionGroup at any depth by recursing into
   * sub-groups, and NestedChildFieldCondition as leaf nodes.
   *
   * @param NestedConditionGroupBase $group
   *   The condition group to process.
   * @param array $index_fields
   *   The index fields configuration.
   *
   * @return array
   *   Flat list of Elasticsearch filter fragments.
   */
  protected function buildConditionGroupSubfilters(NestedConditionGroupBase $group, array $index_fields): array {
    $subfilters = [];
    foreach ($group->getConditions() ?? [] as $condition) {
      if ($condition instanceof NestedChildFieldConditionGroup) {
        $inner = $this->buildConditionGroupSubfilters($condition, $index_fields);
        $subfilters[] = $this->wrapWithConjunction($inner, $condition->getConjunction());
      }
      elseif ($condition instanceof NestedChildFieldCondition) {
        $subfilters[] = $this->buildFilterTerm($condition, $index_fields);
      }
    }
    return $subfilters;
  }


  /**
   * Builds an Elasticsearch nested query from a NestedParentFieldConditionGroup.
   */
  protected function buildNestedFieldConditionFilters(NestedParentFieldConditionGroup $condition_group, array $index_fields): array {
    $parent = $condition_group->getParentFieldName();
    
    if (empty($parent)) {
       $this->logger->warning('NestedParentFieldConditionGroup without parent field name');
      return [];
    }

    $subfilters = $this->buildConditionGroupSubfilters($condition_group, $index_fields);

    if (empty($subfilters)) {
      return [];
    }

    $combined_subfilters = $this->wrapWithConjunction($subfilters, $condition_group->getConjunction());
    return ['filters' => $this->queryBuilder->buildNestedFilter($parent, $combined_subfilters)];
  }
}