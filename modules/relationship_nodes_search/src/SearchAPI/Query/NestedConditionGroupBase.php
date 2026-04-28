<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Query\ConditionGroup;
use Drupal\relationship_nodes_search\QueryHelper\NestedQueryStructureBuilder;


/**
 * Base class for nested field condition groups.
 *
 * Provides shared properties and methods used by both
 * NestedParentFieldConditionGroup (top-level nested query) and
 * NestedChildFieldConditionGroup (inner sub-group within a nested query).
 */
abstract class NestedConditionGroupBase extends ConditionGroup {

  protected ?string $parentFieldName = NULL;
  protected ?Index $index = NULL;
  protected ?NestedQueryStructureBuilder $queryBuilder = NULL;


  public function setParentFieldName(string $parentFieldName): static {
    $this->parentFieldName = $parentFieldName;
    return $this;
  }


  public function getParentFieldName(): ?string {
    return $this->parentFieldName;
  }


  public function setIndex(Index $index): static {
    $this->index = $index;
    return $this;
  }


  public function setQueryBuilder(NestedQueryStructureBuilder $queryBuilder): static {
    $this->queryBuilder = $queryBuilder;
    return $this;
  }


  /**
   * Adds a child field condition, resolving the full Elasticsearch field path.
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


  /**
   * Creates and adds a sub-condition group inside this condition group.
   *
   * Use this to express inner boolean logic (e.g. OR-with-AND-sub-group) that
   * must apply to the same nested document as the other conditions in this group.
   *
   * @param string $conjunction
   *   'AND' or 'OR'.
   *
   * @return NestedChildFieldConditionGroup
   *   The new sub-group, for fluent chaining of addChildFieldCondition() calls.
   */
  public function addChildConditionGroup(string $conjunction): NestedChildFieldConditionGroup {
    $sub = new NestedChildFieldConditionGroup($conjunction);
    $sub->setParentFieldName($this->parentFieldName)
        ->setIndex($this->index)
        ->setQueryBuilder($this->queryBuilder);
    $this->conditions[] = $sub;
    return $sub;
  }
}