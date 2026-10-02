<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the rn() Twig functions.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class TwigFunctionsTest extends RelationshipNodesKernelTestBase {

  /**
   * The relation fields and formatted relations of a node.
   */
  public function testFunctions(): void {
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    $ann = $this->createPerson('Ann');
    $friend = $this->createRelationType('friend');
    $this->createRelation($ann, $this->createPerson('Bob'), $friend);
    $this->createRelation($ann, $this->createPerson('Carl'), $friend);
    $this->createRelation($ann, $this->createPerson('Hidden', FALSE), $friend);

    $twig = $this->container->get('relationship_nodes.twig_extension');
    $this->container->get('account_switcher')->switchTo(new AnonymousUserSession());
    // Inside a template, rn() runs in a render context (it bubbles cache data).
    $rn = fn(...$args) => $this->container->get('renderer')->executeInRenderContext(new RenderContext(), fn() => $twig->rn(...$args));
    $this->assertSame([static::COMPUTED_FIELD], $rn('relation_fields_list', $ann));

    // Unpublished related nodes are left out for anonymous visitors.
    $result = $rn('formatted_relations', $ann, static::COMPUTED_FIELD);
    $this->assertSame('rel_person_person', $result['relation_bundle']);
    $this->assertSame(['Bob', 'Carl'], array_map(fn($item) => $item['related_id']['value'], $result['items']));
    $this->assertSame('friend', $result['items'][0]['relation_type_name']['value']);
    $this->assertFalse($result['has_more']);
    $this->assertArrayNotHasKey('_cache', $result);

    $limited = $rn('formatted_relations', $ann, static::COMPUTED_FIELD, ['limit' => 1]);
    $this->assertCount(1, $limited['items']);
    $this->assertTrue($limited['has_more']);

    // A node without relations.
    $this->assertNull($rn('formatted_relations', $this->createPerson('Alone'), static::COMPUTED_FIELD));
  }

}
