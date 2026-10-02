<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests relation listing, caching and cleanup.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class RelationsTest extends RelationshipNodesKernelTestBase {

  /**
   * Both sides list a relation; the list carries the relation bundle tag.
   */
  public function testComputedRelations(): void {
    $a = $this->createPerson('A');
    $b = $this->createPerson('B');
    $c = $this->createPerson('C');
    $relation = $this->createRelation($a, $b);

    $this->assertSame([(int) $relation->id()], $this->getComputedRelationIds($a));
    $this->assertSame([(int) $relation->id()], $this->getComputedRelationIds($b));
    $this->assertSame([], $this->getComputedRelationIds($c));

    // Also an empty list must be invalidated when a relation is added.
    $items = Node::load($c->id())->get(static::COMPUTED_FIELD);
    $this->assertContains('node_list:' . static::RELATION_BUNDLE, $items->getCacheTags());
  }

  /**
   * Deleting a person deletes its relations.
   */
  public function testTargetDeletionDeletesRelations(): void {
    $a = $this->createPerson('A');
    $b = $this->createPerson('B');
    $c = $this->createPerson('C');
    $ab = $this->createRelation($a, $b);
    $bc = $this->createRelation($b, $c);

    $b->delete();

    $this->assertNull(Node::load($ab->id()));
    $this->assertNull(Node::load($bc->id()));
    $this->assertSame([], $this->getComputedRelationIds($a));
    $this->assertSame([], $this->getComputedRelationIds($c));
  }

  /**
   * Only one relation bundle may connect the same two bundles.
   */
  public function testPairCollision(): void {
    $info = $this->container->get('relationship_nodes.bundle_info_service');
    $this->assertNull($info->findRelationBundleForPair('person', 'person', static::RELATION_BUNDLE));
    $this->assertSame(static::RELATION_BUNDLE, $info->findRelationBundleForPair('person', 'person'));

    // A second relation bundle between persons, e.g. through a config import.
    $type = NodeType::create(['type' => 'rel_second', 'name' => 'Second person relation']);
    $type->setThirdPartySetting('relationship_nodes', 'enabled', TRUE);
    $type->setThirdPartySetting('relationship_nodes', 'typed_relation', FALSE);
    $type->setThirdPartySetting('relationship_nodes', 'auto_title', FALSE);
    $type->save();
    foreach (['rn_related_entity_1', 'rn_related_entity_2'] as $field_name) {
      FieldConfig::loadByName('node', 'rel_second', $field_name)
        ->setSetting('handler_settings', ['target_bundles' => ['person' => 'person']])
        ->save();
    }
    $this->assertSame(static::RELATION_BUNDLE, $info->findRelationBundleForPair('person', 'person', 'rel_second'));

    // The existing relations keep their field instead of being replaced.
    $a = $this->createPerson('A');
    $b = $this->createPerson('B');
    $relation = $this->createRelation($a, $b);
    $this->assertSame([(int) $relation->id()], $this->getComputedRelationIds($a));
  }

}
