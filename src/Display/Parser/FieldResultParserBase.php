<?php

namespace Drupal\relationship_nodes\Display\Parser;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;

/**
 * Base parser for processing entity reference values in nested fields.
 * 
 * Provides shared logic for:
 * - Batch loading entities across multiple entity types
 * - Resolving entity labels and links
 * - Language-aware entity loading
 * - Error handling and logging
 * 
 * Context-specific parsers extend this to handle different data sources:
 * - Formatter: Direct Drupal entities from relation nodes
 * - Views: Indexed data strings from Search API
 */
abstract class FieldResultParserBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected LanguageManagerInterface $languageManager;
  protected LoggerChannelFactoryInterface $loggerFactory;

  
  /**
   * Constructs a FieldResultParserBase object.
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
    $this->entityTypeManager = $entityTypeManager;
    $this->languageManager = $languageManager;
    $this->loggerFactory = $loggerFactory;
  }


  /**
   * Batch loads entities grouped by entity type.
   * 
   * Loads all entities in a single query per entity type for performance.
   * Automatically loads the correct language translation when available.
   *
   * @param array $entity_ids_by_type
   *   Array keyed by entity type with arrays of entity IDs as values.
   *   Example: ['node' => [1, 2, 3], 'taxonomy_term' => [10, 11]]
   * @param string|null $langcode
   *   Optional language code. If NULL, uses current language.
   *
   * @return array
   *   Loaded entities keyed by "entity_type/id" format.
   *   Example: ['node/1' => Node object, 'taxonomy_term/10' => Term object]
   */
  protected function batchLoadEntities(array $entity_ids_by_type, ?string $langcode = NULL): array {
    $loaded_entities = [];
    $langcode = $langcode ?? $this->languageManager->getCurrentLanguage()->getId();
    
    foreach ($entity_ids_by_type as $entity_type => $ids) {
      // Remove duplicates
      $unique_ids = array_unique($ids);
      
      try {
        $storage = $this->entityTypeManager->getStorage($entity_type);
        $entities = $storage->loadMultiple($unique_ids);
        
        foreach ($entities as $id => $entity) {
          // Load translated version if available
          if ($entity instanceof ContentEntityInterface && $entity->hasTranslation($langcode)) {
            $entity = $entity->getTranslation($langcode);
          }
          
          $cache_key = $entity_type . '/' . $id;
          $loaded_entities[$cache_key] = $entity;
        }
      }
      catch (\InvalidArgumentException $e) {
        $this->loggerFactory->get('relationship_nodes')->error('Invalid entity type "@type": @message', [
          '@type' => $entity_type,
          '@message' => $e->getMessage()
        ]);
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('relationship_nodes')->error('Failed to batch load @type entities: @message', [
          '@type' => $entity_type,
          '@message' => $e->getMessage()
        ]);
      }
    }
    
    return $loaded_entities;
  }


  /**
   * Resolves an entity to its label and optional URL.
   * 
   * Works with any entity type that has a label() method.
   *
   * @param ContentEntityInterface $entity
   *   The entity to resolve.
   * @param string $display_mode
   *   Display mode: 'raw', 'label', or 'link'.
   *
   * @return array
   *   Array with keys:
   *   - 'value': Entity label (or ID for raw mode)
   *   - 'link_url': Url object or NULL
   */
  protected function resolveEntityValue(ContentEntityInterface $entity, string $display_mode): array {
    $result = [
      'value' => $entity->id(),
      'link_url' => NULL,
    ];

    // For raw mode, just return the ID
    if ($display_mode === 'raw') {
      return $result;
    }

    // Get label
    if (method_exists($entity, 'label')) {
      $result['value'] = $entity->label();
    } else {
      $this->loggerFactory->get('relationship_nodes')->warning('Entity @type/@id does not have label() method', [
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id()
      ]);
      return $result;
    }

    // Get URL if link mode
    if ($display_mode === 'link') {
      try {
        $result['link_url'] = $entity->toUrl();
      }
      catch (UndefinedLinkTemplateException $e) {
        // Some entity types don't have canonical URLs (e.g., paragraphs)
        $this->loggerFactory->get('relationship_nodes')->debug('Entity @type/@id does not support URLs', [
          '@type' => $entity->getEntityTypeId(),
          '@id' => $entity->id()
        ]);
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('relationship_nodes')->error('Error generating URL for entity @type/@id: @message', [
          '@type' => $entity->getEntityTypeId(),
          '@id' => $entity->id(),
          '@message' => $e->getMessage()
        ]);
      }
    }

    return $result;
  }


  /**
   * Loads a single entity by type and ID.
   * 
   * Automatically loads the correct language translation when available.
   *
   * @param string $entity_type
   *   The entity type (e.g., 'node', 'taxonomy_term').
   * @param mixed $entity_id
   *   The entity ID.
   * @param string|null $langcode
   *   Optional language code. If NULL, uses current language.
   *
   * @return ContentEntityInterface|null
   *   The loaded entity, or NULL if not found.
   */
  protected function loadEntity(string $entity_type, $entity_id, ?string $langcode = NULL): ?ContentEntityInterface {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);
      
      if (!$entity instanceof ContentEntityInterface) {
        return NULL;
      }
      
      // Load translated version if available
      $langcode = $langcode ?? $this->languageManager->getCurrentLanguage()->getId();
      if ($entity->hasTranslation($langcode)) {
        $entity = $entity->getTranslation($langcode);
      }
      
      return $entity;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('relationship_nodes')->error('Failed to load entity @type/@id: @message', [
        '@type' => $entity_type,
        '@id' => $entity_id,
        '@message' => $e->getMessage()
      ]);
      return NULL;
    }
  }
}