<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests enabling relations in the node type and vocabulary forms.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class AdminFormsTest extends RelationshipNodesKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setCurrentUser($this->createUser([], NULL, TRUE));
  }

  /**
   * Submits an entity form and returns its errors.
   */
  protected function submitEntityForm($entity, string $operation, array $values, ?string $op = NULL): array {
    $form_object = $this->container->get('entity_type.manager')->getFormObject($entity->getEntityTypeId(), $operation);
    $form_object->setEntity($entity);
    // By default the form's main button, whose label differs between versions.
    $op ??= (string) $this->container->get('form_builder')->getForm($form_object)['actions']['submit']['#value'];
    $form_object->setEntity($entity);
    $form_state = (new FormState())->setValues($values + ['op' => $op]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    return array_map('strval', $form_state->getErrors());
  }

  /**
   * Enabling relations on an existing node type creates the relation fields.
   */
  public function testEnableOnExistingNodeType(): void {
    NodeType::create(['type' => 'membership', 'name' => 'Membership'])->save();
    $errors = $this->submitEntityForm(NodeType::load('membership'), 'edit', [
      'name' => 'Membership',
      'type' => 'membership',
      // In programmatic submissions, an unchecked checkbox is NULL, not 0.
      'relationship_nodes' => ['enabled' => 1, 'typed_relation' => NULL, 'auto_title' => 1],
    ]);
    $this->assertSame([], $errors);

    $type = NodeType::load('membership');
    $this->assertTrue((bool) $type->getThirdPartySetting('relationship_nodes', 'enabled'));
    $this->assertTrue((bool) $type->getThirdPartySetting('relationship_nodes', 'auto_title'));
    $this->assertNotNull(FieldConfig::loadByName('node', 'membership', 'rn_related_entity_1'));
    $this->assertNotNull(FieldConfig::loadByName('node', 'membership', 'rn_related_entity_2'));
    $this->assertNull(FieldConfig::loadByName('node', 'membership', 'rn_relation_type'), 'Untyped relations have no relation type field.');
  }

  /**
   * Relations can be enabled when a node type is created.
   */
  public function testEnableOnNewNodeType(): void {
    $errors = $this->submitEntityForm(NodeType::create(['type' => '']), 'add', [
      'name' => 'Collaboration',
      'type' => 'collaboration',
      'relationship_nodes' => ['enabled' => 1, 'typed_relation' => 1, 'auto_title' => NULL],
    ]);
    $this->assertSame([], $errors);

    $type = NodeType::load('collaboration');
    $this->assertNotNull($type);
    $this->assertTrue((bool) $type->getThirdPartySetting('relationship_nodes', 'typed_relation'));
    $this->assertNotNull(FieldConfig::loadByName('node', 'collaboration', 'rn_relation_type'));
  }

  /**
   * A relation type vocabulary gets its mirror field.
   */
  public function testEnableOnVocabulary(): void {
    $errors = $this->submitEntityForm(Vocabulary::create(['vid' => '']), 'default', [
      'name' => 'Roles',
      'vid' => 'roles',
      'relationship_nodes' => ['enabled' => 1, 'referencing_type' => 'entity_reference', 'confirm_mirror_change' => 0],
    ], 'Hidden Save');
    $this->assertSame([], $errors);

    $vocab = Vocabulary::load('roles');
    $this->assertNotNull($vocab);
    $this->assertSame('entity_reference', $vocab->getThirdPartySetting('relationship_nodes', 'referencing_type'));
    $this->assertNotNull(FieldConfig::loadByName('taxonomy_term', 'roles', 'rn_mirror_reference'));
    $this->assertNull(FieldConfig::loadByName('taxonomy_term', 'roles', 'rn_mirror_string'));
  }

}
