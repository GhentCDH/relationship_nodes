<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\search_api\Entity\Index;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that uninstalling the search submodule cleans up dependent config.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class SearchUninstallTest extends SearchKernelTestBase {

  /**
   * The index and a view lose their relationship parts, but survive.
   */
  public function testUninstall(): void {
    $this->installConfig(['views']);
    // Uninstalling a module clears its user data.
    $this->installSchema('user', ['users_data']);
    View::create([
      'id' => 'relations_view',
      'label' => 'Relations',
      'base_table' => 'search_api_index_test_index',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_title' => 'Default',
          'display_options' => [
            'fields' => [
              'search_api_id' => [
                'id' => 'search_api_id',
                'table' => 'search_api_index_test_index',
                'field' => 'search_api_id',
                'plugin_id' => 'standard',
              ],
            ],
            'filters' => [
              'relations' => [
                'id' => 'relations',
                'table' => 'search_api_index_test_index',
                // Search API's Views field name for the relationship field.
                'field' => 'relationship_info_rel_person_person_nested',
                'plugin_id' => 'search_api_relationship_filter',
              ],
            ],
          ],
        ],
      ],
    ])->save();
    $view = View::load('relations_view');
    $this->assertContains('relationship_nodes_search', $view->getDependencies()['module'] ?? []);
    $this->assertContains('relationship_nodes_search', $this->index->getDependencies()['module'] ?? []);

    $this->container->get('module_installer')->uninstall(['relationship_nodes_search']);

    $index = Index::load('test_index');
    $this->assertNotNull($index, 'The index still exists.');
    $this->assertNull($index->getField(static::NESTED));
    $this->assertFalse($index->isValidProcessor('relationship_indexer'));
    $view = View::load('relations_view');
    $this->assertNotNull($view, 'The view still exists.');
    $this->assertArrayNotHasKey('relations', $view->getDisplay('default')['display_options']['filters'] ?? []);
  }

}
