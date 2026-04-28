<?php

namespace Drupal\relationship_nodes\Form\Entity;

use Drupal\Core\Form\FormStateInterface;
use Drupal\relationship_nodes\Form\Entity\RelationFormHelper;
use Drupal\relationship_nodes\Form\Widget\WidgetSubmitHandler;

/**
 * Form alter service for parent node forms with relationship subforms.
 */
class ParentNodeFormAlter {

  protected RelationFormHelper $formHelper;


  /**
   * Constructs a ParentNodeFormAlter object.
   *
   * @param RelationFormHelper $formHelper
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
   * @param FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, $form_id) {  
    if (!$this->formHelper->isParentFormWithRelationSubforms($form_state)) {
      return;
    }
    
    $target_entity = $this->formHelper->getParentFormNode($form_state);
    if ($target_entity->isNew()) {
      $form['actions']['submit']['#submit'][] = [$this, 'bindNewRelationsToParent'];
    }
    WidgetSubmitHandler::updateDefaultSubmit($form, $form_state);
  }


  /**
   * Binds newly created relations to their parent node.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public function bindNewRelationsToParent(array &$form, FormStateInterface $form_state) {
    $syncService = \Drupal::service('relationship_nodes.relation_sync');
    $syncService->bindNewRelationsToParent($form_state);
  }
}