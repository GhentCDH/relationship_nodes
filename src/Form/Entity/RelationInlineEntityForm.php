<?php

namespace Drupal\relationship_nodes\Form\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\inline_entity_form\Form\NodeInlineForm;
use Drupal\node\NodeInterface;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationData\NodeHelper\ForeignKeyResolver;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Extended inline entity form for relationship nodes.
 *
 * Handles automatic population of foreign key fields in inline entity forms,
 * and renders table columns based on the active form mode's field components.
 */
class RelationInlineEntityForm extends NodeInlineForm {

  protected RouteMatchInterface $routeMatch;
  protected FieldNameResolver $fieldNameResolver;
  protected ForeignKeyResolver $foreignKeyResolver;
  protected BundleSettingsManager $bundleSettingsManager;


  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->routeMatch = $container->get('current_route_match');
    $instance->fieldNameResolver = $container->get('relationship_nodes.field_name_resolver');
    $instance->foreignKeyResolver = $container->get('relationship_nodes.foreign_key_field_resolver');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleSettingsManager = $container->get('relationship_nodes.bundle_settings_manager');
    return $instance;
  }


  /**
   * {@inheritdoc}
   */
  public function entityForm(array $entity_form, FormStateInterface $form_state) {
    $entity_form = parent::entityForm($entity_form, $form_state);

    if (empty($entity_form['#relation_extended_widget']) || $entity_form['#relation_extended_widget'] !== TRUE) {
      return $entity_form;
    }

    $relation_entity = $entity_form['#entity'];
    if (!$relation_entity instanceof NodeInterface) {
      return $entity_form;
    }

    $foreign_key = $this->foreignKeyResolver->getEntityFormForeignKeyField($relation_entity, $form_state);
    if ($foreign_key) {
      $entity_form[$foreign_key]['#attributes']['hidden'] = 'hidden';
      $entity_form['#rn__foreign_key'] = $foreign_key;
    }

    return $entity_form;
  }


  /**
   * {@inheritdoc}
   */
  public function entityFormSubmit(array &$entity_form, FormStateInterface $form_state) {
    parent::entityFormSubmit($entity_form, $form_state);

    if (empty($entity_form['#relation_extended_widget']) || $entity_form['#relation_extended_widget'] !== TRUE) {
      return;
    }

    if ($form_state->get('inline_entity_form') === NULL) {
      return;
    }

    $current_node = $this->routeMatch->getParameter('node');
    if (!($current_node instanceof NodeInterface)) {
      return; // New parent node: a submit handler binds the relation later.
    }

    if (empty($entity_form['#rn__foreign_key'])) {
      return;
    }

    $relation_node = $entity_form['#entity'];
    $foreign_key = $entity_form['#rn__foreign_key'];

    if (!is_string($foreign_key) || !$relation_node->hasField($foreign_key)) {
      return;
    }

    $relation_node->set($foreign_key, $current_node->id());
  }


  /**
   * {@inheritdoc}
   */
  public function getTableFields($bundles) {
    $typed_relation = FALSE;

    foreach ($bundles as $bundle) {
      $bundle_info = $this->bundleSettingsManager->getBundleInfo($bundle);
      if ($bundle_info && $bundle_info->isTypedRelation()) {
        $typed_relation = TRUE;
        break;
      }
    }

    $fields['rn_other_entity'] = [
      'type' => 'callback',
      'label' => $this->t('Related entity'),
      'weight' => 1,
      'callback' => [static::class, 'renderOtherEntity'],
    ];

    if ($typed_relation) {
      $fields['rn_relation_type'] = [
        'type' => 'callback',
        'label' => $this->t('Relation type'),
        'weight' => 2,
        'callback' => [static::class, 'renderRelationType'],
      ];
    }

    return $fields;
  }


  /**
   * Callback: renders the non-foreign-key related entity for a table row.
   *
   * Static so the callable stored in the form array contains no object
   * reference and survives form-cache serialization.
   *
   * @param EntityInterface $entity
   *   The relation entity for this row.
   * @param array $variables
   *   The preprocess variables array.
   *
   * @return array
   *   A render array.
   */
  public static function renderOtherEntity(EntityInterface $entity, array $variables): array {
    if (!$entity instanceof NodeInterface) {
      return [];
    }

    $fieldNameResolver = \Drupal::service('relationship_nodes.field_name_resolver');
    $foreignKeyResolver = \Drupal::service('relationship_nodes.foreign_key_field_resolver');
    $languageManager = \Drupal::languageManager();
    $current_node = \Drupal::routeMatch()->getParameter('node');

    $foreign_key = $foreignKeyResolver->getEntityForeignKeyField($entity, $current_node);
    $other_field = $foreign_key
      ? $fieldNameResolver->getOppositeRelatedEntityField($foreign_key)
      : $fieldNameResolver->getRelatedEntityFields(1);

    if (!$other_field || !$entity->hasField($other_field)) {
      return [];
    }

    $ref_arr = $entity->get($other_field)->referencedEntities();
    if (empty($ref_arr)) {
      return [];
    }

    $ref = reset($ref_arr);
    $langcode = $languageManager->getCurrentLanguage()->getId();
    if ($ref->hasTranslation($langcode)) {
      $ref = $ref->getTranslation($langcode);
    }

    return ['#markup' => $ref->label()];
  }


  /**
   * Callback: renders the relation type for a table row.
   *
   * Static — same serialization-safety reason as renderOtherEntity().
   * Uses mirror label when the foreign key is the second related entity field,
   * matching the perspective logic used in RelationshipDataBuilder.
   *
   * @param EntityInterface $entity
   *   The relation entity for this row.
   * @param array $variables
   *   The preprocess variables array.
   *
   * @return array
   *   A render array.
   */
  public static function renderRelationType(EntityInterface $entity, array $variables): array {
    if (!$entity instanceof NodeInterface) {
      return [];
    }

    $fieldNameResolver = \Drupal::service('relationship_nodes.field_name_resolver');
    $foreignKeyResolver = \Drupal::service('relationship_nodes.foreign_key_field_resolver');
    $mirrorProvider = \Drupal::service('relationship_nodes.mirror_provider');
    $languageManager = \Drupal::languageManager();

    $type_field = $fieldNameResolver->getRelationTypeField();
    if (!$entity->hasField($type_field) || $entity->get($type_field)->isEmpty()) {
      return [];
    }

    $term = $entity->get($type_field)->entity;
    if (!$term instanceof TermInterface) {
      return [];
    }

    $langcode = $languageManager->getCurrentLanguage()->getId();
    if ($term->hasTranslation($langcode)) {
      $term = $term->getTranslation($langcode);
    }

    $current_node = \Drupal::routeMatch()->getParameter('node');
    $fk_field = $foreignKeyResolver->getEntityForeignKeyField($entity, $current_node);
    $use_mirror = ($fk_field === $fieldNameResolver->getRelatedEntityFields(2));

    $label = $use_mirror ? ($mirrorProvider->getMirrorLabelFromTerm($term) ?? $term->getName()) : $term->getName();

    return ['#markup' => $label];
  }
}
