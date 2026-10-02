<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Routing\RouteMatch;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests config import detection and access to the RN field routes.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class ConfigAndRoutesTest extends RelationshipNodesKernelTestBase {

  use UserCreationTrait;

  /**
   * The module's enable/disable detection during a config import.
   */
  public function testModuleStateChange(): void {
    $subscriber = $this->container->get('relationship_nodes.config_import_subscriber');
    $method = new \ReflectionMethod($subscriber, 'getModuleStateChange');
    $storage = function (bool $installed): MemoryStorage {
      $storage = new MemoryStorage();
      $modules = ['node' => 0] + ($installed ? ['relationship_nodes' => 0] : []);
      $storage->write('core.extension', ['module' => $modules]);
      return $storage;
    };

    // The comparer's source is the imported config, the target the active one.
    $this->assertSame('enabling', $method->invoke($subscriber, new StorageComparer($storage(TRUE), $storage(FALSE))));
    $this->assertSame('disabling', $method->invoke($subscriber, new StorageComparer($storage(FALSE), $storage(TRUE))));
    $this->assertNull($method->invoke($subscriber, new StorageComparer($storage(TRUE), $storage(TRUE))));
  }

  /**
   * The edit and delete routes only accept the module's own fields.
   */
  public function testFieldRouteAccess(): void {
    $check = $this->container->get('relationship_nodes.rn_field_access_check');
    $admin = $this->createUser(['administer content types']);
    $editor = $this->createUser(['access content']);

    FieldStorageConfig::create(['field_name' => 'field_other', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_other', 'entity_type' => 'node', 'bundle' => static::RELATION_BUNDLE])->save();
    NodeType::create(['type' => 'other', 'name' => 'Other'])->save();

    $rn_field = FieldConfig::loadByName('node', static::RELATION_BUNDLE, 'rn_related_entity_1');
    $other_field = FieldConfig::loadByName('node', static::RELATION_BUNDLE, 'field_other');
    $access = function (FieldConfig $field, ?string $bundle, $account) use ($check): bool {
      $parameters = ['field_config' => $field];
      $raw = ['field_config' => $field->id()];
      if ($bundle) {
        $parameters['node_type'] = NodeType::load($bundle);
        $raw['node_type'] = $bundle;
      }
      // RouteMatch only keeps parameters that are variables of the route path.
      $path = $bundle ? '/test/{node_type}/{field_config}' : '/test/{field_config}';
      $route_match = new RouteMatch('test', new Route($path), $parameters, $raw);
      return $check->access($route_match, $account)->isAllowed();
    };

    $this->assertTrue($access($rn_field, static::RELATION_BUNDLE, $admin), 'RN field on its own bundle.');
    $this->assertTrue($access($rn_field, NULL, $admin), 'RN field on the delete route.');
    $this->assertFalse($access($rn_field, 'other', $admin), 'RN field on another bundle.');
    $this->assertFalse($access($other_field, static::RELATION_BUNDLE, $admin), 'Field not created by the module.');
    $this->assertFalse($access($rn_field, static::RELATION_BUNDLE, $editor), 'User without permission.');
  }

}
