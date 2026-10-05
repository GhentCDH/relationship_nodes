<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Config\ConfigImporterException;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\ConfigTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the validation and processing of relation config on config import.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class ConfigImportTest extends RelationshipNodesKernelTestBase {

  use ConfigTestTrait;

  /**
   * The sync storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $sync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $this->sync);
  }

  /**
   * Imports the sync storage and returns the error messages.
   */
  protected function import(): array {
    try {
      $this->configImporter()->import();
      return [];
    }
    catch (ConfigImporterException $e) {
      return array_map('strval', $this->configImporter()->getErrors());
    }
  }

  /**
   * Relation config in the sync storage is created by the import.
   */
  public function testImportCreatesRelationBundle(): void {
    // Remove the relation bundle from the site; the sync storage has it.
    NodeType::load(static::RELATION_BUNDLE)->delete();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->assertNull(NodeType::load(static::RELATION_BUNDLE));

    $this->assertSame([], $this->import());
    $this->assertNotNull(NodeType::load(static::RELATION_BUNDLE));
    $this->assertNotNull(FieldConfig::loadByName('node', static::RELATION_BUNDLE, 'rn_related_entity_1'));

    // The imported relation bundle works.
    $relation = $this->createRelation($this->createPerson('A'), $b = $this->createPerson('B'));
    $this->assertSame([(int) $relation->id()], $this->getComputedRelationIds($b));
  }

  /**
   * A harmless change to a relation bundle is imported.
   */
  public function testValidChangeIsImported(): void {
    $name = 'node.type.' . static::RELATION_BUNDLE;
    $data = $this->sync->read($name);
    $data['name'] = 'Renamed relation';
    $this->sync->write($name, $data);

    $this->assertSame([], $this->import());
    $this->assertSame('Renamed relation', NodeType::load(static::RELATION_BUNDLE)->label());
  }

  /**
   * Relation fields cannot be made required.
   */
  public function testInvalidFieldConfigIsRefused(): void {
    $name = 'field.field.node.' . static::RELATION_BUNDLE . '.rn_related_entity_1';
    $data = $this->sync->read($name);
    $data['required'] = TRUE;
    $this->sync->write($name, $data);
    // The bundle is validated with its fields when the bundle changes.
    $bundle = 'node.type.' . static::RELATION_BUNDLE;
    $bundle_data = $this->sync->read($bundle);
    $bundle_data['description'] = 'Changed';
    $this->sync->write($bundle, $bundle_data);

    $errors = $this->import();
    $this->assertNotEmpty($errors, 'The import is refused.');
    $this->assertStringContainsString('cannot be required', implode(' ', $errors));
    $this->assertFalse(FieldConfig::loadByName('node', static::RELATION_BUNDLE, 'rn_related_entity_1')->isRequired());
  }

  /**
   * Fields a relation bundle needs cannot be removed.
   */
  public function testRemovingRequiredFieldIsRefused(): void {
    $this->sync->delete('field.field.node.' . static::RELATION_BUNDLE . '.rn_related_entity_1');

    $errors = $this->import();
    $this->assertStringContainsString('cannot be removed because the bundle', implode(' ', $errors));
    $this->assertNotNull(FieldConfig::loadByName('node', static::RELATION_BUNDLE, 'rn_related_entity_1'));
  }

  /**
   * An invalid change to only a relation field is refused as well.
   */
  public function testInvalidFieldOnlyChangeIsRefused(): void {
    $name = 'field.field.node.' . static::RELATION_BUNDLE . '.rn_related_entity_1';
    $data = $this->sync->read($name);
    $data['required'] = TRUE;
    $this->sync->write($name, $data);

    $errors = $this->import();
    $this->assertStringContainsString('cannot be required', implode(' ', $errors));
    $this->assertFalse(FieldConfig::loadByName('node', static::RELATION_BUNDLE, 'rn_related_entity_1')->isRequired());
  }

  /**
   * A whole relation bundle with its fields can be removed.
   */
  public function testRemovingRelationBundleIsAllowed(): void {
    foreach ($this->sync->listAll('field.field.node.' . static::RELATION_BUNDLE . '.') as $name) {
      $this->sync->delete($name);
    }
    foreach ($this->sync->listAll('core.entity_') as $name) {
      if (str_contains($name, '.node.' . static::RELATION_BUNDLE . '.')) {
        $this->sync->delete($name);
      }
    }
    $this->sync->delete('node.type.' . static::RELATION_BUNDLE);

    $this->assertSame([], $this->import());
    $this->assertNull(NodeType::load(static::RELATION_BUNDLE));
    // Persons no longer have the computed relationship field.
    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'person');
    $this->assertArrayNotHasKey(static::COMPUTED_FIELD, $fields);
  }

}
