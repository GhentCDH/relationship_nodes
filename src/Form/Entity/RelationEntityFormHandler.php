<?php

namespace Drupal\relationship_nodes\Form\Entity;

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
   * Handles relation widget submit processing.
   *
   * @param string $ief_id
   *   The Inline Entity Form widget ID.
   * @param array $widget_state
   *   The widget state (passed by reference).
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public function handleRelationWidgetSubmit(
    string $ief_id,
    array &$widget_state,
    array &$form,
    FormStateInterface $form_state
  ): void {
    $parent_node = $this->formHelper->getParentFormNode($form_state);
    if (!($parent_node instanceof Node)) {
      return;
    }

    if (!$parent_node->isNew()) {
      // Use IEF ID for delete tracking
      $delete_ids = array_keys($form_state->get(['rn_delete_ids', $ief_id]) ?? []);
      if (!empty($delete_ids)) {
        $this->syncService->deleteNodes($delete_ids);
      }
    }
    
    $this->syncService->saveSubformRelations($parent_node, $widget_state, $form, $form_state);  
  }
}