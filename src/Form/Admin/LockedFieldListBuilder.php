<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\field_ui\FieldConfigListBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for field configurations that keeps locked relation fields visible.
 *
 * Extends FieldConfigListBuilder to override operations for relationship node fields.
 */
class LockedFieldListBuilder extends FieldConfigListBuilder {

  /**
   * The field UI manager.
   */
  protected FieldUiManager $fieldUiManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->fieldUiManager = $container->get('relationship_nodes.field_ui_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $field_config) {
    $row = parent::buildRow($field_config);
    $original_operations = ConfigEntityListBuilder::buildRow($field_config) ?? [];
    $this->fieldUiManager->overrideOperationsEdit($row, $field_config, $original_operations);
    return $row;
  }

}
