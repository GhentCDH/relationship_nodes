<?php

namespace Drupal\relationship_nodes\RelationData\TermHelper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\taxonomy\TermInterface;

/**
 * Service for automatically updating mirror term links.
 */
class MirrorSync {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FieldNameResolver $fieldNameResolver;


  /**
   * Constructs a MirrorSync object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FieldNameResolver $fieldNameResolver   
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fieldNameResolver = $fieldNameResolver;
  }


  /**
   * Gets the mirror term ID for a term.
   *
   * @param TermInterface $term
   *   The term.
   * @param string $field
   *   The field name.
   * @param bool $original
   *   Whether to get the original value.
   *
   * @return int|null
   *   The mirror term ID or NULL.
   */
  public function getMirrorTermId(TermInterface $term, string $field, bool $original = false): ?int {
    if ($original) {
      if (!isset($term->original)) {
        return null;
      }
      $term = $term->original;
    }
    return $term->$field->target_id ?? null;
  }


  /**
   * Gets changes in mirror term references.
   *
   * @param TermInterface $term
   *   The term.
   * @param string $field
   *   The field name.
   *
   * @return array|null
   *   Array with 'original' and 'current' keys, or NULL if unchanged.
   */
  private function getMirrorTermChanges(TermInterface $term, string $field): ?array {
    $orig_id = $this->getMirrorTermId($term, $field, true) ?? null;
    $current_id = $this->getMirrorTermId($term, $field) ?? null;
    return $orig_id === $current_id ? null : ['original'=> $orig_id, 'current'=> $current_id];
  }

  
  /**
   * Loads a taxonomy term by ID.
   *
   * @param int $id
   *   The term ID.
   *
   * @return TermInterface|null
   *   The loaded term or NULL.
   */
  private function loadTerm(int $id): ?TermInterface {
    $tax_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = $tax_storage->load($id);
    return $term instanceof TermInterface ? $term : null;
  }


  /**
   * Sets mirror term links when a term is created, updated, or deleted.
   *
   * @param TermInterface $term
   *   The term.
   * @param string $hook
   *   The hook name ('insert', 'update', or 'delete').
   */
  public function setMirrorTermLink(TermInterface $term, string $hook): void {
    $ref_field = $this->fieldNameResolver->getMirrorFields('entity_reference');  
    if (empty($ref_field)) {
      return;
    }   

    $changes = $this->getMirrorTermChanges($term, $ref_field);

    if (!$changes) {
      return;
    }

    $term_id = $term->id();
    foreach ($changes as $key => $id) {
      if(!$id){
        continue;
      }
      $linked_term = $this->loadTerm($id);
      if (!$linked_term) {
        continue;
      }
      if ($key === 'original') {
        $linked_term->$ref_field->target_id = null;
          
      } elseif ($hook !== 'delete') {
        $linked_term->$ref_field->target_id = $term_id;
      }
      $linked_term->save();        
    }
  }
}