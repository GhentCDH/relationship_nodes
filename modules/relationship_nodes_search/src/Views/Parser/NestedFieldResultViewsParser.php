<?php

namespace Drupal\relationship_nodes_search\Views\Parser;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\relationship_nodes\Display\Parser\FieldResultParserBase;

/**
 * Parser for Views/Search API context.
 * 
 * Processes entity reference values from indexed Search API data.
 * Works with "entity_type/id" string format from Elasticsearch.
 * 
 * Used by: RelationshipField Views plugin, Views filter widgets
 */
class NestedFieldResultViewsParser extends FieldResultParserBase {

  /**
   * Constructs a NestedFieldResultViewsParser object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param LanguageManagerInterface $languageManager
   *   The language manager.
   * @param LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LanguageManagerInterface $languageManager,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    parent::__construct($entityTypeManager, $languageManager, $loggerFactory);
  }

  
   /**
   * Batch loads entities from indexed data.
   * 
   * Public wrapper that collects entity references from nested data,
   * groups them by type, and batch loads them.
   *
   * @param array $nested_data
   *   Array of relationship items from search results.
   * @param array $field_settings
   *   Field display settings.
   *
   * @return array
   *   Loaded entities keyed by "entity_type/id".
   */
  public function batchLoadFromIndexedData(array $nested_data, array $field_settings): array {
    $entity_ids_by_type = [];
    
    // Collect all entity reference IDs that need loading
    foreach ($nested_data as $item) {
      foreach ($field_settings as $child_fld_nm => $settings) {
        if (empty($settings['enabled']) || !isset($item[$child_fld_nm])) {
          continue;
        }
        
        $display_mode = $settings['display_mode'] ?? 'raw';
        if (!in_array($display_mode, ['label', 'link'])) {
          continue; // No entity loading needed for raw mode
        }
        
        // Collect entity IDs from field values
        $values = is_array($item[$child_fld_nm]) ? $item[$child_fld_nm] : [$item[$child_fld_nm]];
        foreach ($values as $value) {
          $parsed = $this->parseEntityReferenceString($value);
          if ($parsed) {
            $entity_type = $parsed['entity_type'];
            $entity_id = $parsed['entity_id'];
            
            if (!isset($entity_ids_by_type[$entity_type])) {
              $entity_ids_by_type[$entity_type] = [];
            }
            $entity_ids_by_type[$entity_type][] = $entity_id;
          }
        }
      }
    }
    
    // Batch load entities using protected parent method
    return $this->batchLoadEntities($entity_ids_by_type);
  }

  /**
   * Processes field values using pre-loaded entity cache.
   *
   * @param mixed $raw_value
   *   The raw field value(s) in "entity_type/id" format.
   * @param array $settings
   *   Display settings for this field.
   * @param array $preloaded_entities
   *   Pre-loaded entities keyed by "entity_type/id".
   *
   * @return array|null
   *   Processed field data with 'field_values', 'separator', and 'is_multiple' keys.
   */
  public function processFieldValuesWithCache($raw_value, array $settings, array $preloaded_entities): ?array {
    // Handle empty or NULL values
    if ($raw_value === NULL || $raw_value === '' || (is_array($raw_value) && empty($raw_value))) {
      return NULL;
    }
    
    $value_arr = is_array($raw_value) ? $raw_value : [$raw_value];
    $display_mode = $settings['display_mode'] ?? 'raw';
    $processed_values = [];

    foreach ($value_arr as $raw_val) {
      // Skip empty values
      if ($raw_val === NULL || $raw_val === '') {
        continue;
      }
      
      // Check if this value has a cached entity
      if (in_array($display_mode, ['label', 'link'], TRUE) && isset($preloaded_entities[$raw_val])) {
        try {
          $entity = $preloaded_entities[$raw_val];
          $processed_values[] = $this->resolveEntityValue($entity, $display_mode);
        }
        catch (\Exception $e) {
          $this->loggerFactory->get('relationship_nodes')->error('Error processing cached entity @value: @message', [
            '@value' => $raw_val,
            '@message' => $e->getMessage()
          ]);
          // Fall through to regular processing
          $fallback = $this->processSingleValue($raw_val, $display_mode);
          if ($fallback !== NULL) {
            $processed_values[] = $fallback;
          }
        }
      } else {
        // Raw mode or no cached entity
        $fallback = $this->processSingleValue($raw_val, $display_mode);
        if ($fallback !== NULL) {
          $processed_values[] = $fallback;
        }
      }
    }
    
    if (empty($processed_values)) {
      return NULL;
    }
    
    return [
      'field_values' => $processed_values,
      'separator' => $settings['multiple_separator'] ?? ', ',
      'is_multiple' => count($value_arr) > 1,
    ];
  }

  /**
   * Processes a single indexed field value.
   *
   * @param mixed $value
   *   The raw field value (string in "entity_type/id" format).
   * @param string $display_mode
   *   Display mode: 'raw', 'label', or 'link'.
   *
   * @return array|null
   *   Array with 'value' and 'link_url' keys, or NULL if invalid.
   */
  protected function processSingleValue($value, string $display_mode = 'raw'): ?array {
    $result = ['value' => (string) $value, 'link_url' => NULL];

    // Raw mode - return as-is
    if ($display_mode === 'raw') {
      return $result;
    }

    // Parse entity reference
    $parsed = $this->parseEntityReferenceString($value);
    if (!$parsed) {
      return $result; // Return raw value if parse fails
    }

    // Load entity and resolve
    $entity = $this->loadEntity($parsed['entity_type'], $parsed['id']);
    if (!$entity) {
      return $result; // Return raw value if entity not found
    }

    return $this->resolveEntityValue($entity, $display_mode);
  }

  /**
   * Parses an entity reference string from indexed data.
   * 
   * Converts "entity_type/id" format into component parts.
   * Examples: "node/123", "taxonomy_term/456"
   *
   * @param mixed $value
   *   The value to parse.
   *
   * @return array|null
   *   Array with 'entity_type' and 'id' keys, or NULL if invalid.
   */
  public function parseEntityReferenceString($value): ?array {
    if (empty($value) || !is_string($value)) {
      return NULL;
    }
    
    // Split on first slash only to handle IDs that might contain slashes
    $parts = explode('/', $value, 2);
    
    // Validate we have exactly 2 parts and both are non-empty
    if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
      return NULL;
    }
    
    return [
      'entity_type' => trim($parts[0]),
      'id' => trim($parts[1]),
    ];
  }

  /**
   * Extracts integer IDs from entity reference string values.
   * 
   * Converts ["node/123", "node/456"] to [123, 456].
   * Useful for filtering or grouping operations.
   *
   * @param array $str_id_array
   *   Array of string IDs in "entity_type/id" format.
   * @param string $entity_type
   *   The entity type to filter by.
   *
   * @return array
   *   Array of integer IDs.
   */
  public function extractIntIdsFromStrings(array $str_id_array, string $entity_type): array {
    $result = [];
    $prefix = $entity_type . '/';
    
    foreach ($str_id_array as $string_id) {
      if (!is_string($string_id) || !str_starts_with($string_id, $prefix)) {
        continue;
      }
      
      $cleaned = substr($string_id, strlen($prefix));
      if (is_numeric($cleaned)) {
        $result[] = (int) $cleaned;
      }
    }
    
    return $result;
  }
}