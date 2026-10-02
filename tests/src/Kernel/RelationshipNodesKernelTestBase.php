<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;

/**
 * Sets up a person content type with a typed person–person relation.
 *
 * Relation bundle 'rel_person_person' links two persons; its relation type
 * vocabulary 'reltype_person_person' uses entity reference mirrors.
 */
abstract class RelationshipNodesKernelTestBase extends KernelTestBase {

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
    'entity_events',
    'inline_entity_form',
    'relationship_nodes',
  ];

  /**
   * The relation bundle.
   */
  protected const RELATION_BUNDLE = 'rel_person_person';

  /**
   * The relation type vocabulary.
   */
  protected const RELATION_VOCAB = 'reltype_person_person';

  /**
   * The computed field listing a person's person–person relations.
   */
  protected const COMPUTED_FIELD = 'computed_relationshipfield__person__person';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'taxonomy', 'relationship_nodes']);

    NodeType::create(['type' => 'person', 'name' => 'Person'])->save();

    $vocab = Vocabulary::create(['vid' => static::RELATION_VOCAB, 'name' => 'Relation types']);
    $vocab->setThirdPartySetting('relationship_nodes', 'enabled', TRUE);
    $vocab->setThirdPartySetting('relationship_nodes', 'referencing_type', 'entity_reference');
    $vocab->save();
    $this->container->get('relationship_nodes.relationship_field_manager')->implementFieldUpdates($vocab);

    $type = NodeType::create(['type' => static::RELATION_BUNDLE, 'name' => 'Person relation']);
    $type->setThirdPartySetting('relationship_nodes', 'enabled', TRUE);
    $type->setThirdPartySetting('relationship_nodes', 'typed_relation', TRUE);
    $type->setThirdPartySetting('relationship_nodes', 'auto_title', FALSE);
    // Saving the node type creates the relation fields.
    $type->save();

    // Point the relation fields at their targets, as the field UI does.
    $targets = [
      'rn_related_entity_1' => 'person',
      'rn_related_entity_2' => 'person',
      'rn_relation_type' => static::RELATION_VOCAB,
    ];
    foreach ($targets as $field_name => $target) {
      $field = FieldConfig::loadByName('node', static::RELATION_BUNDLE, $field_name);
      $this->assertNotNull($field, "Relation field $field_name was created.");
      $field->setSetting('handler_settings', ['target_bundles' => [$target => $target]])->save();
    }
  }

  /**
   * Creates a person.
   */
  protected function createPerson(string $title, bool $published = TRUE): NodeInterface {
    $node = Node::create(['type' => 'person', 'title' => $title, 'status' => $published]);
    $node->save();
    return $node;
  }

  /**
   * Creates a relation between two persons.
   */
  protected function createRelation(NodeInterface $a, NodeInterface $b, ?TermInterface $type = NULL, bool $published = TRUE): NodeInterface {
    $relation = Node::create([
      'type' => static::RELATION_BUNDLE,
      'title' => $a->label() . ' – ' . $b->label(),
      'status' => $published,
      'rn_related_entity_1' => $a->id(),
      'rn_related_entity_2' => $b->id(),
      'rn_relation_type' => $type?->id(),
    ]);
    $relation->save();
    return $relation;
  }

  /**
   * Creates a relation type term.
   */
  protected function createRelationType(string $name): TermInterface {
    $term = Term::create(['vid' => static::RELATION_VOCAB, 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Returns the IDs of a person's relations through the computed field.
   */
  protected function getComputedRelationIds(NodeInterface $person): array {
    $person = Node::load($person->id());
    return array_map('intval', array_column($person->get(static::COMPUTED_FIELD)->getValue(), 'target_id'));
  }

}
