<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\relationship_nodes_search\SearchAPI\Query\NestedChildFieldCondition;
use Drupal\relationship_nodes_search\SearchAPI\Query\NestedParentFieldConditionGroup;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Views relationship filter on a Search API index.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class SearchViewsTest extends SearchKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_ui',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'search_api',
    'facets',
    'facets_exposed_filters',
    'better_exposed_filters',
    'elasticsearch_connector',
    'entity_events',
    'inline_entity_form',
    'relationship_nodes',
    'relationship_nodes_search',
    'search_api_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['views']);
    // Views need an enabled index, so a server with Search API's test backend.
    Server::create(['id' => 'test_server', 'name' => 'Test', 'backend' => 'search_api_test'])->save();
    $this->index->setServer(Server::load('test_server'))->setStatus(TRUE)->save();
  }

  /**
   * Creates a view with a relationship filter.
   */
  protected function createView(array $field_settings, bool $exposed = FALSE): void {
    $table = 'search_api_index_test_index';
    View::create([
      'id' => 'relations_view',
      'label' => 'Relations',
      'base_table' => $table,
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_title' => 'Default',
          'display_options' => [
            'fields' => [
              'search_api_id' => [
                'id' => 'search_api_id',
                'table' => $table,
                'field' => 'search_api_id',
                'plugin_id' => 'standard',
              ],
            ],
            'filters' => [
              'relations' => [
                'id' => 'relations',
                'table' => $table,
                'field' => 'relationship_info_rel_person_person_nested',
                'plugin_id' => 'search_api_relationship_filter',
                'exposed' => $exposed,
                'field_settings' => $field_settings,
              ],
            ],
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Finds the nested condition group a view adds to its query.
   */
  protected function findNestedGroup(ConditionGroupInterface $group): ?NestedParentFieldConditionGroup {
    foreach ($group->getConditions() as $condition) {
      if ($condition instanceof NestedParentFieldConditionGroup) {
        return $condition;
      }
      if ($condition instanceof ConditionGroupInterface && ($found = $this->findNestedGroup($condition))) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * A fixed relationship filter adds a nested condition to the query.
   */
  public function testFixedFilter(): void {
    $this->createView([
      'calculated_related_name' => ['enabled' => TRUE, 'field_operator' => '<>', 'value' => 'Ann'],
      'calculated_relation_type_name' => ['enabled' => FALSE, 'value' => 'ignored'],
    ]);
    $view = Views::getView('relations_view');
    $view->setDisplay('default');
    $view->build();
    $this->assertEmpty($view->build_info['fail'] ?? NULL, 'The view builds.');

    $group = $this->findNestedGroup($view->getQuery()->getSearchApiQuery()->getConditionGroup());
    $this->assertNotNull($group);
    $this->assertSame(static::NESTED, $group->getParentFieldName());
    $conditions = $group->getConditions();
    $this->assertCount(1, $conditions, 'Only enabled fields are filtered on.');
    $this->assertInstanceOf(NestedChildFieldCondition::class, $conditions[0]);
    $this->assertSame('calculated_related_name', $conditions[0]->getChildFieldName());
    $this->assertSame('Ann', $conditions[0]->getValue());
    $this->assertSame('<>', $conditions[0]->getOperator());
  }

  /**
   * An exposed filter without input adds no condition.
   */
  public function testExposedFilterWithoutInput(): void {
    $this->createView(['calculated_related_name' => ['enabled' => TRUE, 'field_operator' => '=']], TRUE);
    $view = Views::getView('relations_view');
    $view->setDisplay('default');
    $view->setExposedInput([]);
    $view->build();
    $this->assertNull($this->findNestedGroup($view->getQuery()->getSearchApiQuery()->getConditionGroup()));
  }

  /**
   * The settings form lists the relation's indexed fields.
   */
  public function testOptionsForm(): void {
    $this->createView(['calculated_related_name' => ['enabled' => TRUE, 'value' => 'Ann']]);
    $view = Views::getView('relations_view');
    $view->setDisplay('default');
    $view->initHandlers();
    $form = [];
    $form_state = new FormState();
    $view->filter['relations']->buildOptionsForm($form, $form_state);
    $this->assertArrayHasKey('field_settings', $form);
    $this->assertArrayHasKey('calculated_related_name', $form['field_settings']);
    // The raw references are replaced by the calculated fields, and relation
    // type fields need the relation type field in the index.
    $this->assertArrayNotHasKey('rn_related_entity_1', $form['field_settings']);
    $this->assertArrayNotHasKey('calculated_relation_type_name', $form['field_settings']);
    $this->assertSame('Ann', $form['field_settings']['calculated_related_name']['value']['#default_value']);
  }

}
