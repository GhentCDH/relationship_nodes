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

}
