<?php

namespace Drupal\relationship_nodes_search\FieldHelper;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\Field;
use Drupal\relationship_nodes_search\SearchAPI\Processor\RelationProcessorProperty;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;

/**
 * Service for working with nested fields in Search API indices.
 *
 * Provides utilities for:
 * - Field structure operations (paths, configurations, instances)
 * - Field capability checking (entity references, linkability)
 * - Field metadata (target types, widget support)
 * 
 * "Nested" refers to Elasticsearch nested objects that contain relationship
 * data indexed as child documents within parent entities.
 */
class NestedIndexFieldHelper {

  protected CalculatedFieldHelper $calculatedFieldHelper;

  /**
   * Constructs a NestedIndexFieldHelper object.
   *
   * @param CalculatedFieldHelper $calculatedFieldHelper
   *   The calculated field helper service.
   */
  public function __construct(CalculatedFieldHelper $calculatedFieldHelper) {
    $this->calculatedFieldHelper = $calculatedFieldHelper;
  }


  /**
   * Get comprehensive field capabilities and metadata.
   *
   * @return array
   *   Array with keys:
   *   - 'search_api_type': string (e.g., 'date', 'integer')
   *   - 'is_entity_reference': bool
   *   - 'target_type': string|null (e.g., 'node', 'taxonomy_term')
   *   - 'supports_range': bool
   *   - 'linkable': bool
   */
  public function getChildFieldCapabilities(
    Index $index, 
    string $parent_field, 
    string $child_field
  ): array {
    $type = $this->getChildFieldType($index, $parent_field, $child_field);
    
    return [
      'search_api_type' => $type,
      'is_entity_reference' => $this->childFieldIsEntityReference($index, $parent_field, $child_field),
      'target_type' => $this->getChildFieldTargetType($index, $parent_field, $child_field),
      'supports_range' => $this->childFieldSupportsRangeOperators($index, $parent_field, $child_field),
      'linkable' => $this->childFieldCanLink($index, $parent_field, $child_field),
    ];
  }


  /**
   * Validates and parses a nested field path.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $path
   *   The field path in "parent:child" format.
   *
   * @return array|null
   *   Array with 'parent' and 'child' keys, or NULL if invalid.
   */
  public function validateNestedPath(Index $index, string $path): ?array {
    if (strpos($path, ':') === FALSE) {
      return NULL;
    }
    
    [$parent_field, $child_field] = explode(':', $path, 2);
    $parent_field = trim($parent_field);
    $child_field = trim($child_field);

    if (empty($parent_field) || empty($child_field)) {
      return NULL;
    }

    // Validate child exists in parent
    $child_fields = $this->getAllNestedChildFieldNames($index, $parent_field);
    if (!in_array($child_field, $child_fields)) {
      return NULL;
    }

    // Validate field structure
    $field = $this->getIndexFieldInstance($index, $parent_field);
    if (!$field) {
      return NULL;
    }

    $property = $this->getNestedFieldProperty($field);
    if (!$property instanceof RelationProcessorProperty) {
      return NULL;
    }

    return ['parent' => $parent_field, 'child' => $child_field];
  }


  /**
   * Gets all nested child field names for a parent field.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $parent_field_name
   *   The parent field name.
   *
   * @return array
   *   Array of all child field names.
   */
  public function getAllNestedChildFieldNames(Index $index, string $parent_field_name): array {
    $field = $this->getIndexFieldInstance($index, $parent_field_name);

    if (!$field) {
      return [];
    }
    
    return array_keys($this->getAllNestedChildFieldsConfig($field));
  }


  /**
   * Gets the Search API data type of a nested child field.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $parent_field
   *   The parent field name.
   * @param string $child_field
   *   The child field name.
   *
   * @return string|null
   *   The Search API type ('integer', 'decimal', 'date', 'string', 'text', etc.)
   *   or NULL if field not found.
   */
  public function getChildFieldType(Index $index, string $parent_field, string $child_field): ?string {
    $sapi_fld = $index->getField($parent_field);
    if (!$sapi_fld instanceof Field || empty($sapi_fld->getConfiguration()['nested_fields'])) {
      return NULL;
    }
    $nested_fields = $sapi_fld->getConfiguration()['nested_fields'];
    if(empty($nested_fields[$child_field])) {
      return NULL;
    }

    return $nested_fields[$child_field]['type'] ?? NULL;
  }


  /**
   * Determines if a nested child field supports range operators.
   *
   * Range operators (>, <, >=, <=) are supported for numeric and date fields.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $parent_field
   *   The parent field name.
   * @param string $child_field
   *   The child field name.
   *
   * @return bool
   *   TRUE if the field supports range operators.
   */
  public function childFieldSupportsRangeOperators(Index $index, string $parent_field, string $child_field): bool {
    return in_array(
      $this->getChildFieldType($index, $parent_field, $child_field), 
      ['integer', 'decimal', 'date'], 
      TRUE
    );
  }



  /**
   * Gets the target entity type for a nested child entity reference field.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The parent field name.
   * @param string $child_fld_nm
   *   The child field name.
   *
   * @return string|null
   *   The target entity type (e.g., 'node', 'taxonomy_term'), or NULL.
   */
  public function getChildFieldTargetType(Index $index, string $sapi_fld_nm, string $child_fld_nm): ?string {
    $sapi_fld = $this->getIndexFieldInstance($index, $sapi_fld_nm);
    
    if (!$sapi_fld) {
      return NULL;
    }
    
    $property = $this->getNestedFieldProperty($sapi_fld);
    return $property ? $property->getDrupalFieldTargetType($child_fld_nm) : NULL;
  }


  /**
   * Determines if a nested field can be displayed as a link.
   *
   * A field can be linked if it's either:
   * - A calculated ID field
   * - An entity reference field
   *
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The parent field name.
   * @param string $child_fld_nm
   *   The child field name.
   *
   * @return bool
   *   TRUE if the field can be displayed as a link.
   */
  public function childFieldCanLink(Index $index, string $sapi_fld_nm, string $child_fld_nm): bool {
    if ($this->calculatedFieldHelper->isCalculatedChildField($child_fld_nm)) { 
      $calc_id_fields = $this->calculatedFieldHelper->getCalculatedFieldNames(NULL, 'id', TRUE);
      if (!in_array($child_fld_nm, $calc_id_fields)) {
        return FALSE;
      }
    } else {
      if (!$this->childFieldIsEntityReference($index, $sapi_fld_nm, $child_fld_nm)) {
        return FALSE;
      }
    }

    return TRUE;
  }


  /**
   * Converts a colon-separated path to dot-separated format.
   *
   * Converts Search API field paths ("parent:child") to Elasticsearch
   * paths ("parent.child").
   *
   * @param string $str
   *   The string to convert.
   *
   * @return string
   *   The converted string.
   */
  public function colonsToDots(string $str): string {
    return str_replace(':', '.', $str);
  }


  /**
   * Gets a field instance from an index.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $field_name
   *   The field name.
   *
   * @return Field|null
   *   The field instance, or NULL if not found.
   */
  protected function getIndexFieldInstance(Index $index, string $field_name): ?Field {
    $index_fields = $index->getFields();
  
    if (!isset($index_fields[$field_name])) {
      return NULL;
    }
    
    $field = $index_fields[$field_name];
    return $field instanceof Field ? $field : NULL;
  }


  /**
   * Gets the RelationProcessorProperty for a field.
   *
   * @param Field $field
   *   The Search API field.
   *
   * @return RelationProcessorProperty|null
   *   The property object, or NULL if not found.
   */
  protected function getNestedFieldProperty(Field $field): ?RelationProcessorProperty {
    $property = $field->getDataDefinition();
    return $property instanceof RelationProcessorProperty ? $property : NULL;
  }


  /**
   * Gets all nested child field configuration.
   *
   * @param Field $field
   *   The Search API field.
   *
   * @return array
   *   Configuration array of nested child fields.
   */
  protected function getAllNestedChildFieldsConfig(Field $field): array {
    if (!$this->isNestedField($field)) {
      return [];
    }
    
    $config = $field->getConfiguration();
    return is_array($config) && isset($config['nested_fields']) ? $config['nested_fields'] : [];
  }


  /**
   * Checks if a field is a nested parent field containing child fields.
   *
   * @param Field $field
   *   The Search API field.
   *
   * @return bool
   *   TRUE if the field contains nested fields.
   */
  protected function isNestedField(Field $field): bool {
    $config = $field->getConfiguration() ?? [];
    return is_array($config) && !empty($config['nested_fields']);
  }


    /**
   * Checks if a nested field is an entity reference.
   *
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   The parent field name.
   * @param string $child_fld_nm
   *   The child field name.
   *
   * @return bool
   *   TRUE if the field is an entity reference.
   */
  protected function childFieldIsEntityReference(Index $index, string $sapi_fld_nm, string $child_fld_nm): bool {
    $sapi_fld = $this->getIndexFieldInstance($index, $sapi_fld_nm);

    if (!$sapi_fld) {
      return FALSE;
    }
    
    $property = $this->getNestedFieldProperty($sapi_fld);
    return $property ? $property->drupalFieldIsReference($child_fld_nm) : FALSE;
  }
}