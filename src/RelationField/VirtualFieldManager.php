<?php

namespace Drupal\relationship_nodes\RelationField;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\Plugin\Field\FieldType\ReferencingRelationshipItemList;
use Drupal\relationship_nodes\Plugin\Field\FieldWidget\RelationIefWidget;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;

/**
 * Service for adding virtual relationship fields to entity bundles.
 *
 * Creates computed fields for referencing relationships.
 */
class VirtualFieldManager {

  use StringTranslationTrait;

  /**
   * The bundle info service.
   */
  protected BundleInfoService $bundleInfoService;

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a VirtualFieldManager object.
   *
   * @param \Drupal\relationship_nodes\RelationBundle\BundleInfoService $bundleInfoService
   *   The bundle info service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(BundleInfoService $bundleInfoService, LoggerChannelFactoryInterface $loggerFactory) {
    $this->bundleInfoService = $bundleInfoService;
    $this->logger = $loggerFactory->get('relationship_nodes');
  }

  /**
   * Adds virtual relationship fields to a bundle.
   *
   * @param array $fields
   *   The fields array (passed by reference).
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle name.
   */
  public function addFields(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() !== 'node') {
      return;
    }

    $relationships = $this->bundleInfoService->getRelationInfoForTargetBundle($bundle);

    if (empty($relationships)) {
      return;
    }

    // Two relation bundles between the same bundles would produce the same
    // field name. The field form prevents this; if it happens anyway (e.g.
    // through a config import), the first relation bundle keeps the field.
    ksort($relationships);
    foreach ($relationships as $relation_bundle => $relationship) {
      $field_name = 'computed_relationshipfield__' . $bundle . '__' . implode('_', $relationship['related_bundles']);
      if (isset($fields[$field_name])) {
        $this->logger->error('Relation bundles @first and @second both connect @bundle with @related; only @first is shown in field @field. Use one relation bundle per pair of bundles.', [
          '@first' => $fields[$field_name]->getTargetBundle(),
          '@second' => $relation_bundle,
          '@bundle' => $bundle,
          '@related' => implode(', ', $relationship['related_bundles']),
          '@field' => $field_name,
        ]);
        continue;
      }
      $fields[$field_name] = BaseFieldDefinition::create('entity_reference')
        ->setName($field_name)
        ->setLabel('Relationships with ' . implode(', ', $relationship['related_bundles']))
        ->setDescription($this->t('This computed field lists all the relationships between @this and @related.', [
          '@this' => $bundle,
          '@related' => implode(', ', $relationship['related_bundles']),
        ]))
        ->setClass(ReferencingRelationshipItemList::class)
        ->setComputed(TRUE)
        ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
        ->setTargetEntityTypeId('node')
        ->setTargetBundle($relation_bundle)
        ->setDisplayOptions('form', [
          'type' => RelationIefWidget::PLUGIN_ID,
          'weight' => 0,
        ])
        ->setDisplayOptions('view', [
          'type' => 'relationship_formatter',
          'weight' => 10,
          'label' => 'above',
          'settings' => [
            'show_relation_type' => TRUE,
            'show_field_labels' => TRUE,
            'link_entities' => TRUE,
            'group_by_type' => FALSE,
            'separator' => ', ',
          ],
        ])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE)
        ->setSetting('handler_settings', [
          'target_bundles' => [$relation_bundle],
        ])
        ->setSetting('join_field', $relationship['join_fields'])
        ->setRevisionable(FALSE);
    }
  }

  /**
   * Get all ReferencingRelationshipItemList fields from a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array
   *   Array of field names that are ReferencingRelationshipItemList.
   */
  public function getReferencingRelationshipFields(NodeInterface $node): array {
    $relationship_fields = [];

    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getClass() === ReferencingRelationshipItemList::class) {
        $relationship_fields[] = $field_name;
      }
    }
    return $relationship_fields;
  }

}
