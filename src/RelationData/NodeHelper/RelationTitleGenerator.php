<?php

namespace Drupal\relationship_nodes\RelationData\NodeHelper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;

/**
 * Generates the automatic titles of relation nodes.
 *
 * A relation node of a bundle with "auto title" gets a title in each of its
 * translations, built from the labels of its related nodes in that language,
 * e.g. "Relationship Ann - Bob".
 */
class RelationTitleGenerator {

  use StringTranslationTrait;

  /**
   * Constructs a RelationTitleGenerator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager $settingsManager
   *   The bundle settings manager.
   * @param \Drupal\relationship_nodes\RelationData\NodeHelper\RelationInfo $relationInfo
   *   The relation info service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BundleSettingsManager $settingsManager,
    protected RelationInfo $relationInfo,
  ) {}

  /**
   * Checks whether a node is a relation node with automatic titles.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return bool
   *   TRUE if the node gets automatic titles.
   */
  public function hasAutoTitle(NodeInterface $node): bool {
    $bundle_info = $this->settingsManager->getBundleInfo($node->bundle(), 'node');
    return $bundle_info && $bundle_info->isRelation() && $bundle_info->hasAutoTitle();
  }

  /**
   * Sets the automatic title in all translations of a relation node.
   *
   * @param \Drupal\node\NodeInterface $relation
   *   The relation node.
   *
   * @return bool
   *   TRUE if a title changed.
   */
  public function applyTitles(NodeInterface $relation): bool {
    if (!$this->hasAutoTitle($relation)) {
      return FALSE;
    }
    $changed = FALSE;
    foreach (array_keys($relation->getTranslationLanguages()) as $langcode) {
      $translation = $relation->getTranslation($langcode);
      $title = $this->buildTitle($relation, $langcode);
      if ($translation->getTitle() !== $title) {
        $translation->setTitle($title);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Builds the title of a relation node in a language.
   *
   * @param \Drupal\node\NodeInterface $relation
   *   The relation node.
   * @param string $langcode
   *   The language of the title.
   *
   * @return string
   *   The title.
   */
  public function buildTitle(NodeInterface $relation, string $langcode): string {
    $options = ['langcode' => $langcode];
    $related = $this->relationInfo->getRelatedEntityValues($relation) ?? [];
    $ids = array_merge([], ...array_values($related));
    $nodes = $ids ? $this->entityTypeManager->getStorage('node')->loadMultiple($ids) : [];

    $parts = [];
    foreach ($related as $field_ids) {
      $labels = [];
      foreach ($field_ids as $nid) {
        $node = $nodes[$nid] ?? NULL;
        if ($node instanceof NodeInterface) {
          $labels[] = $node->hasTranslation($langcode) ? $node->getTranslation($langcode)->label() : $node->label();
        }
      }
      if ($labels) {
        $parts[] = implode(', ', $labels);
      }
    }
    if (!$parts) {
      return (string) $this->t('Relationship (no entities)', [], $options);
    }
    return (string) $this->t('Relationship @related', ['@related' => implode(' - ', $parts)], $options);
  }

  /**
   * Hides the title field of a relation node form when titles are automatic.
   *
   * The title is required, so a provisional title is set for validation; the
   * real titles are set when the relation is saved.
   *
   * @param array $form
   *   The form or inline entity form (passed by reference).
   * @param \Drupal\node\NodeInterface $relation
   *   The relation node of the form.
   */
  public function hideTitleField(array &$form, NodeInterface $relation): void {
    if (!isset($form['title']) || !$this->hasAutoTitle($relation)) {
      return;
    }
    $form['title']['#access'] = FALSE;
    if ((string) $relation->getTitle() === '') {
      $relation->setTitle($this->buildTitle($relation, $relation->language()->getId()));
    }
  }

}
