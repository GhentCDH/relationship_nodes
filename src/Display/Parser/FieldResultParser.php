<?php

namespace Drupal\relationship_nodes\Display\Parser;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Parser for field formatter context.
 * 
 * Processes entity reference values from Drupal field data structures.
 * Works with actual loaded entities from relation nodes.
 * 
 * Used by: RelationshipDataBuilder
 */
class FieldResultParser extends FieldResultParserBase {

  /**
   * Processes entity IDs from field values with batch loading.
   * 
   * Collects all entity references, groups them by entity type,
   * batch loads them, and formats according to display mode.
   *
   * @param array $entity_references
   *   Array of entity references, each with:
   *   - 'entity_type': The entity type (e.g., 'node', 'taxonomy_term')
   *   - 'entity_id': The entity ID
   * @param array $config
   *   Field configuration with 'display_mode' and 'linkable' keys.
   * @param string|null $langcode
   *   Optional language code. If NULL, uses current language.
   *
   * @return array
   *   Array of processed values with 'value' and 'link_url' keys.
   */
  public function processEntityReferences(array $entity_references, array $config, ?string $langcode = NULL): array {
    if (empty($entity_references)) {
      return [];
    }

    $display_mode = $config['display_mode'] ?? 'label';
    $linkable = !empty($config['linkable']);

    // For raw mode, just return IDs
    if ($display_mode === 'raw' || !$linkable) {
      return array_map(function($ref) {
        return [
          'value' => (string) $ref['entity_id'],
          'link_url' => NULL,
        ];
      }, $entity_references);
    }

    // Group entity IDs by type for batch loading
    $entity_ids_by_type = [];
    foreach ($entity_references as $ref) {
      $entity_type = $ref['entity_type'];
      $entity_id = $ref['entity_id'];
      
      if (!isset($entity_ids_by_type[$entity_type])) {
        $entity_ids_by_type[$entity_type] = [];
      }
      $entity_ids_by_type[$entity_type][] = $entity_id;
    }

    // Batch load all entities
    $loaded_entities = $this->batchLoadEntities($entity_ids_by_type, $langcode);

    // Process each reference using loaded entities
    $values = [];
    foreach ($entity_references as $ref) {
      $cache_key = $ref['entity_type'] . '/' . $ref['entity_id'];
      
      if (isset($loaded_entities[$cache_key])) {
        $values[] = $this->resolveEntityValue($loaded_entities[$cache_key], $display_mode);
      } else {
        // Fallback if entity couldn't be loaded
        $values[] = [
          'value' => (string) $ref['entity_id'],
          'link_url' => NULL,
        ];
      }
    }

    return $values;
  }

  /**
   * Extracts entity references from a Drupal field.
   * 
   * Handles entity_reference fields and returns structured data with entity type.
   *
   * @param ContentEntityInterface $entity
   *   The entity containing the field.
   * @param string $field_name
   *   The field name to extract from.
   *
   * @return array
   *   Array of entity references with 'entity_type' and 'entity_id' keys.
   */
  public function extractEntityReferencesFromField(ContentEntityInterface $entity, string $field_name): array {
    if (!$entity->hasField($field_name)) {
      return [];
    }

    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return [];
    }

    $field_definition = $field->getFieldDefinition();
    $target_type = $field_definition->getSetting('target_type');

    if (empty($target_type)) {
      $this->loggerFactory->get('relationship_nodes')->warning('Field @field on @entity_type has no target_type', [
        '@field' => $field_name,
        '@entity_type' => $entity->getEntityTypeId()
      ]);
      return [];
    }

    $references = [];
    foreach ($field->getValue() as $item) {
      if (isset($item['target_id'])) {
        $references[] = [
          'entity_type' => $target_type,
          'entity_id' => $item['target_id'],
        ];
      }
    }

    return $references;
  }

  /**
   * Processes a simple array of entity IDs (single entity type).
   * 
   * Convenience method when all IDs are from the same entity type.
   *
   * @param array $entity_ids
   *   Array of entity IDs.
   * @param string $entity_type
   *   The entity type for all IDs.
   * @param array $config
   *   Field configuration.
   * @param string|null $langcode
   *   Optional language code. If NULL, uses current language.
   *
   * @return array
   *   Array of processed values.
   */
  public function processEntityIds(array $entity_ids, string $entity_type, array $config, ?string $langcode = NULL): array {
    $references = array_map(function($id) use ($entity_type) {
      return [
        'entity_type' => $entity_type,
        'entity_id' => $id,
      ];
    }, $entity_ids);

    return $this->processEntityReferences($references, $config, $langcode);
  }
}