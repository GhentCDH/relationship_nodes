<?php

/**
 * Implements hook_views_data_alter().
 */
function relationship_nodes_search_views_data_alter(array &$data): void {
  foreach ($data as $table_name => &$table) {
    $is_index = str_starts_with($table_name, 'search_api_index_');
    $is_datasource = str_starts_with($table_name, 'search_api_datasource_');
    $is_node = str_starts_with($table_name, 'search_api_entity_node_');

    if (!$is_index && !$is_datasource && !$is_node) {
      continue;
    }

    $fields_to_remove = [];

    foreach ($table as $field_name => &$field) {
      if ($field_name === 'table' || !is_array($field)) {
        continue;
      }

      $real_field = $field['real field'] ?? $field_name;

      $is_computed = str_contains($field_name, 'computed_relationshipfield')
        || str_contains($real_field, 'computed_relationshipfield');
      $is_nested = (str_starts_with($field_name, 'relationship_info_') && str_ends_with($field_name, '_nested'))
        || (str_starts_with($real_field, 'relationship_info_') && str_ends_with($real_field, '_nested'));

      if (!$is_computed && !$is_nested) {
        continue;
      }

      if ($is_node || $is_datasource) {
        $fields_to_remove[] = $field_name;
      }
      elseif ($is_index) {
        if (isset($field['field'])) {
          $field['field']['id'] = 'search_api_relationship_field';
        }
        $field['filter'] = [
          'id' => 'search_api_relationship_filter',
          'allow empty' => TRUE,
        ];
        unset($field['sort'], $field['argument']);
      }
    }
    unset($field);

    foreach ($fields_to_remove as $field_name) {
      unset($data[$table_name][$field_name]);
    }
  }
  unset($table);
}