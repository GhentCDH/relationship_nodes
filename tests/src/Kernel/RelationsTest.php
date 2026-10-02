<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\node\Entity\Node;

/**
 * Tests relation listing, caching and cleanup.
 *
 * @group relationship_nodes
 */
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

}
