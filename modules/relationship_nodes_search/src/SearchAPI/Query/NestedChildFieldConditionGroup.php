<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

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

}
