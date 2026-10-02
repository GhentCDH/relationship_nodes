<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the automatic titles of relation nodes.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class AutoTitleTest extends RelationshipNodesKernelTestBase {

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
    'language',
    'entity_events',
    'inline_entity_form',
    'relationship_nodes',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('nl')->save();
    $type = NodeType::load(static::RELATION_BUNDLE);
    $type->setThirdPartySetting('relationship_nodes', 'auto_title', TRUE)->save();
  }

  /**
   * Titles in all translations, updated when a related node is renamed.
   */
  public function testTitles(): void {
    $ann = $this->createPerson('Ann');
    $ann->addTranslation('nl', ['title' => 'An'])->save();
    $bob = $this->createPerson('Bob');
    $relation = Node::create([
      'type' => static::RELATION_BUNDLE,
      'title' => 'Typed by an editor',
      'rn_related_entity_1' => $ann->id(),
      'rn_related_entity_2' => $bob->id(),
    ]);
    $relation->addTranslation('nl', ['title' => 'Ook getypt']);
    $relation->save();

    $relation = Node::load($relation->id());
    $this->assertSame('Relationship Ann - Bob', $relation->label());
    $this->assertSame('Relationship An - Bob', $relation->getTranslation('nl')->label());

    // Renaming a related node updates the titles of its relations.
    $bob = Node::load($bob->id());
    $bob->setTitle('Robert')->save();
    $relation = Node::load($relation->id());
    $this->assertSame('Relationship Ann - Robert', $relation->label());
    $this->assertSame('Relationship An - Robert', $relation->getTranslation('nl')->label());
  }

  /**
   * The title field is hidden and gets a provisional value.
   */
  public function testHiddenTitleField(): void {
    $relation = Node::create(['type' => static::RELATION_BUNDLE]);
    $form = ['title' => ['#type' => 'textfield']];
    $this->container->get('relationship_nodes.relation_title_generator')->hideTitleField($form, $relation);
    $this->assertFalse($form['title']['#access']);
    $this->assertNotSame('', (string) $relation->getTitle());

    // Without auto-title, the field stays.
    NodeType::load(static::RELATION_BUNDLE)->setThirdPartySetting('relationship_nodes', 'auto_title', FALSE)->save();
    $form = ['title' => ['#type' => 'textfield']];
    $this->container->get('relationship_nodes.relation_title_generator')->hideTitleField($form, Node::create(['type' => static::RELATION_BUNDLE]));
    $this->assertArrayNotHasKey('#access', $form['title']);
  }

}
