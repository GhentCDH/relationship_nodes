<?php

namespace Drupal\relationship_nodes\RelationData\TermHelper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\Form\Entity\RelationFormHelper;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationData\NodeHelper\ForeignKeyResolver;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;

/**
 * Service for providing mirror term functionality.
 *
 * Handles mirror term logic for relationship type vocabularies.
 */
class MirrorProvider {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The field name resolver.
   */
  protected FieldNameResolver $fieldNameResolver;

  /**
   * The bundle settings manager.
   */
  protected BundleSettingsManager $settingsManager;

  /**
   * The foreign key resolver.
   */
  protected ForeignKeyResolver $foreignKeyResolver;

  /**
   * The relation form helper.
   */
  protected RelationFormHelper $formHelper;

  /**
   * Constructs a MirrorProvider object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\relationship_nodes\RelationField\FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   * @param \Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager $settingsManager
   *   The settings manager.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\ForeignKeyResolver $foreignKeyResolver
   *   The foreign key field resolver.
   * @param \Drupal\relationship_nodes\Form\Entity\RelationFormHelper $formHelper
   *   The form helper.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FieldNameResolver $fieldNameResolver,
    BundleSettingsManager $settingsManager,
    ForeignKeyResolver $foreignKeyResolver,
    RelationFormHelper $formHelper,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fieldNameResolver = $fieldNameResolver;
    $this->settingsManager = $settingsManager;
    $this->foreignKeyResolver = $foreignKeyResolver;
    $this->formHelper = $formHelper;
  }

  /**
   * Checks if an element supports mirroring.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if mirroring is supported, FALSE otherwise.
   */
  public function elementSupportsMirroring(FieldItemListInterface $items, array $form, FormStateInterface $form_state): bool {
    $bundle_info = $this->settingsManager->getBundleInfo($items->getEntity()->getType(), 'node');
    if (
      !$this->formHelper->isParentFormWithIefSubforms($form_state) ||
      !$bundle_info || !$bundle_info->isRelation() ||
      !$items->getFieldDefinition() instanceof FieldConfig
    ) {
      return FALSE;
    }

    $field = $items->getFieldDefinition();

    if (empty($field->getSettings())) {
      return FALSE;
    }

    $field_settings = $field->getSettings();
    if (
      !isset($field_settings['target_type']) ||
      $field_settings['target_type'] != 'taxonomy_term' ||
      empty($field_settings['handler_settings']['target_bundles'])
    ) {
      return FALSE;
    }

    $target_bundles = $field_settings['handler_settings']['target_bundles'];

    $target_vocab = $this->settingsManager->ensureVocab(reset($target_bundles));
    $bundle_info = $this->settingsManager->getBundleInfo($target_vocab);
    if (!$bundle_info || !$bundle_info->isRelation() || !$bundle_info->isMirroringVocab()) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if mirroring is required for this field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if mirroring is required, FALSE otherwise.
   */
  public function mirroringRequired(FieldItemListInterface $items, array $form, FormStateInterface $form_state): bool {
    if (!$this->elementSupportsMirroring($items, $form, $form_state)) {
      return FALSE;
    }

    $relation_entity = $items->getEntity();
    if (!$relation_entity instanceof NodeInterface) {
      return FALSE;
    }

    $foreign_key_field = $this->foreignKeyResolver->getEntityFormForeignKeyField($relation_entity, $form_state);

    if (!is_string($foreign_key_field) || $foreign_key_field !== $this->fieldNameResolver->getRelatedEntityFields(2)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets mirror options for select widget.
   *
   * Transforms term options to show mirror labels instead of original labels.
   * For example, if term "Parent" has mirror "Child", the option will show
   * "Child" instead of "Parent" when the field is the second related entity.
   *
   * @param array $options
   *   The original options array in format [term_id => label].
   *   Example: [1 => 'Parent', 2 => 'Sibling', '_none' => '- None -'].
   *
   * @return array
   *   The mirrored options array in the same format [term_id => mirror_label].
   *   Non-numeric keys (like '_none') are preserved with original labels.
   *   Example: [1 => 'Child', 2 => 'Sibling', '_none' => '- None -']
   */
  public function getMirrorOptions(array $options): array {
    if (empty($options)) {
      return [];
    }

    $mirror_options = [];

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($options as $term_id => $label) {

      if (!ctype_digit((string) $term_id)) {
        $mirror_options[$term_id] = $label;
        continue;
      }

      $mirror_array = $this->getMirrorArray($term_storage, $term_id, $label);
      $mirror_label = reset($mirror_array);

      $mirror_options[$term_id] = $mirror_label;

    }
    return $mirror_options;
  }

  /**
   * Gets mirror information as an array for a term.
   *
   * @param \Drupal\taxonomy\TermStorageInterface $term_storage
   *   The term storage.
   * @param string $term_id
   *   The term ID.
   * @param string|null $default_label
   *   The default label.
   * @param string|null $langcode
   *   The language of the label; defaults to the current language.
   *
   * @return array
   *   Array with term ID as key and label as value.
   */
  public function getMirrorArray(TermStorageInterface $term_storage, string $term_id, ?string $default_label = NULL, ?string $langcode = NULL): array {
    $term = $term_storage->load((int) $term_id);
    if (!$term instanceof TermInterface) {
      return [$term_id => $default_label ?? ''];
    }
    if ($langcode && $term->hasTranslation($langcode)) {
      $term = $term->getTranslation($langcode);
    }
    return $this->getTermMirrorArray($term, TRUE, $default_label);
  }

  /**
   * Gets mirror information as an array for a term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   * @param bool $fallback_to_default
   *   Should the method return a default label if no label is found.
   * @param string|null $default_label
   *   The default label.
   *
   * @return array
   *   Array with term ID as key and label as value.
   */
  public function getTermMirrorArray(TermInterface $term, bool $fallback_to_default = FALSE, ?string $default_label = NULL): array {

    if ($fallback_to_default == TRUE) {
      if ($default_label === NULL) {
        $default_label = $term->getName() ?? '';
      }
    }
    else {
      $default_label = NULL;
    }

    $result = [$term->id() => $default_label];

    $vocab = $term->bundle();
    $bundle_info = $this->settingsManager->getBundleInfo($term->bundle(), 'taxonomy_term');
    $vocab_type = $bundle_info->getMirrorType();

    switch ($vocab_type) {
      case 'string':
        $mirror_lookup = $this->getStringMirror($term);
        break;

      case 'entity_reference':
        $mirror_lookup = $this->getReferenceMirror($term, $vocab);
        break;

      default:
        return $result;
    }

    if (!is_array($mirror_lookup)) {
      return $result;
    }

    return $mirror_lookup;
  }

  /**
   * Returns the mirror label string for a term.
   *
   * Convenience wrapper over getTermMirrorArray() for callers that only need
   * the plain label string, not the full [id => label] array.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   *
   * @return string|null
   *   The mirror label, or NULL if no mirror is configured.
   */
  public function getMirrorLabelFromTerm(TermInterface $term): ?string {
    $mirror_array = $this->getTermMirrorArray($term);
    return reset($mirror_array) ?: NULL;
  }

  /**
   * Resolves the mirror label for a term ID.
   *
   * Convenience wrapper that loads the term internally, so callers do not
   * need to manage term storage themselves.
   *
   * @param string $term_id
   *   The taxonomy term ID.
   * @param string|null $langcode
   *   The language of the label; defaults to the current language.
   *
   * @return string|null
   *   The mirror label, or NULL if the term does not exist or has no mirror.
   */
  public function getMirrorLabelFromId(string $term_id, ?string $langcode = NULL): ?string {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $mirror_array = $this->getMirrorArray($term_storage, $term_id, NULL, $langcode);
    $label = reset($mirror_array);
    return !empty($label) ? (string) $label : NULL;
  }

  /**
   * Gets string mirror for a term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   *
   * @return array|null
   *   Array with term ID and mirror label, or NULL.
   */
  public function getStringMirror(TermInterface $term):?array {
    $values = $term->get($this->fieldNameResolver->getMirrorFields('string'))->getValue();
    if (empty($values)) {
      return NULL;
    }
    $value = reset($values) ?? [];
    $mirror_label = $value['value'] ?? NULL;
    return $mirror_label !== NULL ? [$term->id() => $mirror_label] : NULL;
  }

  /**
   * Gets entity reference mirror for a term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   * @param string $vocab
   *   The vocabulary ID.
   *
   * @return array|null
   *   Array with mirror term ID and label, or NULL.
   */
  public function getReferenceMirror(TermInterface $term, string $vocab): ?array {
    $values = $term->get($this->fieldNameResolver->getMirrorFields('entity_reference'))->getValue();
    if (empty($values)) {
      return NULL;
    }
    $value = reset($values) ?? [];
    $id_value = $value['target_id'];
    if (empty($id_value)) {
      return NULL;
    }
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $mirror_term = $term_storage->load((int) $id_value);
    if (!($mirror_term instanceof TermInterface) || $mirror_term->bundle() !== $vocab) {
      return NULL;
    }

    $langcode = $term->language()->getId();
    if ($mirror_term->hasTranslation($langcode)) {
      $mirror_term = $mirror_term->getTranslation($langcode);
    }

    $mirror_label = $mirror_term->getName();
    return $mirror_label !== NULL ? [$mirror_term->id() => $mirror_label] : NULL;
  }

}
