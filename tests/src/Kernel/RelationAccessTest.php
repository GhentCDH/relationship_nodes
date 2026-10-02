<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests that relations are only displayed when the viewer may see them.
 *
 * @group relationship_nodes
 */
class RelationAccessTest extends RelationshipNodesKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    // User 1 is created first so the admin below is a regular user.
    $this->createUser();
  }

  /**
   * Returns the IDs of the relations that are displayed to the current user.
   */
  protected function getDisplayedRelationIds(array $relations): array {
    $builder = $this->container->get('relationship_nodes.relationship_data_builder');
    $classify = new \ReflectionMethod($builder, 'classifyRelations');
    $cache = new CacheableMetadata();
    $classified = $classify->invoke($builder, $relations, 'en', $cache);
    $this->assertContains('user.permissions', $cache->getCacheContexts());
    return array_map(fn($item) => (int) $item['node']->id(), $classified);
  }

  /**
   * Unpublished relations and relations to unpublished nodes are hidden.
   */
  public function testUnpublishedRelationsAreHidden(): void {
    $a = $this->createPerson('A');
    $b = $this->createPerson('B');
    $hidden_person = $this->createPerson('Hidden', FALSE);
    $published = $this->createRelation($a, $b);
    $unpublished = $this->createRelation($a, $b, NULL, FALSE);
    $to_unpublished = $this->createRelation($a, $hidden_person);
    $relations = [$published, $unpublished, $to_unpublished];

    $this->container->get('account_switcher')->switchTo(new AnonymousUserSession());
    $this->assertSame([(int) $published->id()], $this->getDisplayedRelationIds($relations));
    $this->container->get('account_switcher')->switchBack();

    // Users who may view unpublished content see all relations.
    $this->setCurrentUser($this->createUser(['access content', 'bypass node access']));
    $this->assertSame(
      [(int) $published->id(), (int) $unpublished->id(), (int) $to_unpublished->id()],
      $this->getDisplayedRelationIds($relations)
    );
  }

}
