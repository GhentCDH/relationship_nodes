<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;

/**
 * Tests that entity reference mirror terms stay linked both ways.
 *
 * @group relationship_nodes
 */
class MirrorSyncTest extends RelationshipNodesKernelTestBase {

  /**
   * The mirror reference field.
   */
  protected const MIRROR_FIELD = 'rn_mirror_reference';

  /**
   * Returns the mirror term ID of a term, freshly loaded.
   */
  protected function getMirror(TermInterface $term): ?int {
    $storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $term = $storage->loadUnchanged($term->id());
    $this->assertNotNull($term);
    $target = $term->get(static::MIRROR_FIELD)->target_id;
    return $target === NULL ? NULL : (int) $target;
  }

  /**
   * Sets the mirror of a term.
   */
  protected function setMirror(TermInterface $term, ?TermInterface $mirror): void {
    $term = Term::load($term->id());
    $term->set(static::MIRROR_FIELD, $mirror?->id());
    $term->save();
  }

  /**
   * Linking, relinking, deleting and clearing mirrors.
   */
  public function testMirrorLinks(): void {
    $a = $this->createRelationType('A');
    $b = $this->createRelationType('B');
    $c = $this->createRelationType('C');

    // Linking A to B links B back to A.
    $this->setMirror($a, $b);
    $this->assertSame((int) $b->id(), $this->getMirror($a));
    $this->assertSame((int) $a->id(), $this->getMirror($b));

    // Relinking A to C unlinks B and links C.
    $this->setMirror($a, $c);
    $this->assertSame((int) $c->id(), $this->getMirror($a));
    $this->assertNull($this->getMirror($b));
    $this->assertSame((int) $a->id(), $this->getMirror($c));

    // Deleting C clears A's link to it.
    Term::load($c->id())->delete();
    $this->assertNull($this->getMirror($a));
    $this->assertNull($this->getMirror($b));

    // Clearing a link clears it on both sides.
    $this->setMirror($b, $a);
    $this->assertSame((int) $b->id(), $this->getMirror($a));
    $this->setMirror($a, NULL);
    $this->assertNull($this->getMirror($a));
    $this->assertNull($this->getMirror($b));
  }

}
