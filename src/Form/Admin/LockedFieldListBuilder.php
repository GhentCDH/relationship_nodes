<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\field_ui\FieldConfigListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;


/**
 * List builder for field configurations that keeps locked relation fields visible.
 *
 * Extends FieldConfigListBuilder to override operations for relationship node fields.
 */
class LockedFieldListBuilder extends FieldConfigListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $field_config) {
    $row = parent::buildRow($field_config);
    $original_operations = ConfigEntityListBuilder::buildRow($field_config) ?? [];
    $field_config_helper = \Drupal::service('relationship_nodes.field_ui_manager');
    $field_config_helper->overrideOperationsEdit($row, $field_config, $original_operations);
    return $row;
  }
}