<?php

namespace Drupal\relationship_nodes\RelationData\TermHelper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\relationship_nodes\Entity\OriginalEntityTrait;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\taxonomy\TermInterface;

/**
 * Service for automatically updating mirror term links.
 */
class MirrorSync {

  use OriginalEntityTrait;

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FieldNameResolver $fieldNameResolver;

  /**
   * Constructs a MirrorSync object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\relationship_nodes\RelationField\FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FieldNameResolver $fieldNameResolver,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fieldNameResolver = $fieldNameResolver;
  }

  /**
   * Gets the mirror term ID for a term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   * @param string $field
   *   The field name.
   * @param bool $original
   *   Whether to get the original value.
   *
   * @return int|null
   *   The mirror term ID or NULL.
   */
  public function getMirrorTermId(TermInterface $term, string $field, bool $original = FALSE): ?int {
    if ($original) {
      $term = $this->getOriginalEntity($term);
      if (!$term instanceof TermInterface) {
        return NULL;
      }
    }
    return $term->$field->target_id ?? NULL;
  }

  /**
   * Gets changes in mirror term references.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   * @param string $field
   *   The field name.
   *
   * @return array|null
   *   Array with 'original' and 'current' keys, or NULL if unchanged.
   */
  private function getMirrorTermChanges(TermInterface $term, string $field): ?array {
    $orig_id = $this->getMirrorTermId($term, $field, TRUE) ?? NULL;
    $current_id = $this->getMirrorTermId($term, $field) ?? NULL;
    return $orig_id === $current_id ? NULL : ['original' => $orig_id, 'current' => $current_id];
  }

  /**
   * Loads a taxonomy term by ID.
   *
   * @param int $id
   *   The term ID.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The loaded term or NULL.
   */
  private function loadTerm(int $id): ?TermInterface {
    $tax_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = $tax_storage->load($id);
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Sets mirror term links when a term is created, updated, or deleted.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   * @param string $hook
   *   The hook name ('insert', 'update', or 'delete').
   */
  public function setMirrorTermLink(TermInterface $term, string $hook): void {
    $ref_field = $this->fieldNameResolver->getMirrorFields('entity_reference');
    if (empty($ref_field)) {
      return;
    }

    $term_id = (int) $term->id();

    // A deleted term's mirror must no longer point to it.
    if ($hook === 'delete') {
      $mirror_id = $this->getMirrorTermId($term, $ref_field);
      $this->updateLink($mirror_id, $ref_field, $term_id, NULL);
      return;
    }

    $changes = $this->getMirrorTermChanges($term, $ref_field);
    if (!$changes) {
      return;
    }

    // The previous mirror no longer points back, the new one does.
    $this->updateLink($changes['original'], $ref_field, $term_id, NULL);
    $this->updateLink($changes['current'], $ref_field, NULL, $term_id);
  }

  /**
   * Updates the mirror reference of a linked term when needed.
   *
   * Only saves the linked term when its reference changes, so linked terms
   * are not saved again (and re-trigger this sync) when already correct.
   *
   * @param int|null $linked_id
   *   The ID of the linked term, or NULL to do nothing.
   * @param string $ref_field
   *   The mirror reference field name.
   * @param int|null $only_if
   *   Only update when the linked term currently points to this term ID;
   *   NULL to update regardless.
   * @param int|null $new_target
   *   The new mirror term ID, or NULL to clear the reference.
   */
  protected function updateLink(?int $linked_id, string $ref_field, ?int $only_if, ?int $new_target): void {
    if (!$linked_id) {
      return;
    }
    $linked_term = $this->loadTerm($linked_id);
    if (!$linked_term || !$linked_term->hasField($ref_field)) {
      return;
    }
    $current = $linked_term->$ref_field->target_id;
    $current = $current === NULL ? NULL : (int) $current;
    if ($only_if !== NULL && $current !== $only_if) {
      return;
    }
    if ($current === $new_target) {
      return;
    }
    $linked_term->$ref_field->target_id = $new_target;
    $linked_term->save();
  }

}
