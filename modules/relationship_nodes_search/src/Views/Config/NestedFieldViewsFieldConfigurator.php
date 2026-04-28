<?php

namespace Drupal\relationship_nodes_search\Views\Config;

use Drupal\search_api\Entity\Index;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\relationship_nodes\RelationField\CalculatedFieldHelper;
use Drupal\relationship_nodes_search\FieldHelper\NestedIndexFieldHelper;

/**
 * Configuration form builder for Views field display.
 * 
 * Extends the Views configurator base with field display-specific functionality:
 * - Display mode selection (raw, label, link)
 * - Template configuration
 * - Sorting and grouping options
 * - Multiple value separator configuration
 */
class NestedFieldViewsFieldConfigurator extends NestedFieldViewsConfiguratorBase {

  /**
   * Builds field display configuration form for Views.
   * 
   * High-level orchestration method that:
   * 1. Prepares field configurations with Views context
   * 2. Extracts global display settings
   * 3. Builds the complete form structure
   *
   * @param array &$form
   *   The form array to build into.
   * @param Index $index
   *   The Search API index.
   * @param string $sapi_fld_nm
   *   Parent Search API field name.
   * @param array $child_field_names
   *   Available child field names.
   * @param array $saved_settings
   *   Current saved settings from Views configuration.
   */
  public function buildFieldDisplayForm(
    array &$form,
    Index $index,
    string $sapi_fld_nm,
    array $child_field_names,
    array $saved_settings
  ): void {
    // PREPARE: Build field configurations with Views display context
    $field_configs = $this->prepareViewsFieldConfigurations(
      $index,
      $sapi_fld_nm,
      $child_field_names,
      $saved_settings
    );

    // Extract global display settings
    $global_settings = [
      'sort_by_field' => $saved_settings['sort_by_field'] ?? '',
      'group_by_field' => $saved_settings['group_by_field'] ?? '',
      'template' => $saved_settings['template'] ?? 'relationship-field',
    ];

    // RENDER: Build form from configurations using parent's generic builder
    $this->buildConfigurationForm(
      $form,
      $field_configs,
      $global_settings,
      [
        'context_prefix' => $this->getViewsContextPrefix(),
        'show_template' => TRUE,
        'show_grouping' => TRUE,
        'show_sorting' => TRUE,
        // Use parent's default field callback (buildFieldFormFromConfig)
        // which handles display_mode, label, weight, hide_label, multiple_separator
      ]
    );
  }
}