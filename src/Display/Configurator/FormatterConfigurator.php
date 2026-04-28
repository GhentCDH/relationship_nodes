<?php

namespace Drupal\relationship_nodes\Display\Configurator;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;

/**
 * Configuration builder for field formatters.
 * 
 * Extends FieldConfiguratorBase with formatter-specific context building.
 * Uses Drupal's field definition system (not Search API) to determine field
 * capabilities and prepares configurations for relationship field display.
 * 
 * Key responsibilities:
 * - Extract available fields from relation bundles
 * - Replace internal fields (rn_entity_a, rn_entity_b, rn_relation_type) with
 *   calculated equivalents (calculated_related_id, calculated_relation_type_name)
 * - Determine which fields support entity linking (entity references)
 * - Prepare field configurations for template rendering
 * 
 * Symmetric to Views configurators but operates on Drupal entities rather than
 * Search API indexed data.
 */
class FormatterConfigurator extends FieldConfiguratorBase {

  protected EntityFieldManagerInterface $entityFieldManager;
  protected CalculatedFieldHelper $calculatedFieldHelper;

  /**
   * Constructs a FormatterConfigurator object.
   *
   * @param \Drupal\relationship_nodes\RelationField\FieldNameResolver $fieldNameResolver
   *   The field name resolver service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager service.
   * @param \Drupal\relationship_nodes\RelationField\CalculatedFieldHelper $calculatedFieldHelper
   *   The calculated field helper service.
   */
  public function __construct(
    FieldNameResolver $fieldNameResolver,
    EntityFieldManagerInterface $entityFieldManager,
    CalculatedFieldHelper $calculatedFieldHelper
  ) {
    parent::__construct($fieldNameResolver);
    $this->entityFieldManager = $entityFieldManager;
    $this->calculatedFieldHelper = $calculatedFieldHelper;
  }

  /**
   * Gets available field names for a relation bundle.
   * 
   * Replaces internal relationship fields with calculated equivalents:
   * - rn_entity_a, rn_entity_b → calculated_related_id
   * - rn_relation_type → calculated_relation_type_name
   * 
   * These calculated fields are resolved at render time by
   * RelationshipDataBuilder based on viewing context (which entity
   * is viewing the relationships).
   * 
   * Validates that the bundle has all required relationship infrastructure
   * fields before processing. Returns empty array if validation fails.
   *
   * @param string $relation_bundle
   *   The relation node bundle machine name.
   *
   * @return array
   *   Array of available field names including calculated fields, or empty
   *   array if bundle is misconfigured. Field names are indexed numerically.
   *   Example: ['calculated_related_id', 'field_custom_data', 'field_notes']
   */
  public function getAvailableFieldNames(string $relation_bundle): array {
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $relation_bundle);
    
    if (empty($field_definitions)) {
      return [];
    }
    
    // Extract configurable field names only
    $all_field_names = $this->extractConfigurableFieldNames($field_definitions);
    
    if (empty($all_field_names)) {
      return [];
    }
    
    // Validate relationship structure
    if (!$this->validateRelationshipStructure($all_field_names)) {
      return [];
    }

    // Replace internal fields with calculated variants
    return $this->replaceWithCalculatedFields($all_field_names);
  }

  /**
   * Prepares field configurations for formatter context.
   * 
   * Main entry point for formatter configuration. Builds complete field
   * configuration arrays including:
   * - Which fields are calculated (resolved at runtime)
   * - Which fields support entity linking (entity references)
   * - Field metadata (labels, weights, display modes, etc.)
   * 
   * These configurations are used by the parent's buildConfigurationForm()
   * to render the formatter settings form.
   *
   * @param string $relation_bundle
   *   The relation node bundle machine name.
   * @param array $field_names
   *   Available field names from getAvailableFieldNames().
   * @param array $saved_settings
   *   Current formatter settings from field configuration.
   *   Contains 'field_settings', 'sort_by_field', 'group_by_field'.
   *
   * @return array
   *   Field configurations keyed by field name. Each configuration contains:
   *   - field_name: (string) The field machine name
   *   - linkable: (bool) Whether field supports entity linking
   *   - is_calculated: (bool) Whether field is calculated at runtime
   *   - enabled: (bool) Whether field is enabled in settings
   *   - label: (string) Human-readable label
   *   - weight: (int) Display order weight
   *   - display_mode: (string) raw|label|link
   *   - hide_label: (bool) Whether to hide label in output
   *   - multiple_separator: (string) Separator for multiple values
   */
  public function prepareFormatterFieldConfigurations(
    string $relation_bundle,
    array $field_names,
    array $saved_settings
  ): array {
    $calculated_fields = array_filter(
      $field_names, 
      [$this->calculatedFieldHelper, 'isCalculatedChildField']
    );
    
    $context = [
      'linkable_fields' => $this->getLinkableFields($relation_bundle, $field_names),
      'calculated_fields' => $calculated_fields,
    ];
    
    return $this->prepareFieldConfigurations($field_names, $saved_settings, $context);
  }

  /**
   * Gets fields that support entity reference linking.
   * 
   * Determines which fields can be rendered as links to entities.
   * 
   * For calculated fields:
   * - Checks if field has a target entity type (only ID fields, not name fields)
   * - Example: calculated_related_id → linkable, calculated_related_name → not linkable
   * 
   * For real fields:
   * - Checks if field type is entity_reference
   * - Example: field_custom_ref → linkable, field_text → not linkable
   *
   * @param string $relation_bundle
   *   The relation node bundle machine name.
   * @param array $field_names
   *   Available field names to check for linkability.
   *
   * @return array
   *   Array of field names that support entity linking.
   *   Example: ['calculated_related_id', 'field_organization_ref']
   */
  protected function getLinkableFields(string $relation_bundle, array $field_names): array {
    $linkable = [];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $relation_bundle);
    
    if (empty($field_definitions)) {
      return [];
    }
    
    foreach ($field_names as $field_name) {
      // Check calculated fields via target type
      if ($this->calculatedFieldHelper->isCalculatedChildField($field_name)) {
        // Only ID fields (not name fields) have target types and are linkable
        if (empty($this->calculatedFieldHelper->getCalculatedFieldTargetType($field_name))) {
          continue;
        }
      } 
      // Check real fields via field type
      elseif (
        empty($field_definitions[$field_name]) || 
        $field_definitions[$field_name]->getType() !== 'entity_reference'
      ) {
        continue;
      }
      
      $linkable[] = $field_name;
    }
    
    return $linkable;
  }

  /**
   * Extracts configurable field names from field definitions.
   * 
   * Filters field definitions to include only configurable fields (FieldConfig
   * instances), excluding base fields like nid, title, created, etc.
   * 
   * Configurable fields are fields added via Field UI or configuration,
   * typically prefixed with field_* or rn_*.
   *
   * @param array $field_definitions
   *   Array with field definitions from EntityFieldManager::getFieldDefinitions().
   *
   * @return array
   *   Array of configurable field machine names.
   *   Example: ['rn_entity_a', 'rn_entity_b', 'field_custom_data']
   */
  protected function extractConfigurableFieldNames(array $field_definitions): array {
    $field_names = [];
    
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition instanceof FieldConfig) {
        $field_names[] = $field_name;
      }
    }
    
    return $field_names;
  }

  /**
   * Validates that relationship structure fields exist.
   * 
   * Checks that all required relationship infrastructure fields are present
   * in the bundle:
   * - rn_entity_a: First related entity reference
   * - rn_entity_b: Second related entity reference
   * 
   * These fields are essential for the relationship system to function.
   * If they're missing, the bundle is not properly configured as a relation
   * bundle.
   *
   * @param array $field_names
   *   Available field names to validate.
   *
   * @return bool
   *   TRUE if all required fields exist, FALSE if misconfigured.
   */
  protected function validateRelationshipStructure(array $field_names): bool {
    $related_entity_fields = $this->fieldNameResolver->getRelatedEntityFields();
    
    foreach ($related_entity_fields as $required_field) {
      if (!in_array($required_field, $field_names)) {
        return FALSE;
      }
    }
    
    return TRUE;
  }

  /**
   * Replaces internal fields with calculated field variants.
   * 
   * Internal relationship infrastructure fields are replaced with calculated
   * equivalents that are resolved at render time based on viewing context:
   * 
   * Replacements:
   * - rn_relation_type → calculated_relation_type_name
   *   Shows the relation type from the perspective of the viewing entity
   * 
   * - rn_entity_a, rn_entity_b → calculated_related_id
   *   Shows the "other" entity in the relationship (if viewing from A, shows B)
   * 
   * The calculated fields are placed at the beginning of the field list.
   * 
   * Note: Only replaces relation type field if it exists in the bundle.
   * Some relation bundles may not have typed relationships.
   *
   * @param array $field_names
   *   Original field names including internal fields.
   *
   * @return array
   *   Field names with internal fields replaced by calculated variants.
   *   Example input:  ['rn_entity_a', 'rn_entity_b', 'rn_relation_type', 'field_notes']
   *   Example output: ['calculated_related_id', 'calculated_relation_type_name', 'field_notes']
   */
  protected function replaceWithCalculatedFields(array $field_names): array {
    $relation_type_field = $this->fieldNameResolver->getRelationTypeField();
    
    // Replace relation type field if present
    if (in_array($relation_type_field, $field_names)) {
      $field_names = array_diff($field_names, [$relation_type_field]);
      $calc_relation_fields = $this->calculatedFieldHelper->getCalculatedFieldNames('relation_type', NULL, TRUE);
      $field_names = array_merge($calc_relation_fields, $field_names);
    }
    
    // Replace related entity fields (always present)
    $related_entity_fields = $this->fieldNameResolver->getRelatedEntityFields();
    $field_names = array_diff($field_names, $related_entity_fields);
    $calc_related_fields = $this->calculatedFieldHelper->getCalculatedFieldNames('related_entity', 'id', TRUE);
    $field_names = array_merge($calc_related_fields, $field_names);
    
    return array_values($field_names);
  }

  /**
   * Formats a field name into human-readable label.
   * 
   * Delegates to CalculatedFieldHelper for calculated fields to ensure
   * consistent labeling across the system. Falls back to parent's generic
   * formatting for regular fields.
   * 
   * Examples:
   * - calculated_related_id → "Related id"
   * - calculated_relation_type_name → "Relation type name"
   * - field_custom_data → "Custom Data"
   * - rn_notes → "Notes"
   *
   * @param string $field_name
   *   The field machine name.
   *
   * @return string
   *   Formatted human-readable label.
   */
  public function formatFieldLabel(string $field_name): string {
    if ($this->calculatedFieldHelper->isCalculatedChildField($field_name)) {
      return $this->calculatedFieldHelper->formatCalculatedFieldLabel($field_name);
    }
    
    return parent::formatFieldLabel($field_name);
  }
}