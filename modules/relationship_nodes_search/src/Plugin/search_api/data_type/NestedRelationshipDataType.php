<?php

namespace Drupal\relationship_nodes_search\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a data type for nested relationship objects.
 *
 * This data type is used by Search API to handle nested relationship data
 * structures in Elasticsearch indexes. The actual indexing logic is handled
 * by the RelationshipIndexer processor.
 *
 * @SearchApiDataType(
 *   id = "relationship_nodes_search_nested_relationship",
 *   label = @Translation("Nested relationship object"),
 *   description = @Translation("Stores nested relationship data as an object."),
 * )
 */
class NestedRelationshipDataType extends DataTypePluginBase {

}