<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the output of the relationship and mirror label formatters.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class FormattersTest extends RelationshipNodesKernelTestBase {

  use UserCreationTrait;

  /**
   * Renders a field with a formatter and returns the text.
   */
  protected function renderField($entity, string $field_name, array $display_options): string {
    $build = $entity->get($field_name)->view($display_options);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Returns the text of rendered HTML.
   */
  protected function text(string $html): string {
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
  }

  /**
   * The relation type is shown from the viewing node's side.
   */
  public function testFormatters(): void {
    $this->installConfig(['system']);
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    $parent = $this->createRelationType('parent');
    $child = $this->createRelationType('child');
    Term::load($parent->id())->set('rn_mirror_reference', $child->id())->save();
    $ann = $this->createPerson('Ann');
    $bob = $this->createPerson('Bob');
    // Ann is the parent of Bob.
    $relation = $this->createRelation($ann, $bob, $parent);

    $ann_output = $this->renderField(Node::load($ann->id()), static::COMPUTED_FIELD, ['type' => 'relationship_formatter']);
    $bob_output = $this->renderField(Node::load($bob->id()), static::COMPUTED_FIELD, ['type' => 'relationship_formatter']);
    $mirror_label = ['type' => 'relation_type_mirror_label', 'label' => 'hidden'];
    $mirror_output = $this->renderField(Node::load($relation->id()), 'rn_relation_type', $mirror_label);
    // "Ann is the parent of Bob": Ann's page shows the relation type, Bob's
    // page its mirror.
    $this->assertStringContainsString('Bob parent', $this->text($ann_output));
    $this->assertStringContainsString('Ann child', $this->text($bob_output));
    $this->assertStringContainsString('href="/node/' . $bob->id() . '"', $ann_output);
    $this->assertSame('child', $this->text($mirror_output));

    // Without a mirror, the mirror label formatter shows the term itself.
    $plain = $this->createRelation($ann, $this->createPerson('Carl'), $this->createRelationType('neighbour'));
    $plain_output = $this->renderField(Node::load($plain->id()), 'rn_relation_type', $mirror_label);
    $this->assertSame('neighbour', $this->text($plain_output));
  }

}
