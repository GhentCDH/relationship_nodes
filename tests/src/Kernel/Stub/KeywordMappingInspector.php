<?php

namespace Drupal\Tests\relationship_nodes\Kernel\Stub;

use Drupal\relationship_nodes_search\QueryHelper\ElasticMappingInspector;
use Drupal\search_api\Entity\Index;

/**
 * Mapping inspector that reports keyword child fields, without Elasticsearch.
 */
class KeywordMappingInspector extends ElasticMappingInspector {

  /**
   * {@inheritdoc}
   */
  public function getIndexMappings(Index $index): array {
    $mappings = [];
    foreach ($index->getFields() as $field_id => $field) {
      $children = $field->getConfiguration()['nested_fields'] ?? NULL;
      if ($children) {
        $mappings[$field_id] = [
          'type' => 'nested',
          'properties' => array_map(fn() => ['type' => 'keyword'], $children),
        ];
      }
    }
    return $mappings;
  }

}
