<?php

namespace Drupal\relationship_nodes\Display;

use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\RelationField\VirtualFieldManager;
use Drupal\relationship_nodes\Display\Configurator\FormatterConfigurator;
use Drupal\relationship_nodes\Display\RelationshipDataBuilder;

/**
 * Generic service for formatting relationships for Twig rendering.
 *
 * Provides rich relationship data structures optimized for template rendering.
 * Always returns rich format — each field value is an array with 'value',
 * 'url', 'is_fallback', 'langcode', and 'available_languages' keys.
 * The template is responsible for deciding how to render each field.
 */
class RelationshipTwigFormatter {

  protected VirtualFieldManager $virtualFieldManager;
  protected RelationshipDataBuilder $dataBuilder;
  protected FormatterConfigurator $configurator;

  /**
   * Constructs a RelationshipTwigFormatter object.
   */
  public function __construct(
    VirtualFieldManager $virtualFieldManager,
    RelationshipDataBuilder $dataBuilder,
    FormatterConfigurator $configurator
  ) {
    $this->virtualFieldManager = $virtualFieldManager;
    $this->dataBuilder = $dataBuilder;
    $this->configurator = $configurator;
  }


  /**
   * Get all relation field names for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param array $bundle_order
   *   Optional custom bundle sort order. Empty array = alphabetical.
   *   Example: ['story', 'person', 'institution']
   *
   * @return array|null
   *   Array of relation field names, or NULL if none.
   */
  public function getAllRelationFields(NodeInterface $node, array $bundle_order = []): ?array {
    $fields = $this->virtualFieldManager->getReferencingRelationshipFields($node);

    if (!$fields) {
      return NULL;
    }

    if (empty($bundle_order)) {
      // Sort alphabetically by related bundle name.
      usort($fields, function ($a, $b) {
        return strcasecmp($this->extractRelatedBundle($a), $this->extractRelatedBundle($b));
      });
    }
    else {
      // Sort by custom bundle order.
      usort($fields, function ($a, $b) use ($bundle_order) {
        $pos_a = array_search($this->extractRelatedBundle($a), $bundle_order);
        $pos_b = array_search($this->extractRelatedBundle($b), $bundle_order);
        if ($pos_a === FALSE) $pos_a = 999;
        if ($pos_b === FALSE) $pos_b = 999;
        return $pos_a <=> $pos_b;
      });
    }

    return $fields;
  }


  /**
   * Get formatted relationships ready for Twig rendering.
   *
   * Always returns rich format — each field value is an array with 'value',
   * 'url', 'is_fallback', 'langcode', and 'available_languages' keys.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The viewing node.
   * @param string $relation_field_name
   *   Field name like 'computed_relationshipfield__person__concept'.
   * @param array $options
   *   Options:
   *   - 'default_fields': Array of field names to include
   *     (default: ['calculated_related_id', 'calculated_relation_type_name'])
   *   - 'include_links': Whether to resolve entity references as links
   *     (default: TRUE)
   *   - 'language': Language code to use. Falls back to current language
   *     (OPTIONAL)
   *   - 'language_fallback': If TRUE, include relations unavailable in the
   *     requested language using the best available language (default: FALSE)
   *   - 'extra_fields': Associative array per relation field:
   *       ['computed_relationshipfield__person__person' => ['field_date_start']]
   *   - 'limit': If set, return at most this many items and set 'has_more'
   *     (OPTIONAL, default: NULL = no limit)
   * @param array $field_settings
   *   Custom field configuration per field. Merges with defaults.
   *
   * @return array|null
   *   Formatted relationship data, or NULL if none. Structure:
   *   - 'title': Related bundle name.
   *   - 'field_name': The relation field name.
   *   - 'relation_bundle': The relation bundle machine name.
   *   - 'has_more': TRUE if there are more items than the limit allows.
   *   - 'items': Array of items, each containing fields as:
   *     ['value' => ..., 'url' => ..., 'is_fallback' => ...,
   *      'langcode' => ..., 'available_languages' => [...]]
   *     Plus '_is_fallback', '_langcode', '_available_languages',
   *     '_extra_fields'.
   *   - '_cache': CacheableMetadata object.
   */
  public function getFormattedRelationships(
    NodeInterface $node,
    string $relation_field_name,
    array $options = [],
    array $field_settings = []
  ): ?array {
    $limit = isset($options['limit']) ? (int) $options['limit'] : NULL;

    $relation_nodes = $this->loadRelationNodes($node, $relation_field_name);
    if (!$relation_nodes) {
      return NULL;
    }

    $relation_bundle = $this->getRelationBundle($node, $relation_field_name);
    if (!$relation_bundle) {
      return NULL;
    }

    $field_configs = $this->buildFieldConfigurations(
      $relation_bundle,
      $relation_field_name,
      $options,
      $field_settings
    );

    $rel_data = $this->dataBuilder->buildRelationshipData($relation_nodes, [
      'field_configs' => $field_configs,
      'viewing_node' => $node,
      'language' => $options['language'] ?? NULL,
      'language_fallback' => $options['language_fallback'] ?? FALSE,
    ]);

    $relationships = $rel_data['items'];
    $cache = $rel_data['cache'];
    $cache->addCacheTags(['node_list:' . $relation_bundle]);

    if (empty($relationships)) {
      return NULL;
    }

    $extra_fields = $this->getExtraFields($relation_field_name, $options);
    $items = $this->simplifyForTwig(
      $relationships,
      $options['include_links'] ?? TRUE,
      $extra_fields
    );

    // Apply limit after simplification so language filtering is already done.
    $has_more = FALSE;
    if ($limit !== NULL && count($items) > $limit) {
      $has_more = TRUE;
      $items = array_slice($items, 0, $limit);
    }

    return [
      'title'           => $this->extractRelatedBundle($relation_field_name),
      'field_name'      => $relation_field_name,
      'relation_bundle' => $relation_bundle,
      'has_more'        => $has_more,
      'items'           => $items,
      '_cache'          => $cache,
    ];
  }

  /**
   * Loads relation nodes from a field on a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\node\NodeInterface[]|null
   *   Array of relation nodes, or NULL if none.
   */
  protected function loadRelationNodes(NodeInterface $node, string $field_name): ?array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    $relation_nodes = [];
    foreach ($node->get($field_name) as $item) {
      if ($relation_node = $item->entity) {
        $relation_nodes[] = $relation_node;
      }
    }

    return $relation_nodes ?: NULL;
  }


  /**
   * Returns the relation bundle machine name from a field definition.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $field_name
   *   The field name.
   *
   * @return string|null
   *   The relation bundle machine name, or NULL if not found.
   */
  protected function getRelationBundle(NodeInterface $node, string $field_name): ?string {
    $field_def = $node->get($field_name)->getFieldDefinition();
    $target_bundles = $field_def->getSetting('handler_settings')['target_bundles'] ?? [];
    return reset($target_bundles) ?: NULL;
  }


  /**
   * Builds field configurations for the relationship data builder.
   *
   * @param string $relation_bundle
   *   The relation bundle machine name.
   * @param string $relation_field_name
   *   The relation field name.
   * @param array $options
   *   Options array from getFormattedRelationships().
   * @param array $field_settings
   *   Custom field settings to merge with defaults.
   *
   * @return array
   *   Field configurations keyed by field name.
   */
  protected function buildFieldConfigurations(
    string $relation_bundle,
    string $relation_field_name,
    array $options,
    array $field_settings = []
  ): array {
    $field_names = $this->configurator->getAvailableFieldNames($relation_bundle);

    $default_fields = $options['default_fields'] ?? ['calculated_related_id', 'calculated_relation_type_name'];
    $extra_fields = $this->getExtraFields($relation_field_name, $options);
    $fields_to_enable = array_merge($default_fields, $extra_fields);

    $field_configs = [];
    foreach ($field_names as $field_name) {
      if (in_array($field_name, $fields_to_enable)) {
        $defaults = [
          'field_name' => $field_name,
          'enabled' => TRUE,
          'display_mode' => 'link',
          'linkable' => TRUE,
          'is_calculated' => str_starts_with($field_name, 'calculated_'),
          'label' => $field_name,
          'weight' => 0,
          'hide_label' => TRUE,
          'multiple_separator' => ', ',
        ];

        $field_configs[$field_name] = array_merge($defaults, $field_settings[$field_name] ?? []);
      }
    }

    return $field_configs;
  }


  /**
   * Simplifies relationship data for Twig rendering.
   *
   * Always returns rich format: each field value is an array with 'value',
   * 'url', 'is_fallback', 'langcode', and 'available_languages' keys.
   *
   * @param array $relationships
   *   Relationship data from buildRelationshipData().
   * @param bool $include_links
   *   Whether to resolve entity references as links (populates 'url').
   * @param array $extra_fields
   *   Extra field names to place under '_extra_fields'.
   *
   * @return array
   *   Simplified items ready for Twig.
   */
  protected function simplifyForTwig(
    array $relationships,
    bool $include_links,
    array $extra_fields
  ): array {
    $simple = [];

    foreach ($relationships as $rel) {
      $item = ['_main' => [], '_extra' => []];
      $is_fallback = $rel['_is_fallback'] ?? FALSE;
      $effective_langcode = $rel['_langcode'] ?? NULL;
      $available_languages = $rel['_available_languages'] ?? [];

      foreach ($rel as $field_name => $field_data) {
        if (in_array($field_name, ['_relation_node', '_langcode', '_is_fallback', '_available_languages', '_related_nid'])) {
          continue;
        }

        $first_value = $field_data['field_values'][0] ?? NULL;
        if (!$first_value) {
          continue;
        }

        $clean_name = str_replace('calculated_', '', $field_name);
        $is_extra = !empty($extra_fields) && in_array($field_name, $extra_fields);
        $target_key = $is_extra ? '_extra' : '_main';

        $item[$target_key][$clean_name] = [
          'value' => $first_value['value'],
          'url' => $include_links ? ($first_value['link_url'] ?? NULL) : NULL,
        ];
      }

      if (!empty($item['_main'])) {
        $simple[] = array_merge($item['_main'], [
          '_extra_fields' => $item['_extra'],
          '_is_fallback' => $is_fallback,
          '_langcode' => $effective_langcode,
          '_available_languages' => $available_languages,
          '_related_nid' => $rel['_related_nid'] ?? NULL
        ]);
      }
    }

    return $simple;
  }


  /**
   * Returns extra fields for a specific relation field from options.
   *
   * @param string $relation_field_name
   *   The relation field name.
   * @param array $options
   *   Options array from getFormattedRelationships().
   *
   * @return array
   *   Extra field names, or empty array if none.
   */
  protected function getExtraFields(string $relation_field_name, array $options): array {
    return ($options['extra_fields'] ?? [])[$relation_field_name] ?? [];
  }


  /**
   * Extracts the related bundle name from a relation field name.
   *
   * Format: computed_relationshipfield__bundle1__bundle2
   * Returns bundle2 (always the related/target bundle).
   *
   * @param string $field_name
   *   The relation field name.
   *
   * @return string
   *   The related bundle name.
   */
  protected function extractRelatedBundle(string $field_name): string {
    $parts = explode('__', $field_name);
    return $parts[2] ?? '';
  }

}