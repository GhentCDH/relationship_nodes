<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Form\FormState;
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

  /**
   * Deferred relation changes are applied to the saved parent node.
   */
  public function testSaveDeferredRelations(): void {
    $parent = $this->createPerson('Parent');
    $other = $this->createPerson('Other');
    $removed = $this->createRelation($parent, $other);
    $weights = $this->container->get('relationship_nodes.relation_weight_manager');
    $weights->setWeight((int) $removed->id(), 'rn_related_entity_1', 3);

    // A relation added in the parent's form: no reference to the parent yet.
    $new = Node::create([
      'type' => static::RELATION_BUNDLE,
      'title' => 'New relation',
      'rn_related_entity_2' => $other->id(),
    ]);
    $deferred = [
      'relations-form' => [
        'entities' => [['entity' => $new, 'weight' => 5, 'needs_save' => TRUE]],
        'delete' => [$removed],
      ],
    ];
    $form_state = new FormState();
    $this->container->get('relationship_nodes.relation_sync')->saveDeferredRelations($parent, $deferred, $form_state);

    $this->assertFalse($new->isNew());
    $saved = Node::load($new->id());
    $this->assertSame((int) $parent->id(), (int) $saved->get('rn_related_entity_1')->target_id);
    $this->assertNull(Node::load($removed->id()));
    $this->assertSame([(int) $new->id()], $this->getComputedRelationIds($parent));
    $this->assertSame(5, $weights->getWeight((int) $new->id(), 'rn_related_entity_1'));
    $stored_for_removed = array_filter(array_keys($weights->getAllWeights()), fn($key) => str_starts_with($key, $removed->id() . '.'));
    $this->assertSame([], $stored_for_removed);
  }

  /**
   * Saving a node does not save new relations in its computed field.
   *
   * Inline entity forms put new relations in the computed field; they are
   * saved after the parent, when it has an ID to reference.
   */
  public function testParentSaveDoesNotSaveNewRelations(): void {
    $other = $this->createPerson('Other');
    $parent = Node::create(['type' => 'person', 'title' => 'Parent']);
    $new = Node::create(['type' => static::RELATION_BUNDLE, 'title' => 'New', 'rn_related_entity_2' => $other->id()]);
    $parent->get(static::COMPUTED_FIELD)->appendItem(['entity' => $new]);
    $parent->save();
    $this->assertTrue($new->isNew());
  }

  /**
   * IEF does not save or delete relations of a relation widget itself.
   */
  public function testDeferRelationWidgetSubmit(): void {
    $other = $this->createPerson('Other');
    $existing = $this->createRelation($this->createPerson('A'), $other);
    $new = Node::create(['type' => static::RELATION_BUNDLE, 'title' => 'New']);
    $widget_state = [
      'entities' => [
        ['entity' => $new, 'weight' => 0, 'needs_save' => TRUE],
        ['entity' => $existing, 'weight' => 1, 'needs_save' => FALSE],
      ],
      'delete' => [$existing],
    ];
    $form_state = new FormState();
    $this->container->get('relationship_nodes.relation_entity_form_handler')
      ->deferRelationWidgetSubmit('relations-form', $widget_state, $form_state);

    $this->assertFalse($widget_state['entities'][0]['needs_save']);
    $this->assertSame([], $widget_state['delete']);
    $deferred = $form_state->get(['rn_deferred_relations', 'relations-form']);
    $this->assertTrue($deferred['entities'][0]['needs_save']);
    $this->assertSame(1, $deferred['entities'][1]['weight']);
    $this->assertSame([$existing], $deferred['delete']);
  }

  /**
   * Relations are listed in the order of their stored weights.
   */
  public function testWeightOrder(): void {
    $a = $this->createPerson('A');
    $first = $this->createRelation($a, $this->createPerson('B'));
    $second = $this->createRelation($a, $this->createPerson('C'));
    $third = $this->createRelation($a, $this->createPerson('D'));
    $weights = $this->container->get('relationship_nodes.relation_weight_manager');
    $weights->setWeight((int) $first->id(), 'rn_related_entity_1', 2);
    $weights->setWeight((int) $second->id(), 'rn_related_entity_1', 0);
    // $third has no weight and comes last.
    $expected = [(int) $second->id(), (int) $first->id(), (int) $third->id()];
    $this->assertSame($expected, $this->getComputedRelationIds($a));
    $stored = $weights->getMultiple([$first->id(), $third->id()], 'rn_related_entity_1');
    $this->assertSame([(int) $first->id() => 2, (int) $third->id() => 9999], $stored);
  }

}
