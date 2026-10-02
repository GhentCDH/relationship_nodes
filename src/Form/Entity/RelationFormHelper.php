<?php

namespace Drupal\relationship_nodes\Form\Entity;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Helper service for relationship node forms.
 */
class RelationFormHelper {

  /**
   * Gets the parent form node entity.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The parent node or NULL.
   */
  public function getParentFormNode(FormStateInterface $form_state): ?Node {
    // Drupal\node\NodeForm moved to Drupal\node\Form\NodeForm in Drupal 11,
    // so check for an entity form instead of the class.
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return NULL;
    }

    $build_info = $form_state->getBuildInfo();
    if (!isset($build_info['base_form_id']) || $build_info['base_form_id'] != 'node_form') {
      return NULL;
    }

    $form_entity = $form_object->getEntity();
    if (!$form_entity instanceof Node) {
      return NULL;
    }

    return $form_entity;
  }

  /**
   * Gets relation extended widget fields mapping.
   *
   * Returns a mapping of IEF ID => field name for all relation extended
   * widgets. Detection is based on the 'relation_extended_widget' flag stored
   * in the
   * widget state by RelationIefWidget::extractFormValues().
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Array mapping IEF IDs to field names: ['ief_id' => 'field_name'].
   */
  public function getRelationExtendedWidgetFields(FormStateInterface $form_state): array {
    $ief_states = $form_state->get('inline_entity_form') ?? [];
    $result = [];
    foreach ($ief_states as $ief_id => $widget_state) {
      if (!is_array($widget_state)) {
        continue;
      }
      $field_name = $this->getIefRelationWidgetFieldName($widget_state);
      if ($field_name) {
        $result[$ief_id] = $field_name;
      }
    }

    return $result;
  }

  /**
   * Checks if form is a parent form with IEF subforms.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if parent form with IEF subforms, FALSE otherwise.
   */
  public function isParentFormWithIefSubforms(FormStateInterface $form_state): bool {
    return !empty($this->getParentFormNode($form_state))
      && !empty($form_state->get('inline_entity_form'));
  }

  /**
   * Checks if form is a parent form with relation subforms.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if parent form with relation subforms, FALSE otherwise.
   */
  public function isParentFormWithRelationSubforms(FormStateInterface $form_state): bool {
    return !empty($this->getParentFormNode($form_state))
      && !empty($this->getRelationExtendedWidgetFields($form_state));
  }

  /**
   * Returns the field name for a relation extended IEF widget state.
   *
   * Returns NULL if the widget state does not belong to a relation extended
   * widget, or if the field instance cannot be resolved.
   *
   * @param array $widget_state
   *   The IEF widget state array.
   *
   * @return string|null
   *   The field name, or NULL.
   */
  protected function getIefRelationWidgetFieldName(array $widget_state): ?string {
    if (empty($widget_state['relation_extended_widget'])) {
      return NULL;
    }
    return $this->getIefWidgetInstanceFieldName($widget_state);
  }

  /**
   * Gets the field name from the IEF widget state's field instance.
   *
   * @param array $widget_state
   *   The IEF widget state array.
   *
   * @return string|null
   *   The field name, or NULL if the instance is not a
   *   FieldDefinitionInterface.
   */
  protected function getIefWidgetInstanceFieldName(array $widget_state): ?string {
    if (!(($widget_state['instance'] ?? NULL) instanceof FieldDefinitionInterface)) {
      return NULL;
    }
    return $widget_state['instance']->getName();
  }

}
