<?php

namespace Drupal\relationship_nodes_search\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\search_api\Entity\Index;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\relationship_nodes_search\SearchAPI\Query\NestedParentFieldConditionGroup;
use Drupal\relationship_nodes_search\QueryHelper\NestedQueryStructureBuilder;
use Drupal\relationship_nodes_search\Views\Widget\NestedExposedFormBuilder;
use Drupal\relationship_nodes_search\Views\Config\NestedFieldViewsFilterConfigurator;
use Drupal\relationship_nodes_search\FieldHelper\NestedIndexFieldHelper;
use Drupal\relationship_nodes_search\QueryHelper\FilterOperatorHelper;

/**
 * Filter for nested relationship data in Search API.
 *
 * @ViewsFilter("search_api_relationship_filter")
 */
class RelationshipFilter extends FilterPluginBase implements ContainerFactoryPluginInterface {

  use SearchApiFilterTrait;

  protected NestedExposedFormBuilder $exposedFormBuilder;
  protected NestedFieldViewsFilterConfigurator $filterConfigurator;
  protected NestedQueryStructureBuilder $queryBuilder;
  protected FilterOperatorHelper $operatorHelper;
  protected NestedIndexFieldHelper $nestedFieldHelper;


  /**
   * Constructs a RelationshipFilter object.
   *
   * @param array $configuration
   *    The plugin configuration.
   * @param string $plugin_id
   *    The plugin ID.
   * @param mixed $plugin_definition
   *    The plugin definition.
   * @param NestedExposedFormBuilder $exposedFormBuilder
   *    The exposed form builder service.
   * @param NestedFieldViewsFilterConfigurator $filterConfigurator
   *    The filter configurator service.
   * @param NestedQueryStructureBuilder $queryBuilder
   *    The query builder service.
   * @param FilterOperatorHelper $operatorHelper
   *    The operator helper service.
   * @param NestedIndexFieldHelper $nestedFieldHelper
   *    The nested index field helper service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    NestedExposedFormBuilder $exposedFormBuilder,
    NestedFieldViewsFilterConfigurator $filterConfigurator,
    NestedQueryStructureBuilder $queryBuilder,
    FilterOperatorHelper $operatorHelper,
    NestedIndexFieldHelper $nestedFieldHelper
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->exposedFormBuilder = $exposedFormBuilder;
    $this->filterConfigurator = $filterConfigurator;
    $this->queryBuilder = $queryBuilder;
    $this->operatorHelper = $operatorHelper;
    $this->nestedFieldHelper = $nestedFieldHelper;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('relationship_nodes_search.nested_exposed_form_builder'),
      $container->get('relationship_nodes_search.nested_field_views_filter_configurator'),
      $container->get('relationship_nodes_search.nested_query_structure_builder'),
      $container->get('relationship_nodes_search.filter_operator_helper'),
      $container->get('relationship_nodes_search.nested_index_field_helper')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();   
    foreach ($this->getDefaultFilterOptions() as $option => $default) {
      $options[$option] = ['default' => $default];
    } 
    return $options;
  }


  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    
    // Hide default form fields, we add custom ones in our subfield section
    if (isset($form['value'])) {
      $form['value']['#access'] = FALSE;
    }
    
    if (isset($form['expose']['multiple'])) {
      $form['expose']['multiple']['#access'] = FALSE;
    }

    $config = $this->filterConfigurator->validateAndPreparePluginForm(
      $this->getIndex(),
      $this->definition,
      $form
    );
    if (!$config) {
      return;
    } 

    $form['operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Operator'),
      '#options' => [
          'and' => $this->t('AND - All conditions must match'),
          'or' => $this->t('OR - Any condition can match'),
      ],
      '#default_value' => $this->options['operator'] ?? 'and',
      '#description' => $this->t('How to combine multiple filter fields.'),
    ];

    $form['expose_operators'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow users to select operators'),
      '#default_value' => $this->options['expose_operators'] ?? FALSE,
      '#description' => $this->t('When exposed, allow users to choose the comparison operator for each field.'),
    ];

    $this->filterConfigurator->buildFilterConfigForm(
      $form, 
      $config['index'], 
      $config['field_name'], 
      $config['available_fields'], 
      $this->options
    );
  }

  
  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);
    $this->filterConfigurator->savePluginOptions(
      $form_state,
      $this->getDefaultFilterOptions(),
      $this->options
    );
  }


  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::validateOptionsForm($form, $form_state);

    $field_settings = $form_state->getValue(['options', 'field_settings']) ?? [];
    foreach ($field_settings as $field_name => $settings) {
      if (empty($settings['enabled']) || $field_name === 'range_pair' || ($settings['widget'] ?? '') !== 'select_range') {
        continue;
      }
      $range = $settings['int_range'] ?? [];
      $use_min = !empty($range['use_current_year_min']);
      $use_max = !empty($range['use_current_year_max']);

      if (!$use_min && ($range['min'] === '' || $range['min'] === NULL)) {
        $form_state->setError(
          $form['field_settings'][$field_name]['int_range']['min'],
          $this->t('Minimum value is required for the "@field" integer range dropdown.', ['@field' => $field_name])
        );
      }
      if (!$use_max && ($range['max'] === '' || $range['max'] === NULL)) {
        $form_state->setError(
          $form['field_settings'][$field_name]['int_range']['max'],
          $this->t('Maximum value is required for the "@field" integer range dropdown.', ['@field' => $field_name])
        );
      }
    }

    $pair = $field_settings['range_pair'] ?? [];
    if (!empty($pair['enabled'])) {
      foreach ($field_settings as $field_name => $settings) {
        if ($field_name === 'range_pair') {
          continue;
        }
        $id = $settings['child_filter_id'] ?? '';
        if ($id === 'from' || $id === 'to') {
          $form_state->setError(
            $form['field_settings'][$field_name]['child_filter_id'],
            $this->t('The identifier "@id" is reserved by the range pair filter. Choose a different identifier for "@field".', ['@id' => $id, '@field' => $field_name])
          );
        }
      }

      if (empty($pair['start_field'])) {
        $form_state->setError($form['field_settings']['range_pair']['start_field'],
          $this->t('Start field is required for the range pair filter.'));
      }
      if (empty($pair['end_field'])) {
        $form_state->setError($form['field_settings']['range_pair']['end_field'],
          $this->t('End field is required for the range pair filter.'));
      }
      if (!empty($pair['start_field']) && $pair['start_field'] === ($pair['end_field'] ?? '')) {
        $form_state->setError($form['field_settings']['range_pair']['end_field'],
          $this->t('Start and end fields must be different.'));
      }
    }
  }


    /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    if (!$this->isExposed()) {
      return parent::adminSummary();
    }

    $child_fld_settings = $this->getFieldSettings();
    $enabled = $this->exposedFormBuilder->getEnabledFields($child_fld_settings);
    $pair = $this->getRangePairConfig();
    $count = count($enabled) + (!empty($pair['enabled']) ? 1 : 0);

    if ($count === 0) {
      return $this->t('Not configured');
    }

    $operator = $this->options['operator'] ?? 'and';

    return $this->t('@count fields (@operator)', [
      '@count' => $count,
      '@operator' => strtoupper($operator),
    ]);
  }


  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state):void {
    $index = $this->getIndex();
    $sapi_fld_nm = $this->filterConfigurator->getPluginParentFieldName($this->definition);
    if (!$index instanceof Index || empty($sapi_fld_nm)) {
      return;
    }

    $child_fld_settings = $this->options['exposed'] ? $this->getFieldSettings() : [];
    $child_fld_values = is_array($this->value) ? $this->value : [];

    $exp_op = $this->options['expose_operators'] ?? FALSE;

    $form['value'] = [
      '#type' => 'fieldset',
      '#title' => $this->options['expose']['label'] ?? $this->t('Filter Options'),
      '#tree' => TRUE,
      '#attributes' => ['class' => ['relationship-child-field-wrapper']],
    ];

    $this->exposedFormBuilder->buildExposedFieldWidget(
      $form, ['value'], $index, $sapi_fld_nm, $child_fld_settings, $child_fld_values, $exp_op, $this->query
    );

    $pair = $this->getRangePairConfig();
    if (!empty($pair['enabled']) && $this->options['exposed']) {
      $this->exposedFormBuilder->buildRangePairWidget($form, ['value'], $pair, $child_fld_values);
    }
  }


  /**
   * {@inheritdoc}
   */
  public function query():void {
    if (!$this->getQuery()) {
      return;
    }

    $conditions = $this->buildFilterConditions();
    if (!empty($conditions)) {
      $this->applyNestedConditions($conditions);
    }

    $this->buildRangePairConditions();
  }


  /**
   * Builds and applies range pair overlap conditions to the query.
   *
   * A record matches when its [start_field, end_field] range overlaps the
   * [from, to] filter range. Both conditions go in the same nested
   * group so they apply to the same nested document.
   */
  protected function buildRangePairConditions(): void {
    $pair = $this->getRangePairConfig();
    if (empty($pair['enabled']) || !$this->getQuery()) return;

    $start_field = $pair['start_field'] ?? '';
    $end_field = $pair['end_field'] ?? '';
    if (!$start_field || !$end_field) return;

    $sapi_fld_nm = $this->filterConfigurator->getPluginParentFieldName($this->definition);
    $index = $this->getIndex();
    if (!$sapi_fld_nm || !$index instanceof Index) return;

    $values = is_array($this->value) ? $this->value : [];
    $from_val = $values['range_pair']['from']['value'] ?? $values['range_pair']['from'] ?? '';
    $to_val = $values['range_pair']['to']['value'] ?? $values['range_pair']['to'] ?? '';

    if (is_string($from_val)) $from_val = $this->sanitizeFieldValue($from_val);
    if (is_string($to_val)) $to_val = $this->sanitizeFieldValue($to_val);

    if ($from_val === '' && $to_val === '') return;

    $field_type = $this->nestedFieldHelper->getChildFieldType($index, $sapi_fld_nm, $start_field);
    if ($field_type === 'date') {
      if ($from_val !== '' && is_numeric($from_val)) {
        $from_val = $this->convertYearToDateString((int) $from_val, '>=');
      }
      if ($to_val !== '' && is_numeric($to_val)) {
        $to_val = $this->convertYearToDateString((int) $to_val, '<=');
      }
    }

    $group = new NestedParentFieldConditionGroup('AND');
    $group->setParentFieldName($sapi_fld_nm)
          ->setIndex($index)
          ->setQueryBuilder($this->queryBuilder);

    if ($from_val !== '') {
      // COALESCE(end, start) >= from:
      // end >= from  OR  (end missing AND start >= from)
      $from_or = $group->addChildConditionGroup('OR');
      $from_or->addChildFieldCondition($end_field, $from_val, '>=');
      $from_or->addChildConditionGroup('AND')
              ->addChildFieldCondition($end_field, NULL, '=')
              ->addChildFieldCondition($start_field, $from_val, '>=');
    }
    if ($to_val !== '') {
      // COALESCE(start, end) <= to:
      // start <= to  OR  (start missing AND end <= to)
      $to_or = $group->addChildConditionGroup('OR');
      $to_or->addChildFieldCondition($start_field, $to_val, '<=');
      $to_or->addChildConditionGroup('AND')
            ->addChildFieldCondition($start_field, NULL, '=')
            ->addChildFieldCondition($end_field, $to_val, '<=');
    }

    $this->query->addConditionGroup($group);
  }


  /**
   * Builds filter conditions from form values.
   *
   * Extracts enabled field values and their operators from the form state,
   * sanitizes input, and returns structured condition arrays.
   *
   * @return array
   *   Array of condition arrays, each containing:
   *   - child_field_name: the nested field name
   *   - value: the filter value
   *   - operator: the comparison operator
   */
  protected function buildFilterConditions(): array {
    $child_fld_settings = $this->getFieldSettings();
    $conditions = [];
    $sapi_fld_nm = $this->filterConfigurator->getPluginParentFieldName($this->definition);
    $index = $this->getIndex();

    foreach ($child_fld_settings as $child_fld_nm => $field_config) {
      if (empty($field_config['enabled'])) {
        continue;
      }

      // Get value
      $child_filter_id = $field_config['child_filter_id'] ?? $child_fld_nm;

      if ($this->options['exposed']) {
        $value = $this->value[$child_filter_id]['value']
         ?? $this->value[$child_filter_id]
         ?? '';
      } else {
        $value = $field_config['value'] ?? '';
      }
      
      if (is_string($value)) {
        $value = $this->sanitizeFieldValue($value);
      }
      
      if ($value === '' || $value === NULL) {
        continue;
      }

      $operator = $this->operatorHelper->determineFieldOperator($field_config, $child_filter_id, $this->value);
      if (($field_config['widget'] ?? '') === 'select_range'
        && isset($sapi_fld_nm) && $index instanceof Index
        && $this->nestedFieldHelper->getChildFieldType($index, $sapi_fld_nm, $child_fld_nm) === 'date'
        && is_numeric($value)
      ) {
        $value = $this->convertYearToDateString((int) $value, $operator);
      }

      $conditions[] = [
        'child_field_name' => $child_fld_nm,
        'value' => $value,
        'operator' => $operator,
      ];
    }
    return $conditions;
  }


  /**
   * Applies nested conditions to the search query.
   *
   * Creates a NestedParentFieldConditionGroup and adds all child field
   * conditions to it, then adds the group to the query.
   *
   * @param array $conditions
   *   Array of condition arrays from buildFilterConditions().
   */
  protected function applyNestedConditions(array $conditions): void {
    $operator = $this->options['operator'] ?? 'and';
    $sapi_fld_nm = $this->filterConfigurator->getPluginParentFieldName($this->definition);
    $index = $this->getIndex();

    if (empty($sapi_fld_nm) || !$index instanceof Index) {
      return;
    }

    $nested_fld_condition = new NestedParentFieldConditionGroup(strtoupper($operator));
    $nested_fld_condition
      ->setParentFieldName($sapi_fld_nm)
      ->setIndex($index)
      ->setQueryBuilder($this->queryBuilder);
    
    foreach ($conditions as $condition) {
      $nested_fld_condition->addChildFieldCondition(
        $condition['child_field_name'],
        $condition['value'],
        $condition['operator']
      );
    }
    $this->query->addConditionGroup($nested_fld_condition);
  }


  /**
   * Converts a year integer to an ISO 8601 date string for date field comparisons.
   *
   * select_range widgets emit plain year integers (e.g. 1800). Date fields are
   * stored in Elasticsearch as ISO 8601 strings (date('c', $timestamp)). This
   * method produces a matching string so Elasticsearch can compare them correctly.
   *
   * For >= and > operators the start of the year is used (Jan 1 00:00:00).
   * For <= and <  operators the end  of the year is used (Dec 31 23:59:59).
   *
   * @param int $year
   *   The year value from the filter widget.
   * @param string $operator
   *   The comparison operator.
   *
   * @return string
   *   ISO 8601 date string, e.g. "1800-01-01T00:00:00+00:00".
   */
  protected function convertYearToDateString(int $year, string $operator): string {
    if (in_array($operator, ['<=', '<'], TRUE)) {
      return date('c', mktime(23, 59, 59, 12, 31, $year));
    }
    return date('c', mktime(0, 0, 0, 1, 1, $year));
  }


  /**
   * Gets the field settings from options.
   *
   * @return array
   *   The filter field settings array.
   */
  protected function getFieldSettings(): array {
    $settings = $this->options['field_settings'] ?? [];
    unset($settings['range_pair']);
    return $settings;
  }


  /**
   * Gets the range pair configuration from options.
   *
   * @return array
   *   The range pair config, or empty array if not set.
   */
  protected function getRangePairConfig(): array {
    return $this->options['field_settings']['range_pair'] ?? [];
  }


  /**
   * Sanitizes a single field value.
   *
   * Removes HTML tags, trims whitespace, and limits length to 255 characters.
   *
   * @param string $value
   *   The value to sanitize.
   *
   * @return string
   *   The sanitized value.
   */
  protected function sanitizeFieldValue(string $value): string {
    // Remove HTML tags
    $value = strip_tags($value);
    
    // Trim whitespace
    $value = trim($value);
    
    // Limit length
    if (mb_strlen($value) > 255) {
      $value = mb_substr($value, 0, 255);
    }
    
    return $value;
  }


  /**
   * Gets default filter options.
   *
   * @return array
   *   Array of default option values.
   */
  protected function getDefaultFilterOptions(): array {
    return [
      'value' => [],
      'field_settings' => [],
      'operator' => 'and',
      'expose_operators' => FALSE,
    ];
  }


  /**
   * Check if any filter values are set.
   *
   * @param array $values
   *   The filter values.
   * @param array $field_settings
   *   The field settings.
   *
   * @return bool
   *   TRUE if any enabled field has a non-empty value.
   */
  protected function hasActiveFilterValues(array $values, array $field_settings): bool {
    if (empty($values)) {
      return FALSE;
    }

    foreach ($field_settings as $field_name => $config) {
      // Skip disabled fields
      if (empty($config['enabled'])) {
        continue;
      }

      // Check if field has a value
      $child_filter_id = $config['child_filter_id'] ?? $field_name;
      $value = $values[$child_filter_id]['value'] ?? $values[$child_filter_id] ?? NULL;

      if ($value !== NULL && $value !== '') {
        return TRUE;
      }
    }

    $pair = $this->getRangePairConfig();
    if (!empty($pair['enabled'])) {
      foreach (['from', 'to'] as $key) {
        $val = $values['range_pair'][$key]['value'] ?? $values['range_pair'][$key] ?? NULL;
        if ($val !== NULL && $val !== '') return TRUE;
      }
    }

    return FALSE;
  }
}