<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Processor;

use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Processor property for relationship fields with nested field definitions.
 *
 * Provides property definitions for relationship node fields and manages
 * calculated fields for Search API indexing.
 */
class RelationProcessorProperty extends ProcessorProperty implements ComplexDataDefinitionInterface{

  protected ?array $propertyDefinitions = NULL;
  protected ?array $drupalFieldInfo = NULL;
  protected EntityFieldManagerInterface $entityFieldManager;
  protected LoggerInterface $logger;
  protected ?array $calculatedFieldNames = NULL;


  /**
   * Constructs a RelationProcessorProperty object.
   *
   * @param array $definition
   *   The property definition.
   * @param EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param LoggerInterface $logger
   *   The logger service.
   * @param array|null $calculatedFieldNames
   *   Optional array of calculated field names.
   */
  public function __construct(
    array $definition, 
    EntityFieldManagerInterface $entityFieldManager,
    LoggerInterface $logger,
    ?array $calculatedFieldNames = NULL
  ) {
    parent::__construct($definition);
    $this->entityFieldManager = $entityFieldManager;
    $this->logger = $logger;
    $this->calculatedFieldNames = $calculatedFieldNames;
  }


  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(): array {
    if ($this->propertyDefinitions !== NULL) {
      return $this->propertyDefinitions;
    }

    $this->propertyDefinitions = [];
    $this->drupalFieldInfo = [];

    $bundle = $this->getBundle();

    if (!$bundle) {
      return $this->propertyDefinitions;
    }

    try {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading field definitions: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->propertyDefinitions;
    }

    foreach ($field_definitions as $field_name => $field_definition) {
      if (!$field_definition instanceof FieldConfig) {
        continue;
      }

      $drupal_type = $field_definition->getType();
      $search_api_type = $this->mapDrupalFieldTypeToSearchApiType($drupal_type);
      $label = $this->convertToString($field_definition->getLabel());
      $description = $this->convertToString($field_definition->getDescription());

      $property = DataDefinition::create($search_api_type)
        ->setLabel($label)
        ->setDescription($description);

      $this->propertyDefinitions[$field_name] = $property;

      $this->drupalFieldInfo[$field_name] = [
        'machine_name' => $field_name,
        'type' => $drupal_type,
      ];
      
      if ($drupal_type === 'entity_reference') {
        $settings = $field_definition->getSettings();
        $target_type = $settings['target_type'] ?? 'node';
        $this->drupalFieldInfo[$field_name]['target_type'] = $target_type;  
      }
    }
    $this->addCalculatedFieldDefinitions();

    return $this->propertyDefinitions;
  }


  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinition($name): ?DataDefinitionInterface {
    $definitions = $this->getPropertyDefinitions();
    return $definitions[$name] ?? NULL;
  }


  /**
   * {@inheritdoc}
   */
  public function getMainPropertyName(): ?string {
    return NULL;
  }


  /**
   * {@inheritdoc}
   */
  public function getProcessorId(): ?string {
    return $this->definition['processor_id'] ?? NULL;
  }


  /**
   * Gets the bundle from the definition.
   *
   * @return string|null
   *   The bundle machine name, or NULL if not set.
   */
  public function getBundle(): ?string {
    return $this->definition['definition_class_settings']['bundle'] ?? NULL;
  }


  /**
   * {@inheritdoc}
   */
  public function getDataType(): string {
    return 'string';
  }


  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    $label = $this->definition['label'] ?? '';
    if (is_object($label) && method_exists($label, '__toString')) {
      return (string) $label;
    }
    return is_string($label) ? $label : '';
  }


  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    $description = $this->definition['description'] ?? '';
    if (is_object($description) && method_exists($description, '__toString')) {
      return (string) $description;
    }
    return is_string($description) ? $description : '';
  }


  /**
   * Gets Drupal field information for a specific field.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return array|null
   *   Field information array containing machine_name, type, and optionally
   *   target_type for entity references, or NULL if not found.
   */
  public function getDrupalFieldInfo(string $field_name): ?array {
    if ($this->drupalFieldInfo === NULL) {
      $this->getPropertyDefinitions();
    }
    
    return $this->drupalFieldInfo[$field_name] ?? NULL;
  }


  /**
   * Checks if a Drupal field is an entity reference.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field is an entity reference, FALSE otherwise.
   */
  public function drupalFieldIsReference(string $field_name): bool {
      $field_info = $this->getDrupalFieldInfo($field_name);
      return isset($field_info['type']) && $field_info['type'] === 'entity_reference';
  }


  /**
   * Gets the target entity type for an entity reference field.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string|null
   *   The target entity type, or NULL if not a reference field.
   */
  public function getDrupalFieldTargetType(string $field_name): ?string {
    if (!$this->drupalFieldIsReference($field_name)) {
        return NULL;
    }  
    $field_info = $this->getDrupalFieldInfo($field_name);  
    return $field_info['target_type'] ?? NULL;
  } 


  /**
   * Builds nested fields configuration for selected fields.
   *
   * Creates configuration array with type, label, and Drupal field info
   * for each selected field. Automatically includes calculated fields.
   *
   * @param array $selected_fields
   *   Array of selected field names.
   *
   * @return array
   *   Configuration array for nested fields with structure:
   *   - type: Search API data type
   *   - label: Field label
   *   - drupal_field: Array with machine_name, type, and optionally target_type
   */
  public function buildNestedFieldsConfig(array $selected_fields): array {
    if ($this->calculatedFieldNames === NULL) {
      return [];
    }

    $config = [];
    $definitions = $this->getPropertyDefinitions();
    $selected_fields = array_merge($selected_fields, $this->calculatedFieldNames);
    foreach ($selected_fields as $field_name) {
      if (!isset($definitions[$field_name])) {
        continue;
      }
      
      $definition = $definitions[$field_name];
      $config[$field_name] = [
        'type' => $definition->getDataType(),
        'label' => $this->convertToString($definition->getLabel()),
      ];

      $drupal_field_info = $this->getDrupalFieldInfo($field_name);
      if ($drupal_field_info) {
        $config[$field_name]['drupal_field'] = $drupal_field_info;
      }
    }
    return $config;
  }


  /**
   * Adds calculated field definitions to property definitions.
   *
   * Creates property definitions for all calculated fields with appropriate
   * settings (hidden, readonly, is_calculated).
   */
  protected function addCalculatedFieldDefinitions(): void {

    if ($this->calculatedFieldNames === NULL) {
      return;
    }

    foreach ($this->calculatedFieldNames as $field_name) {  
      if (isset($this->propertyDefinitions[$field_name])) {
        continue;
      }
      $property = DataDefinition::create('string')
        ->setLabel($field_name)
        ->setDescription('Calculated field')
        ->setSetting('hidden', TRUE)
        ->setSetting('readonly', TRUE)
        ->setSetting('is_calculated', TRUE);
      $this->propertyDefinitions[$field_name] = $property;
    }
  }


  /**
   * Maps Drupal field type to Search API type.
   *
   * @param string $drupal_type
   *   The Drupal field type.
   *
   * @return string
   *   The corresponding Search API type.
   */
  protected function mapDrupalFieldTypeToSearchApiType(string $drupal_type): string {
    $type_map = [
      'entity_reference' => 'string',
      'integer' => 'integer',
      'decimal' => 'decimal',
      'float' => 'decimal',
      'boolean' => 'boolean',
      'datetime' => 'date',
      'timestamp' => 'date',
      'string' => 'string',
      'string_long' => 'text',
      'text' => 'text',
      'text_long' => 'text',
      'text_with_summary' => 'text',
      'list_string' => 'string',
      'list_integer' => 'integer',
      'list_float' => 'decimal',
    ];
    
    return $type_map[$drupal_type] ?? 'string';
  }


  /**
   * Converts a value to string safely.
   *
   * Handles TranslatableMarkup objects and other types that may have
   * __toString() methods.
   *
   * @param mixed $value
   *   The value to convert.
   *
   * @return string
   *   The string representation.
   */
  protected function convertToString($value): string {
    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }
    return is_string($value) ? $value : '';
  }

}