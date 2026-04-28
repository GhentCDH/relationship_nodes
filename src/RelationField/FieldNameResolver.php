<?php

namespace Drupal\relationship_nodes\RelationField;


/**
 * Service for resolving relationship node field names.
 *
 * Provides access to configured field names for relationship functionality.
 */
class FieldNameResolver {

  private const FIELD_NAMES = [
    'related_entity_fields' => [
      'related_entity_1' => 'rn_related_entity_1',
      'related_entity_2'=> 'rn_related_entity_2',
    ],
    'relation_type' => 'rn_relation_type',
    'mirror_fields' => [
      'mirror_entity_reference' => 'rn_mirror_reference',
      'mirror_string' => 'rn_mirror_string',
    ],
  ];


  /**
   * Gets the relation type field name.
   *
   * @return string
   *   The field name.
   */
  public function getRelationTypeField(): string {
    return $this->getConfig('relation_type');
  }


  /**
   * Gets related entity field names.
   *
   * @param int|null $no
   *   Optional field number (1 or 2) to get specific field.
   *
   * @return array|string
   *   Array of field names or single field name.
   */
  public function getRelatedEntityFields(?int $no = null): array|string {
    $fields = $this->getConfig('related_entity_fields') ?? [];
    if ($no === 1 || $no === 2) {
      return array_values($fields)[$no - 1] ?? '';
    }    
    return $fields;
  }


  /**
   * Gets mirror field names.
   *
   * @param string|null $type
   *   Optional mirror type ('string' or 'entity_reference').
   *
   * @return array|string
   *   Array of field names or single field name.
   */
  public function getMirrorFields(?string $type = NULL): array|string {
    $options = ['string' => 'mirror_string', 'entity_reference' => 'mirror_entity_reference'];
    $fields  = $this->getConfig('mirror_fields')?? [];
    if ($type !== NULL && isset($options[$type])) {
      return $fields[$options[$type]] ?? '';
    }
    return $fields;
  }


  /**
   * Gets the opposite related entity field name.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string|null
   *   The opposite field name or NULL.
   */
  public function getOppositeRelatedEntityField(string $field_name): ?string {
    return match($field_name) {
      $this->getRelatedEntityFields(1) => $this->getRelatedEntityFields(2),
      $this->getRelatedEntityFields(2) => $this->getRelatedEntityFields(1),
      default => NULL
    };
  }


  /**
   * Gets the opposite mirror field name.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string|null
   *   The opposite field name or NULL.
   */
  public function getOppositeMirrorField(string $field_name): ?string {
    return match($field_name) {
      $this->getMirrorFields('string') => $this->getMirrorFields('entity_reference'),
      $this->getMirrorFields('entity_reference') => $this->getMirrorFields('string'),
      default => NULL
    };
  }


    /**
   * Gets all relationship node field names.
   *
   * @return array
   *   Array of all field names.
   */
  public function getAllRelationFieldNames(): array {
    $fields = [];
    $relation_type = $this->getRelationTypeField();
    if ($relation_type) {
      $fields[] = $relation_type;
    }
    $related = $this->getRelatedEntityFields();
    if (!empty($related)) {
      $fields = array_merge($fields, array_values($related));
    }
    $mirror = $this->getMirrorFields();
    if (!empty($mirror)) {
      $fields = array_merge($fields, array_values($mirror));
    }
    return $fields;
  }


  /**
   * Gets configuration value by key.
   *
   * @param string|null $key
   *   Optional configuration key.
   *
   * @return string|array|null
   *   The configuration value or NULL.
   */
  public function getConfig(?string $key = NULL): string|array|NULL {
    $config = self::FIELD_NAMES;
    if ($key == NULL) {
      return $config;
    }
    if (!isset($config[$key])) {
      return NULL;
    }
    return $config[$key];
  }
  
}