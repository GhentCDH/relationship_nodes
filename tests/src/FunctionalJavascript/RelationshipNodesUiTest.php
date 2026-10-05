<?php

namespace Drupal\Tests\relationship_nodes\FunctionalJavascript;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the admin forms, the relation widget and the display in a browser.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class RelationshipNodesUiTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'taxonomy',
    'field_ui',
    'language',
    'content_translation',
    'entity_events',
    'inline_entity_form',
    'relationship_nodes',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The relation widget of a person's form.
   */
  protected const WIDGET = 'computed_relationshipfield__person__person';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();
    ConfigurableLanguage::createFromLangcode('nl')->save();
    $this->drupalLogin($this->rootUser);
  }

  /**
   * Changing a vocabulary's mirror type asks for confirmation.
   */
  public function testVocabularyMirrorType(): void {
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    // A new vocabulary is saved without confirmation.
    $this->drupalGet('admin/structure/taxonomy/add');
    $page->fillField('name', 'Relation types');
    $assert->waitForElementVisible('css', '.machine-name-value');
    $page->checkField('relationship_nodes[enabled]');
    $page->selectFieldOption('relationship_nodes[referencing_type]', 'entity_reference');
    $page->pressButton('Save');
    $this->waitForMessage('The following relationship fields were created: rn_mirror_reference');
    $this->assertMirrorFields('relation_types', 'entity_reference');

    // Cancelling the confirmation keeps the settings.
    $this->drupalGet('admin/structure/taxonomy/manage/relation_types');
    $page->selectFieldOption('relationship_nodes[referencing_type]', 'string');
    $page->pressButton('Save');
    $assert->waitForElementVisible('css', '#relationship-nodes-modal-cancel')->click();
    $assert->assertNoElementAfterWait('css', '.ui-dialog');
    $this->assertTrue($page->find('css', 'input[name="relationship_nodes[referencing_type]"][value="entity_reference"]')->isChecked());
    $this->assertMirrorFields('relation_types', 'entity_reference');

    // Confirming saves them.
    $page->selectFieldOption('relationship_nodes[referencing_type]', 'string');
    $page->pressButton('Save');
    $assert->waitForElementVisible('css', '#relationship-nodes-modal-save')->click();
    $this->waitForMessage('The following relationship fields were created: rn_mirror_string');
    $this->assertMirrorFields('relation_types', 'string');
  }

  /**
   * Setting a term's mirror in the term form sets it on both terms.
   */
  public function testMirrorTerms(): void {
    $this->createRelationTypeVocabulary();
    $page = $this->getSession()->getPage();

    $this->drupalGet('admin/structure/taxonomy/manage/relation_types/add');
    $page->fillField('name[0][value]', 'employs');
    $page->pressButton('Save');
    $this->waitForMessage('Created new term employs.');
    $this->drupalGet('admin/structure/taxonomy/manage/relation_types/add');
    $page->fillField('name[0][value]', 'is employed by');
    $page->selectFieldOption('rn_mirror_reference', 'employs');
    $page->pressButton('Save');
    $this->waitForMessage('Created new term is employed by.');

    $employs = $this->loadTerm('employs');
    $employed = $this->loadTerm('is employed by');
    $this->assertSame($employed->id(), $employs->get('rn_mirror_reference')->target_id);
    $this->assertSame($employs->id(), $employed->get('rn_mirror_reference')->target_id);
  }

  /**
   * Relations are created in the node form and shown from both sides.
   */
  public function testRelations(): void {
    $this->createRelationTypeVocabulary();
    $employs = Term::create(['vid' => 'relation_types', 'name' => 'employs']);
    $employs->save();
    Term::create(['vid' => 'relation_types', 'name' => 'is employed by', 'rn_mirror_reference' => $employs->id()])->save();
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    // A relation content type created in the UI has its fields on its form.
    $this->drupalGet('admin/structure/types/add');
    // The machine name is generated from the name: rel_person_person.
    $page->fillField('name', 'Rel person person');
    $assert->waitForElementVisible('css', '.machine-name-value');
    $page->clickLink('Relationship Node');
    $page->checkField('relationship_nodes[enabled]');
    $page->checkField('relationship_nodes[typed_relation]');
    $page->checkField('relationship_nodes[auto_title]');
    $page->pressButton('Save');
    $this->waitForMessage('The content type Rel person person has been added.');
    $this->assertNotNull(NodeType::load('rel_person_person'));
    $form_display = $this->getDisplayRepository()->getFormDisplay('node', 'rel_person_person');
    foreach (['rn_related_entity_1', 'rn_related_entity_2', 'rn_relation_type'] as $field_name) {
      $this->assertNotNull($form_display->getComponent($field_name), "$field_name is on the form.");
    }

    // The field list links relation fields to the module's field form.
    $this->drupalGet('admin/structure/types/manage/rel_person_person/fields');
    $assert->elementExists('css', 'a[href$="/fields/relationship-nodes-edit/node.rel_person_person.rn_relation_type"]');

    // The relation type target is set in the module's field form.
    foreach (['rn_related_entity_1', 'rn_related_entity_2'] as $field_name) {
      FieldConfig::loadByName('node', 'rel_person_person', $field_name)
        ->setSetting('handler_settings', ['target_bundles' => ['person' => 'person']])
        ->save();
    }
    $this->drupalGet('admin/structure/types/manage/rel_person_person/fields/relationship-nodes-edit/node.rel_person_person.rn_relation_type');
    $page->selectFieldOption('target_bundle', 'relation_types');
    $page->pressButton('Save');
    $this->waitForMessage('Field rn_relation_type updated.');
    // The fields were created by the site under test: refresh the
    // definitions known to the test.
    $this->resetAll();
    $this->assertSame(['relation_types' => 'relation_types'], FieldConfig::loadByName('node', 'rel_person_person', 'rn_relation_type')->getSetting('handler_settings')['target_bundles']);

    $this->getDisplayRepository()->getFormDisplay('node', 'person')
      ->setComponent(static::WIDGET, ['type' => 'relation_extended_ief_complex_widget'])
      ->save();
    $this->getDisplayRepository()->getViewDisplay('node', 'person')
      ->setComponent(static::WIDGET, ['type' => 'relationship_formatter'])
      ->save();

    // Add a relation from the form of a new node.
    $bob = Node::create(['type' => 'person', 'title' => 'Bob']);
    $bob->save();
    $this->drupalGet('node/add/person');
    $page->fillField('title[0][value]', 'Ann');
    $page->pressButton('Add new node');
    $other = $assert->waitForField(static::WIDGET . '[form][0][rn_related_entity_2][0][target_id]');
    $other->setValue('Bob (' . $bob->id() . ')');
    $page->selectFieldOption(static::WIDGET . '[form][0][rn_relation_type]', 'employs');
    $page->pressButton('Add node (saved with parent)');
    $table = $assert->waitForElement('css', '.ief-entity-table');
    $this->assertStringContainsString('Bob', $table->getText());
    $this->assertStringContainsString('employs', $table->getText());
    $page->pressButton('Save');
    $this->waitForMessage('Person Ann has been created.');

    $ann = $this->loadNode('Ann');
    $relations = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'rel_person_person']);
    $this->assertCount(1, $relations);
    $relation = reset($relations);
    $this->assertSame($ann->id(), $relation->get('rn_related_entity_1')->target_id);
    $this->assertSame($bob->id(), $relation->get('rn_related_entity_2')->target_id);
    $this->assertSame($employs->id(), $relation->get('rn_relation_type')->target_id);
    $this->assertSame('Relationship Ann - Bob', $relation->label());

    // From the other side, the mirror label is shown.
    $this->drupalGet($ann->toUrl());
    $this->assertRelationShown('Bob', 'employs');
    $this->drupalGet($bob->toUrl());
    $this->assertRelationShown('Ann', 'is employed by');

    // In another language, the related node's translation is shown.
    $ann->addTranslation('nl', ['title' => 'Anna'])->save();
    $bob->addTranslation('nl', ['title' => 'Bobbie'])->save();
    $this->drupalGet('nl/node/' . $bob->id());
    $this->assertRelationShown('Anna', 'is employed by');

    // Editing the relation from the other side offers the mirror labels.
    $this->drupalGet($bob->toUrl('edit-form'));
    $page->find('css', '.ief-entity-table')->pressButton('Edit');
    $select = $assert->waitForElement('css', 'select[name$="[rn_relation_type]"]');
    $options = array_map(fn($option) => $option->getText(), $select->findAll('css', 'option'));
    $this->assertSame(['- None -', 'is employed by', 'employs'], $options);
    // The relation's type is selected, shown with its mirror label.
    $this->assertSame((string) $employs->id(), $select->getValue());
    $this->assertSame('is employed by', $select->find('css', 'option[value="' . $employs->id() . '"]')->getText());
  }

  /**
   * Waits for a status message, after a form submission.
   */
  protected function waitForMessage(string $message): void {
    $this->assertTrue($this->assertSession()->waitForText($message, 20000), "Message shown: $message");
  }

  /**
   * Creates the relation type vocabulary with term reference mirrors.
   */
  protected function createRelationTypeVocabulary(): void {
    $vocab = Vocabulary::create(['vid' => 'relation_types', 'name' => 'Relation types']);
    $vocab->setThirdPartySetting('relationship_nodes', 'enabled', TRUE);
    $vocab->setThirdPartySetting('relationship_nodes', 'referencing_type', 'entity_reference');
    $vocab->save();
    \Drupal::service('relationship_nodes.relationship_field_manager')->implementFieldUpdates($vocab, TRUE);
  }

  /**
   * Asserts which mirror fields and setting a vocabulary has.
   */
  protected function assertMirrorFields(string $vid, string $referencing_type): void {
    \Drupal::configFactory()->reset();
    \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->resetCache();
    \Drupal::entityTypeManager()->getStorage('field_config')->resetCache();
    $vocab = Vocabulary::load($vid);
    $this->assertSame($referencing_type, $vocab->getThirdPartySetting('relationship_nodes', 'referencing_type'));
    $fields = ['entity_reference' => 'rn_mirror_reference', 'string' => 'rn_mirror_string'];
    foreach ($fields as $type => $field_name) {
      $field = FieldConfig::loadByName('taxonomy_term', $vid, $field_name);
      $type === $referencing_type ? $this->assertNotNull($field, "$field_name exists.") : $this->assertNull($field, "$field_name does not exist.");
    }
  }

  /**
   * Asserts that the page shows a relation with a node and type.
   */
  protected function assertRelationShown(string $related, string $type): void {
    $this->assertSession()->pageTextContains($related);
    $this->assertSession()->pageTextContains($type);
  }

  /**
   * Loads a term by name.
   */
  protected function loadTerm(string $name): Term {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $storage->resetCache();
    $terms = $storage->loadByProperties(['name' => $name]);
    return reset($terms);
  }

  /**
   * Loads a node by title.
   */
  protected function loadNode(string $title): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    $nodes = $storage->loadByProperties(['title' => $title]);
    return reset($nodes);
  }

  /**
   * Returns the entity display repository.
   */
  protected function getDisplayRepository(): EntityDisplayRepositoryInterface {
    return \Drupal::service('entity_display.repository');
  }

}
