<?php

namespace Drupal\relationship_nodes_search\Form;

use Drupal\search_api\Form\IndexAddFieldsForm;
use Drupal\Core\Render\Element;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api\Item\Field;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;

/**
 * Extended form for adding fields to Search API index.
 *
 * Provides special handling for nested relationship fields, including:
 * - Parent/child field selection with checkboxes
 * - Automatic inclusion of calculated fields
 * - Custom "Add" button behavior for relationship groups
 */
class ExtendedIndexAddFieldsForm extends IndexAddFieldsForm {

  protected FieldNameResolver $fieldNameResolver;
  protected CalculatedFieldHelper $calculatedFieldHelper;
  

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->fieldNameResolver = $container->get('relationship_nodes.field_name_resolver');
    $instance->calculatedFieldHelper = $container->get('relationship_nodes.calculated_field_helper');
    return $instance;
  }


  /**
   * {@inheritdoc}
   *
   * Filters out calculated fields from nested relationship properties
   * and adds custom checkbox/button UI for relationship field selection.
   */
  protected function getPropertiesList(array $props, string $active_prop_path, $base_url, ?string $datasource_id, string $parent_path = '', string $label_prefix = '', int $depth = 0, array $rows = []): array {
    // Filter out calculated fields from nested relationship properties.
    if ($depth > 0 && str_starts_with($parent_path, 'relationship_info__')) {
      $calc_fld_nms = $this->calculatedFieldHelper->getCalculatedFieldNames(NULL, NULL, TRUE);
      if (!empty($calc_fld_nms)) {
        foreach ($props as $prop_nm => $def) {
          if (in_array($prop_nm, $calc_fld_nms)) {
            unset($props[$prop_nm]);
          }
        }
      }    
    }
    $rows = parent::getPropertiesList($props, $active_prop_path, $base_url, $datasource_id, $parent_path, $label_prefix, $depth, $rows);
    $remove_add_button = [];
    $related_entity_flds = $this->fieldNameResolver->getRelatedEntityFields();
    foreach ($rows as $key => &$row) {
      $machine_name = $row['machine_name']['data'] ?? '';
      if (!str_starts_with($machine_name, 'relationship_info__')) {
        continue;
      }
     
      if (substr_count($machine_name, ':') === 0) {
        $this->addFieldButtons[] = [
          '#type' => 'submit',
          '#name' => Utility::createCombinedId($datasource_id, $machine_name),
          '#value' => $this->t('Add'),
          '#submit' => ['::addNestedRelationField', '::save'],
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['button', 'button--primary', 'button--extrasmall', 'relationship-parent-add'],
            'disabled' => 'disabled',
          ],
          '#ajax' => [
            'wrapper' => $this->formIdAttribute,
          ],
          '#property' => isset($props[$machine_name]) ? $props[$machine_name] : NULL,
          '#row_key' => $key,
          '#datasource_key' => 'datasource_entity:node'
        ];
      } else {
        [$sapi_fld_nm, $child_fld_nm] = explode(':', substr($machine_name, strlen('relationship_info__')), 2);

        $attributes = ['class' => ['relationship-child-checkbox']];
        
        if (in_array($child_fld_nm, $related_entity_flds)) {
          $attributes['class'][] = 'is-disabled';
          $attributes['checked'] = 'checked';
          $attributes['disabled'] = 'disabled';
        } 

        $row['relation_property']['data'] = [
          '#type' => 'checkbox',
          '#title' => '',
          '#name' => 'relationship_nested_' . str_replace(':', '__', $machine_name),
          '#attributes' => $attributes,
        ];  
        $remove_add_button[] = $key;
      }    
    }

    if (!empty($remove_add_button)) {
      foreach($this->addFieldButtons as $i => $add_button){
        if (in_array($add_button['#row_key'], $remove_add_button)) {
          unset($this->addFieldButtons[$i]);
          $remove_add_button = array_diff($remove_add_button, [$i]);
        }
        if (empty($remove_add_button)) {
          break;
        }
      }
    }
    return $rows;
  }


  /**
   * {@inheritdoc}
   */
  public function preRenderForm(array $form): array {
    $form = parent::preRenderForm($form);
    $form['#attached']['library'][] = 'relationship_nodes_search/relationship_form';
    return $form;
  }


  /**
   * Form submission handler for adding nested relation fields.
   *
   * Handles the submission of the "add" button for a single nested relation
   * field group. Collects all selected child fields and creates a Search API
   * field with nested configuration.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public function addNestedRelationField(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    if (!$button || !isset($button['#property'])) {
      return;
    }

    $prop = $button['#property'];
    $row_key = $button['#row_key'] ?? NULL;
    [$datasource_id, $prop_path] = Utility::splitCombinedId($button['#name']);

    if (!$prop_path) {
      return;
    }

    $all_table_rows = $form_state->getCompleteForm()['datasources']['datasource_entity:node']['table']['#rows'];

    $nested_props = [];

    // Collect pre-checked child fields from form structure.
    $i = $row_key + 1;
    while (strtok($all_table_rows[$i]['machine_name']['data'], ':') == $prop_path && isset($all_table_rows[$i]['relation_property'])) {
      $is_checked = $all_table_rows[$i]['relation_property']['data']['#attributes']['checked'] ?? NULL;
      if ($is_checked === 'checked') {
        $nested_props[] = str_replace( $prop_path . ':', '', $all_table_rows[$i]['machine_name']['data']);
      }     
      $i++;
    }

    // Collect user-selected child fields from form input.
    $all_user_input = $form_state->getUserInput();
    $fld_prefix = 'relationship_nested_' . str_replace(':', '__', $prop_path) . '__';

    foreach ($all_user_input as $input_field => $user_input) {
      if (str_starts_with($input_field, $fld_prefix) && $user_input == 1) {
        $child_fld_nm = str_replace($fld_prefix, '', $input_field);
        $nested_props[] = $child_fld_nm;
      }   
    }
    
    if (empty($nested_props)) {
      return;
    }

    // Build nested field configuration.
    $child_fld_config = $prop->buildNestedFieldsConfig(array_unique($nested_props));

    // Create Search API field.
    $sapi_fld = $this->fieldsHelper->createFieldFromProperty(
      $this->entity,
      $prop,
      $datasource_id,
      $prop_path, 
      $prop_path . '__nested', 'relationship_nodes_search_nested_relationship'
    );

    $sapi_fld->setConfiguration(['nested_fields' => $child_fld_config]);
    $this->entity->addField($sapi_fld);
    $this->messenger()->addStatus($this->t('Added relationship group with selected fields.'));
  }
}