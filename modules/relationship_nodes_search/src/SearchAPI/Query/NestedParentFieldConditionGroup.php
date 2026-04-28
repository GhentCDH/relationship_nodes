<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

/**
 * Condition group for nested parent field queries.
 *
 * Extends NestedConditionGroupBase to add support for Elasticsearch nested
 * queries by tracking the parent field path and using the query builder to
 * resolve correct field paths including .keyword suffixes.
 */
class NestedParentFieldConditionGroup extends NestedConditionGroupBase {


  /**
   * Checks if this is a nested parent field condition group.
   *
   * @return bool
   *   TRUE if parent field name is set, FALSE otherwise.
   */
  public function isNestedParentField(): bool {
    return !empty($this->parentFieldName);
  }



}