<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

use Drupal\elasticsearch_connector\SearchAPI\Query\FilterBuilder;
use Drupal\search_api\Query\ConditionGroupInterface;
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
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\relationship_nodes_search\QueryHelper\NestedQueryStructureBuilder $queryBuilder
   *   The query structure builder service.
   */
  public function __construct(
    LoggerInterface $logger,
    NestedQueryStructureBuilder $queryBuilder,
  ) {
    parent::__construct($logger);
    $this->queryBuilder = $queryBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public function buildFilters(ConditionGroupInterface $condition_group, array $index_fields, array $querySettings = []) {
    if (!($condition_group instanceof NestedParentFieldConditionGroup)) {
      return parent::buildFilters($condition_group, $index_fields, $querySettings);
    }
    return $this->buildNestedFieldConditionFilters($condition_group, $index_fields, $querySettings);
  }

  /**
   * Recursively builds the subfilter array for a nested condition group.
   *
   * Handles NestedChildFieldConditionGroup at any depth by recursing into
   * sub-groups, and NestedChildFieldCondition as leaf nodes.
   *
   * @param \Drupal\relationship_nodes_search\SearchAPI\Query\NestedConditionGroupBase $group
   *   The condition group to process.
   * @param array $index_fields
   *   The index fields configuration.
   * @param array $querySettings
   *   The query settings.
   *
   * @return array
   *   Flat list of Elasticsearch filter fragments.
   */
  protected function buildConditionGroupSubfilters(NestedConditionGroupBase $group, array $index_fields, array $querySettings = []): array {
    $subfilters = [];
    foreach ($group->getConditions() ?? [] as $condition) {
      if ($condition instanceof NestedChildFieldConditionGroup) {
        $inner = $this->buildConditionGroupSubfilters($condition, $index_fields, $querySettings);
        $subfilters[] = $this->wrapWithConjunction($inner, $condition->getConjunction());
      }
      elseif ($condition instanceof NestedChildFieldCondition) {
        $subfilters[] = $this->buildFilterTerm($condition, $index_fields, $querySettings);
      }
    }
    return $subfilters;
  }

  /**
   * Builds an Elasticsearch nested query from a NestedParentFieldConditionGroup.
   */
  protected function buildNestedFieldConditionFilters(NestedParentFieldConditionGroup $condition_group, array $index_fields, array $querySettings = []): array {
    $parent = $condition_group->getParentFieldName();

    // The parent's buildFilters() reads all three keys of a nested result.
    $result = ['filters' => [], 'post_filters' => [], 'facets_post_filters' => []];

    if (empty($parent)) {
      $this->logger->warning('NestedParentFieldConditionGroup without parent field name');
      return $result;
    }

    // A single negative condition means "no relation matches", e.g. "relation
    // type is not co-worker" excludes items with a co-worker relation. Inside
    // one nested query it would mean "some relation does not match". Combined
    // conditions keep their meaning: one relation that matches all of them.
    $conditions = $condition_group->getConditions();
    if (count($conditions) === 1 && reset($conditions) instanceof NestedChildFieldCondition) {
      $positive = $this->getPositiveCondition(reset($conditions));
      if ($positive) {
        $nested = $this->queryBuilder->buildNestedFilter($parent, $this->buildFilterTerm($positive, $index_fields, $querySettings));
        $result['filters'] = ['bool' => ['must_not' => [$nested]]];
        return $result;
      }
    }

    $subfilters = $this->buildConditionGroupSubfilters($condition_group, $index_fields, $querySettings);

    if (empty($subfilters)) {
      return $result;
    }

    $combined_subfilters = $this->wrapWithConjunction($subfilters, $condition_group->getConjunction());
    $result['filters'] = $this->queryBuilder->buildNestedFilter($parent, $combined_subfilters);
    return $result;
  }

  /**
   * Returns the positive counterpart of a negative condition.
   *
   * @param NestedChildFieldCondition $condition
   *   The condition.
   *
   * @return NestedChildFieldCondition|null
   *   The condition with the opposite operator, or NULL if the condition is
   *   not negative.
   */
  protected function getPositiveCondition(NestedChildFieldCondition $condition): ?NestedChildFieldCondition {
    $value = $condition->getValue();
    $operator = match (TRUE) {
      $condition->getOperator() === '<>' && $value !== NULL => '=',
      $condition->getOperator() === 'NOT IN' => 'IN',
      // "Field is empty": no relation has a value.
      $condition->getOperator() === '=' && $value === NULL => '<>',
      default => NULL,
    };
    if ($operator === NULL) {
      return NULL;
    }
    $positive = clone $condition;
    $positive->setOperator($operator);
    return $positive;
  }

}
