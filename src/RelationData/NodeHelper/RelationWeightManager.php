<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;

/**
 * Service for managing relationship node weights using Key-Value storage.
 */
class RelationWeightManager {

  protected KeyValueFactoryInterface $keyValueFactory;
  protected ?KeyValueStoreInterface $store = NULL;
  protected ?FieldNameResolver $fieldNameResolver = NULL;

  public function __construct(KeyValueFactoryInterface $key_value_factory, ?FieldNameResolver $field_name_resolver = NULL) {
    $this->keyValueFactory = $key_value_factory;
    $this->fieldNameResolver = $field_name_resolver;
  }


  /**
   * Gets the Key-Value store for a specific reference field.
   * 
   * Store structure: relation_weights.{relation_nid}.{reference_field_name}
   */
  protected function getStore(): KeyValueStoreInterface {
    if ($this->store === NULL) {
      $this->store = $this->keyValueFactory->get('relationship_nodes_weights');
    }
    return $this->store;
  }
  

  /**
   * Generates a storage key.
   */
  protected function getKey(int $relation_nid, string $reference_field_name): string {
    return "{$relation_nid}.{$reference_field_name}";
  }


  /**
   * Gets the weight for a relation via a specific reference field.
   *
   * @param int $relation_nid
   *   The relation node ID.
   * @param string $reference_field_name
   *   The field name that references the parent (e.g., 'field_article').
   *
   * @return int
   *   The weight value.
   */
  public function getWeight(int $relation_nid, string $reference_field_name): int {
    $key = $this->getKey($relation_nid, $reference_field_name);
    $weight = (int) $this->getStore()->get($key, 9999);
    return $weight;
  }


  /**
   * Sets the weight for a relation via a specific reference field.
   *
   * @param int $relation_nid
   *   The relation node ID.
   * @param string $reference_field_name
   *   The field name that references the parent.
   * @param int $weight
   *   The weight value.
   */
  public function setWeight(int $relation_nid, string $reference_field_name, int $weight): void {
    $key = $this->getKey($relation_nid, $reference_field_name);
    $this->getStore()->set($key, $weight);
  }


  /**
   * Deletes weight for a relation via a specific reference field.
   *
   * @param int $relation_nid
   *   The relation node ID.
   * @param string $reference_field_name
   *   The reference field name.
   */
  public function deleteWeight(int $relation_nid, string $reference_field_name): void {
    $key = $this->getKey($relation_nid, $reference_field_name);
    $this->getStore()->delete($key);
  }


  /**
   * Deletes all weights for a relation node (all reference contexts).
   *
   * @param int $relation_nid
   *   The relation node ID.
   */
  public function deleteAllWeights(int $relation_nid): void {
    // Weights are stored per related entity field; delete those keys directly
    // instead of loading the whole collection.
    if ($this->fieldNameResolver) {
      $keys = array_map(fn($field) => $this->getKey($relation_nid, $field), array_values($this->fieldNameResolver->getRelatedEntityFields()));
      $this->getStore()->deleteMultiple($keys);
      return;
    }
    $prefix = $relation_nid . '.';
    $store = $this->getStore();
    foreach (array_keys($store->getAll()) as $key) {
      if (str_starts_with($key, $prefix)) {
        $store->delete($key);
      }
    }
  }



  /**
   * Gets multiple weights for different relations via the same reference field.
   *
   * @param array $relation_nids
   *   Array of relation node IDs.
   * @param string $reference_field_name
   *   The reference field name.
   *
   * @return array
   *   Keyed array of relation_nid => weight.
   */
  public function getMultiple(array $relation_nids, string $reference_field_name): array {
    $keys = [];
    foreach ($relation_nids as $nid) {
      $keys[$nid] = $this->getKey((int) $nid, $reference_field_name);
    }
    $stored = $keys ? $this->getStore()->getMultiple(array_values($keys)) : [];
    $weights = [];
    foreach ($keys as $nid => $key) {
      $weights[$nid] = (int) ($stored[$key] ?? 9999);
    }
    return $weights;
  }


  
  /**
   * Returns all stored weights across all relations and reference fields.
   *
   * @return array
   *   All key-value pairs in the weights store.
   */
  public function getAllWeights(): array {
    return $this->getStore()->getAll();
  }

  /**
   * Sorts node IDs by their weights for a specific reference field.
   *
   * @param array $relations_by_field
   *   Array of relation node IDs : ['field_1' => [rel_id_A => rel_ent_A, rel_id_B => rel_ent_B], 'field_2' => [rel_id_C => rel_ent_C]]
   *
   * @return array
   *   Sorted array of node IDs.
   */
  public function sortByWeight(array $relations_by_field): array {
    if (empty($relations_by_field)) {
      return [];
    }
    
    // Flatten met weight info
    $all_relations = [];
    
    // Read all weights in one query.
    $keys = [];
    foreach ($relations_by_field as $field => $relations) {
      foreach (array_keys($relations) as $rel_id) {
        $keys[] = $this->getKey((int) $rel_id, $field);
      }
    }
    $stored = $keys ? $this->getStore()->getMultiple($keys) : [];

    foreach($relations_by_field as $field => $relations){
      foreach($relations as $rel_id => $rel_ent){
        $all_relations[$rel_id] = [
          'entity' => $rel_ent,
          'weight' => (int) ($stored[$this->getKey((int) $rel_id, $field)] ?? 9999),
        ];
      }
    }
    
    // Sort by weight (0, 1, 2... dan 9999, 9999, 9999...)
    uasort($all_relations, function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });
    
    // Extract just the entities
    return array_map(fn($item) => $item['entity'], $all_relations);
  }
}