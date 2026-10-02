<?php

namespace Drupal\relationship_nodes\Form\Entity;

use Drupal\Core\Form\FormStateInterface;
use Drupal\relationship_nodes\Form\Widget\WidgetSubmitHandler;

/**
 * Form alter service for parent node forms with relationship subforms.
 */
class ParentNodeFormAlter {

  /**
   * The relation form helper.
   */
  protected RelationFormHelper $formHelper;

  /**
   * Constructs a ParentNodeFormAlter object.
   *
   * @param \Drupal\relationship_nodes\Form\Entity\RelationFormHelper $formHelper
   *   The form helper.
   */
  public function __construct(RelationFormHelper $formHelper) {
    $this->formHelper = $formHelper;
  }

  /**
   * Alters target node forms to add relationship nodes handling.
   *
   * Adds relationship nodes handling to IEF form states when available.
   * The default IEF handling submits first the subforms, and afterwards the
   * parent form. For this use case, changing this order would be easiest.
   * Since the default IEF handling may also be required, another workflow
   * was chosen.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, $form_id) {
    if (!$this->formHelper->isParentFormWithRelationSubforms($form_state)) {
      return;
    }

    // Save the relations after the parent node, on the buttons that save it
    // (the same buttons IEF attaches its submit processing to).
    $save_relations = [RelationEntityFormHandler::class, 'saveDeferredRelations'];
    foreach (['submit', 'publish', 'unpublish'] as $action) {
      if (!empty($form['actions'][$action]['#submit'])) {
        $form['actions'][$action]['#submit'][] = $save_relations;
      }
    }
    if (!empty($form['submit']['#submit'])) {
      $form['submit']['#submit'][] = $save_relations;
    }
    WidgetSubmitHandler::updateDefaultSubmit($form, $form_state);
  }

}
