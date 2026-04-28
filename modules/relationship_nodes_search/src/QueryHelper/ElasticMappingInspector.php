<?php

namespace Drupal\relationship_nodes_search\QueryHelper;

use Drupal\search_api\Entity\Index;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;


/**
 * Service for inspecting and working with Elasticsearch field mappings.
 * 
 * Provides utilities to determine correct field paths for queries and aggregations,
 * handling the complexity of Elasticsearch's text/keyword field patterns.
 */
class ElasticMappingInspector {

  protected array $mappingCache = [];
  protected array $fieldMappingCache = [];
  protected LoggerChannelFactoryInterface $loggerFactory;

  
  /**
   * Constructs an ElasticMappingInspector object.
   *
   * @param LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->loggerFactory = $loggerFactory;
  }


  /**
   * Retrieves the Elasticsearch mapping info for a specific field.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The parent field name.
   * @param string $child_fld_nm
   *   The nested child field name.
   *
   * @return array|null
   *   The field mapping array, or NULL if not found.
   */
  public function getFieldMapping(Index $index, string $sapi_fld_nm, string $child_fld_nm): ?array {
    $cache_key = $index->id() . ':' . $sapi_fld_nm . ':' . $child_fld_nm;
    if (isset($this->fieldMappingCache[$cache_key])) {
      return $this->fieldMappingCache[$cache_key];
    }

    $all_mappings = $this->getIndexMappings($index);

    if (!isset($all_mappings[$sapi_fld_nm])) {
      $this->fieldMappingCache[$cache_key] = NULL;
      return NULL;
    }

    $parent_mapping = $all_mappings[$sapi_fld_nm];

    // Check nested field properties
    if (($parent_mapping['type'] ?? '') === 'nested' && isset($parent_mapping['properties'][$child_fld_nm])) {
      $result = $parent_mapping['properties'][$child_fld_nm];
      $this->fieldMappingCache[$cache_key] = $result;
      return $result;
    }

    // Fallback to direct properties
    if (isset($parent_mapping['properties'][$child_fld_nm])) {
      $result = $parent_mapping['properties'][$child_fld_nm];
      $this->fieldMappingCache[$cache_key] = $result;
      return $result;
    }

    $this->fieldMappingCache[$cache_key] = NULL; 
    return NULL;
  }


  /**
   * Retrieves all field mappings for a Search API index from Elasticsearch.
   * Results are cached to avoid repeated API calls.
   *
   * @param Index $index
   *   The Search API index.
   *
   * @return array
   *   Array of field mappings keyed by field name.
   */
  public function getIndexMappings(Index $index): array {
    $index_id = $index->id();
    
    if (isset($this->mappingCache[$index_id])) {
      return $this->mappingCache[$index_id];
    }

    try {
      $server = $index->getServerInstance();
      $backend = $server->getBackend();
      $client = $backend->getClient();
          
      $response = $client->indices()->getMapping(['index' => $index_id]);
      
      // Extract properties from response
      $properties = $response[$index_id]['mappings']['properties'] ?? [];
      
      $this->mappingCache[$index_id] = $properties;
      
      return $properties;
    } catch (\Exception $e) {
      $this->loggerFactory->get('relationship_nodes_search')->error(
        'Failed to retrieve Elasticsearch mappings for index @index: @message',
        ['@index' => $index_id, '@message' => $e->getMessage()]
      );
      return [];
    }
  }


  /**
   * Clears cached mappings (for a specific index or all indices).
   *
   * @param string|null $index_id
   *    Optional index ID to clear. If NULL, clears all cached mappings.
   */
  public function clearCache(?string $index_id = NULL): void {
    if ($index_id) {
      unset($this->mappingCache[$index_id]);
      foreach (array_keys($this->fieldMappingCache) as $key) {
        if (str_starts_with($key, $index_id . ':')) {
          unset($this->fieldMappingCache[$key]);
        }
      }
    } else {
      $this->mappingCache = [];
      $this->fieldMappingCache = [];
    }
  }
}