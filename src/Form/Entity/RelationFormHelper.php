<?php

namespace Drupal\relationship_nodes\Form\Entity;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeForm;

/**
 * Helper service for relationship node forms.
 */
class RelationFormHelper {

  /**
   * Gets the parent form node entity.
   *
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return Node|null
   *   The parent node or NULL.
   */
  public function getParentFormNode(FormStateInterface $form_state): ?Node {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof NodeForm) {
      return null;
    }

    $build_info = $form_state->getBuildInfo();
    if (!isset($build_info['base_form_id']) || $build_info['base_form_id'] != 'node_form') {
      return null;
    }

    $form_entity = $form_object->getEntity();
    if (!$form_entity instanceof Node) {
      return null;
    }

    return $form_entity;
  }


  /**
   * Gets relation extended widget fields mapping.
   *
   * Returns a mapping of IEF ID => field name for all relation extended widgets.
   * Detection is based on the 'relation_extended_widget' flag stored in the
   * widget state by RelationIefWidget::extractFormValues().
   *
   * @param FormStateInterface $form_state
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
   * Finds the IEF state key for a given field name.
   *
   * Iterates over all IEF widget states and matches on the field instance name.
   * Useful for looking up the (hashed) IEF ID when only the field name is known.
   *
   * @param string $field_name
   *   The field name to look up.
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The IEF state key, or NULL if not found.
   */
  public function getIefStateKey(string $field_name, FormStateInterface $form_state): ?string {
    $ief_states = $form_state->get('inline_entity_form') ?? [];

    foreach ($ief_states as $ief_id => $widget_state) {
      if (!is_array($widget_state)) {
        continue;
      }
      $widget_field_name = $this->getIefWidgetInstanceFieldName($widget_state);
      if ($widget_field_name === $field_name) {
        return $ief_id;
      }
    }

    return null;
  }


  /**
   * Checks if form is a parent form with IEF subforms.
   *
   * @param FormStateInterface $form_state
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
   * @param FormStateInterface $form_state
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
      return null;
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
   *   The field name, or NULL if the instance is not a FieldDefinitionInterface.
   */
  protected function getIefWidgetInstanceFieldName(array $widget_state): ?string {
    if (!(($widget_state['instance'] ?? null) instanceof FieldDefinitionInterface)) {
      return null;
    }
    return $widget_state['instance']->getName();
  }
}
