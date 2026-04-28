<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

use Drupal\search_api\Entity\Index;
use Drupal\relationship_nodes_search\QueryHelper\NestedQueryStructureBuilder;


/**
 * A sub-group of child field conditions within a nested parent field query.
 *
 * Allows expressing inner boolean logic (e.g. OR) inside a single nested
 * Elasticsearch query. All conditions in this group are resolved against the
 * same parent field, so the generated bool.should/must fragment is emitted
 * inside the parent's nested query — not as a separate nested query.
 *
 * This ensures both conditions in a range overlap check apply to the same
 * nested document, preventing cross-object false positives.
 *
 * Usage (from RelationshipFilter::buildRangePairConditions()):
 * @code
 * $group->addChildConditionGroup('OR')
 *       ->addChildFieldCondition($end_field, $from_val, '>=')
 *       ->addChildFieldCondition($end_field, NULL, '=');
 * @endcode
 */
class NestedChildFieldConditionGroup extends NestedConditionGroupBase {

  /**
   * Adds a child field condition to this group.
   *
   * Resolves the full Elasticsearch field path and creates a
   * NestedChildFieldCondition, mirroring the API of
   * NestedParentFieldConditionGroup::addChildFieldCondition().
   *
   * @param string $child_fld_nm
   *   The child field name within the nested object.
   * @param mixed $value
   *   The value to filter on. NULL generates an exists/missing condition.
   * @param string $operator
   *   The comparison operator (=, !=, >=, <=, etc.).
   *
   * @return $this
   */
  public function addChildFieldCondition(string $child_fld_nm, $value, string $operator = '='): static {
    $path = $this->queryBuilder->getElasticQueryFieldPath(
      $this->index,
      $this->parentFieldName,
      $child_fld_nm
    );

    $condition = new NestedChildFieldCondition($path, $value, $operator);
    $condition->setParentFieldName($this->parentFieldName);
    $condition->setChildFieldName($child_fld_nm);

    $this->conditions[] = $condition;
    return $this;
  }
}