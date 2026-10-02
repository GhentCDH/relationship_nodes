<?php

namespace Drupal\relationship_nodes\Form\Entity;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\relationship_nodes\Form\Entity\RelationFormHelper;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationSync;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;


/**
 * Service for handling relationship entity forms.
 */
class RelationEntityFormHandler {

  use StringTranslationTrait;

  protected FieldNameResolver $fieldNameResolver;
  protected RelationSync $syncService;
  protected RelationFormHelper $formHelper;


  /**
   * Constructs a RelationEntityFormHandler object.
   *
   * @param FieldNameResolver $fieldNameResolver
   *   The field name resolver.
   * @param RelationSync $syncService
   *   The relation sync service.
   * @param RelationFormHelper $formHelper
   *   The form helper.
   */
  public function __construct(
    FieldNameResolver $fieldNameResolver,
    RelationSync $syncService,
    RelationFormHelper $formHelper
  ) {
    $this->fieldNameResolver = $fieldNameResolver;
    $this->syncService = $syncService;
    $this->formHelper = $formHelper; 
  }


  /**
   * Takes the relation changes of a widget out of IEF's submit processing.
   *
   * IEF saves inline entities before the parent entity. Relations reference
   * the parent, so a new parent has no ID yet, and a failed parent save would
   * leave saved relations and deleted relations behind. The changes are kept
   * in the form state and applied after the parent is saved.
   *
   * @param string $ief_id
   *   The Inline Entity Form widget ID.
   * @param array $widget_state
   *   The widget state (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public function deferRelationWidgetSubmit(string $ief_id, array &$widget_state, FormStateInterface $form_state): void {
    $widget_state += ['entities' => [], 'delete' => []];
    $deferred = ['entities' => [], 'delete' => $widget_state['delete']];
    // Loop over the widget state itself (not a copy), so IEF sees the change.
    foreach ($widget_state['entities'] as $delta => &$entity_item) {
      $entity = $entity_item['entity'] ?? NULL;
      if (!$entity instanceof Node) {
        continue;
      }
      $deferred['entities'][$delta] = [
        'entity' => $entity,
        'weight' => $entity_item['weight'] ?? $delta,
        'needs_save' => !empty($entity_item['needs_save']),
      ];
      $entity_item['needs_save'] = FALSE;
    }
    unset($entity_item);
    $widget_state['delete'] = [];
    $form_state->set(['rn_deferred_relations', $ief_id], $deferred);
  }


  /**
   * Submit handler: saves the deferred relation changes after the parent.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public static function saveDeferredRelations(array &$form, FormStateInterface $form_state): void {
    $deferred = $form_state->get('rn_deferred_relations');
    $form_object = $form_state->getFormObject();
    if (empty($deferred) || !$form_object instanceof EntityFormInterface) {
      return;
    }
    $parent_node = $form_object->getEntity();
    if (!$parent_node instanceof Node || $parent_node->isNew()) {
      return;
    }
    \Drupal::service('relationship_nodes.relation_sync')->saveDeferredRelations($parent_node, $deferred, $form_state);
    $form_state->set('rn_deferred_relations', NULL);
  }

}