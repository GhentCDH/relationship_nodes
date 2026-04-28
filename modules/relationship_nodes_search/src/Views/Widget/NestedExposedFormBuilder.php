<?php

namespace Drupal\relationship_nodes_search\Views\Widget;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\relationship_nodes_search\QueryHelper\FilterOperatorHelper;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;
use Drupal\relationship_nodes_search\Views\Widget\NestedFilterDropdownOptionsProvider;
use Drupal\relationship_nodes_search\FieldHelper\NestedIndexFieldHelper;


/**
 * Service for building exposed filter form widgets.
 */
class NestedExposedFormBuilder {

  use StringTranslationTrait;

  protected FilterOperatorHelper $operatorHelper;
  protected CalculatedFieldHelper $calculatedFieldHelper;
  protected NestedFilterDropdownOptionsProvider $dropdownProvider;
  protected NestedIndexFieldHelper $nestedFieldHelper;

  
  /**
   * Constructs a NestedExposedFormBuilder object.
   */
  public function __construct(
    FilterOperatorHelper $operatorHelper,
    CalculatedFieldHelper $calculatedFieldHelper,
    NestedFilterDropdownOptionsProvider $dropdownProvider,
    NestedIndexFieldHelper $nestedFieldHelper,
  ) {
    $this->operatorHelper = $operatorHelper;
    $this->calculatedFieldHelper = $calculatedFieldHelper;
    $this->dropdownProvider = $dropdownProvider;
    $this->nestedFieldHelper = $nestedFieldHelper;
  }


  /**
   * Build exposed field widget structure.
   */
  public function buildExposedFieldWidget(
    array &$form,
    array $path,
    Index $index,
    string $sapi_fld_nm,
    array $child_fld_settings,
    array $child_fld_values = [],
    bool $expose_operators = FALSE,
    $view_query = NULL
  ): void {
    if (empty($child_fld_settings)) {
      return;
    }

    $enabled_fields = $this->getEnabledAndSortedFields($child_fld_settings);

    foreach ($enabled_fields as $child_fld_nm => $child_fld_config) {
      $child_filter_id = $child_fld_config['child_filter_id'] ?? $child_fld_nm;
      $child_fld_value = $child_fld_values[$child_filter_id] ?? NULL;
      $child_path = array_merge($path, [$child_filter_id]);
      if (($child_fld_config['widget'] ?? 'textfield') === 'select_indexed') {
        $options = $this->dropdownProvider->getDropdownOptionsWithViewContext(
          $index,
          $sapi_fld_nm,
          $child_fld_nm,
          $child_fld_config['select_display_mode'] ?? 'raw',
          $view_query
        );
        $child_fld_config['options'] = $options;
      } elseif (($child_fld_config['widget'] ?? 'textfield') === 'select_range') {
        $child_fld_config['options'] = $this->buildIntRangeOptions($child_fld_config['int_range'] ?? []);
      }
      $this->buildChildFieldElement(
        $form,
        $child_path,
        $child_fld_nm,
        $child_fld_config,
        $child_fld_value,
        $expose_operators
      );
    }
  }


  /**
   * Get enabled fields from configuration.
   *
   * @param array $child_fld_settings
   *   Child field settings array.
   *
   * @return array
   *   Enabled fields only.
   */
  public function getEnabledFields(array $child_fld_settings): array {
    return array_filter($child_fld_settings, function($config) {
      return !empty($config['enabled']);
    });
  }


  /**
   * Get enabled fields sorted by weight.
   *
   * @param array $child_fld_settings
   *   Child field settings array.
   *
   * @return array
   *   Enabled and sorted fields.
   */
  public function getEnabledAndSortedFields(array $child_fld_settings): array {
    $enabled = $this->getEnabledFields($child_fld_settings);

    uasort($enabled, function($a, $b) {
      return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
    });

    return $enabled;
  }


  /**
   * Build a single child field form element.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param array $path
   *   Form element path.
   * @param string $child_fld_nm
   *   Child field name.
   * @param array $field_config
   *   Field configuration (must include 'options' for select widgets).
   * @param array|null $field_value
   *   Current field value.
   * @param bool $expose_operators
   *   Whether to expose operator selector.
   */
  protected function buildChildFieldElement(
    array &$form,
    array $path,
    string $child_fld_nm,
    array $field_config,
    ?array $field_value = NULL,
    bool $expose_operators = FALSE
  ): void {
    $widget_type = $field_config['widget'] ?? 'textfield';
    $label = $field_config['label'] ?? $this->calculatedFieldHelper->formatCalculatedFieldLabel($child_fld_nm);
    $required = !empty($field_config['required']);
    $placeholder = $field_config['placeholder'] ?? '';
    $expose_field_operator = !empty($field_config['expose_field_operator']);

    $child_fld_container = [
    '#type' => 'container',
    '#attributes' => ['class' => ['relationship-filter-field-wrapper']],
    ];
    $this->setFormNestedValue($form, $path, $child_fld_container);

    if ($expose_operators && $expose_field_operator) {
    $this->addOperatorWidget($form, $path, $field_config, $field_value);
    }

    switch ($widget_type) {
      case 'select_indexed':
      case 'select_range':
        $this->addSelectWidget($form, $path, $field_config, $label, $required, $field_value);
        break;
      case 'textfield':
      default:
        $this->addTextfieldWidget($form, $path, $label, $required, $placeholder, $field_value);
        break;
    }
  }


  /**
   * Add operator selector widget.
   *
   * Creates a select dropdown for choosing the comparison operator
   * (equals, greater than, etc.).
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param array $path
   *   Form element path.
   * @param array $field_config
   *   Field configuration.
   * @param array|null $field_value
   *   Current field value.
   */
  protected function addOperatorWidget(array &$form, array $path, array $field_config, ?array $field_value = NULL): void {
    $path[] = 'operator';
    $operator = [
      '#type' => 'select',
      '#title' => $this->t('Operator'),
      '#options' => $this->operatorHelper->getOperatorOptions(),
      '#default_value' => $field_value['operator'] ?? $field_config['field_operator'] ?? $this->operatorHelper->getDefaultOperator(),
      '#attributes' => ['class' => ['relationship-filter-operator']],
    ];
    $this->setFormNestedValue($form, $path, $operator);
  }


  /**
   * Add dropdown select widget.
   *
   * Creates a select dropdown with options provided in field configuration.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param array $path
   *   Form element path.
   * @param array $field_config
   *   Field configuration (must include 'options' key).
   * @param string $label
   *   Field label.
   * @param bool $required
   *   Whether the field is required.
   * @param array|null $field_value
   *   Current field value.
   */
  protected function addSelectWidget(
    array &$form,
    array $path,
    array $field_config,
    string $label,
    bool $required,
    ?array $field_value = NULL
  ): void {
    $options = $field_config['options'] ?? [];

    $path[] = 'value';
    $value = [
      '#type' => 'select',
      '#title' => $label,
      '#options' => $options,
      '#default_value' => $field_value['value'] ?? '',
      '#required' => $required,
      '#empty_option' => $required ? NULL : $this->t('- Any -'),
    ];
    $this->setFormNestedValue($form, $path, $value);
  }
  


  /**
   * Add textfield widget.
   *
   * Creates a simple text input field.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param array $path
   *   Form element path.
   * @param string $label
   *   Field label.
   * @param bool $required
   *   Whether the field is required.
   * @param string $placeholder
   *   Placeholder text.
   * @param array|null $field_value
   *   Current field value.
   */
  protected function addTextfieldWidget(
    array &$form,
    array $path,
    string $label,
    bool $required,
    string $placeholder,
    ?array $field_value = NULL
  ): void {
    $path[] = 'value';
    $value = [
      '#type' => 'textfield',
      '#title' => $label,
      '#default_value' => $field_value['value'] ?? '',
      '#required' => $required,
      '#placeholder' => $placeholder,
    ];
    $this->setFormNestedValue($form, $path, $value);
  }


  /* // ENTITY AUTOCOMPLETE NOT YET IMPLEMENTED (CF CONFIG HELPER)
  protected function addEntityAutocompleteWidget(array &$form, array $path, string $child_fld_nm, string $label, bool $required, string $placeholder, ?array $field_value = NULL): void {
    $target_type =  // implement childfieldentrefhelper getnestedfieldtargettype;
    $default_entity = $this->getDefaultEntityValue($child_fld_nm, $target_type, $field_value);
    $path[] = 'value';
    $value = [
      '#type' => 'entity_autocomplete',
      '#title' => $label,
      '#target_type' => $target_type,
      '#default_value' => $default_entity,
      '#required' => $required,
      '#placeholder' => $placeholder,
    ];
    $this->setFormNestedValue($form, $path, $value);
  }

  protected function getDefaultEntityValue(string $child_fld_nm, string $target_type, ?array $field_value = NULL) {   
    if (empty($field_value) || !is_numeric($field_value)) {
      return NULL;
    }

    try {
      return $this->entityTypeManager->getStorage($target_type)->load($field_value);
    } catch (\Exception $e) {
      return NULL;
    }
  }*/


  /**
   * Set a nested value in form array.
   *
   * Navigates through the form array using the path keys and sets the value
   * at the final location, creating intermediate arrays as needed.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param array $path
   *   Array of keys representing the path to the value.
   * @param mixed $value
   *   The value to set.
   */
  protected function setFormNestedValue(array &$form, array $path, $value): void {
    $ref = &$form;
    $path_count = count($path);
    
    foreach ($path as $i => $key) {
      $is_last = ($i === $path_count - 1);
      
      if ($is_last) {
        if (!isset($ref[$key])) {
          $ref[$key] = $value;
        } elseif (is_array($ref[$key]) && is_array($value)) {
          $ref[$key] = array_merge($ref[$key], $value);
        } else {
          $ref[$key] = $value;
        }
      } else {
        if (!isset($ref[$key])) {
          $ref[$key] = [];
        } elseif (!is_array($ref[$key])) {
          $ref[$key] = [];
        }
        $ref = &$ref[$key];
      }
    }
  }
  

  /**
   * Builds From/To range pair widgets into the exposed form.
   *
   * @param array &$form
   *   The form array.
   * @param array $path
   *   Path to the value container (e.g. ['value']).
   * @param array $pair_config
   *   Range pair configuration (widget, int_range, etc.).
   * @param array $pair_values
   *   Current submitted/default values.
   */
  public function buildRangePairWidget(array &$form, array $path, array $pair_config, array $pair_values = []): void {
    $widget     = $pair_config['widget']     ?? 'textfield';
    $title      = $pair_config['label']      ?? $this->t('Date range');
    $from_label = $pair_config['from_label'] ?? $this->t('From');
    $to_label   = $pair_config['to_label']   ?? $this->t('To');

    if ($widget === 'select_range') {
      $options = $this->buildIntRangeOptions($pair_config['int_range'] ?? []);
    }

    $fieldset_path = array_merge($path, ['range_pair']);
    $this->setFormNestedValue($form, $fieldset_path, [
      '#type'       => 'fieldset',
      '#title'      => $title,
      '#attributes' => ['class' => ['date-range-filter']],
    ]);

    foreach (['from' => $from_label, 'to' => $to_label] as $key => $label) {
      // Values are now nested under range_pair in the submitted values.
      $val = $pair_values['range_pair'][$key]['value'] ?? $pair_values['range_pair'][$key] ?? '';
      // Elements go inside the fieldset.
      $element_path = array_merge($fieldset_path, [$key, 'value']);

      if ($widget === 'select_range') {
        $this->setFormNestedValue($form, $element_path, [
          '#type'          => 'select',
          '#title'         => $label,
          '#options'       => $options,
          '#default_value' => $val,
          '#empty_option'  => $this->t('- Any -'),
        ]);
      }
      else {
        $this->setFormNestedValue($form, $element_path, [
          '#type'          => 'textfield',
          '#title'         => $label,
          '#default_value' => $val,
        ]);
      }
    }
  }


  protected function buildIntRangeOptions(array $int_range): array {
    $min = ($int_range['use_current_year_min'] ?? FALSE)
      ? (int) date('Y')
      : (isset($int_range['min']) && $int_range['min'] !== '' ? (int) $int_range['min'] : NULL);

    $max = ($int_range['use_current_year_max'] ?? FALSE)
      ? (int) date('Y')
      : (isset($int_range['max']) && $int_range['max'] !== '' ? (int) $int_range['max'] : NULL);

    if ($min === NULL || $max === NULL) {
      return [];
    }

    if ($min > $max) {
      [$min, $max] = [$max, $min];
    }

    return array_combine(
      $values = array_reverse(range($min, $max)),
      $values
    );
  }

}