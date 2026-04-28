<?php

namespace Drupal\relationship_nodes\Form\Widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\inline_entity_form\WidgetSubmit;


/**
 * Extended widget submit handler for relationship nodes.
 *
 * Extends Inline Entity Form's WidgetSubmit to handle relationship-specific
 * submit logic.
 */
class WidgetSubmitHandler extends WidgetSubmit{


  /**
   * Updates the default IEF submit handler to use our extended version.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public static function updateDefaultSubmit(array &$form, FormStateInterface $form_state): void {
    foreach ($form['#ief_element_submit'] as $i => $callback) {
      if (is_array($callback) && $callback[0] === WidgetSubmit::class && $callback[1] === 'doSubmit') {     
        $form['#ief_element_submit'][$i] = [static::class, 'doSubmit'];
        return;
      }
    } 
  }


  /**
   * Handles form submission for relationship widgets.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public static function doSubmit(array $form, FormStateInterface $form_state): void {
    $relationFormHelper = \Drupal::service('relationship_nodes.relation_form_helper');
    $relationFormHandler = \Drupal::service('relationship_nodes.relation_entity_form_handler');
    
    // Returns: ['field_relations-form' => 'field_relations']
    $relation_widgets = $relationFormHelper->getRelationExtendedWidgetFields($form_state);
    $all_widget_states = $form_state->get('inline_entity_form');

    foreach ($relation_widgets as $ief_id => $field_name) {
      $widget_state = $all_widget_states[$ief_id];
      $relationFormHandler->handleRelationWidgetSubmit($ief_id, $widget_state, $form, $form_state);
      $all_widget_states[$ief_id] = $widget_state;
    }

    $form_state->set('inline_entity_form', $all_widget_states);
    parent::doSubmit($form, $form_state);
  }
}