<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing relationship node field configurations.
 */
class FieldConfigForm extends FormBase {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The field name resolver.
   */
  protected FieldNameResolver $fieldResolver;

  /**
   * The bundle settings manager.
   */
  protected BundleSettingsManager $settingsManager;

  /**
   * The field UI manager.
   */
  protected FieldUiManager $uiUpdater;

  /**
   * The bundle info service.
   */
  protected BundleInfoService $bundleInfoService;

  /**
   * The field config.
   */
  protected ?FieldConfig $fieldConfig = NULL;

  /**
   * The field name.
   */
  protected ?string $fieldName = NULL;

  /**
   * The entity type.
   */
  protected ?string $entityType = NULL;

  /**
   * The bundle.
   */
  protected ?string $bundle = NULL;

  /**
   * Constructs a FieldConfigForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\relationship_nodes\RelationField\FieldNameResolver $fieldResolver
   *   The field name resolver.
   * @param \Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager $settingsManager
   *   The settings manager.
   * @param \Drupal\relationship_nodes\Form\Admin\FieldUiManager $uiUpdater
   *   The UI updater.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FieldNameResolver $fieldResolver,
    BundleSettingsManager $settingsManager,
    FieldUiManager $uiUpdater,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fieldResolver = $fieldResolver;
    $this->settingsManager = $settingsManager;
    $this->uiUpdater = $uiUpdater;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new static(
      $container->get('entity_type.manager'),
      $container->get('relationship_nodes.field_name_resolver'),
      $container->get('relationship_nodes.bundle_settings_manager'),
      $container->get('relationship_nodes.field_ui_manager')
    );
    $instance->bundleInfoService = $container->get('relationship_nodes.bundle_info_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'relation_field_config_form';
  }

  /**
   * Gets the form title.
   *
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The form title.
   */
  public function getTitle(string $bundle, string $field_name): TranslatableMarkup {
    $bundle_entity = $this->entityTypeManager
      ->getStorage($this->entityType === 'node' ? 'node_type' : 'taxonomy_vocabulary')
      ->load($bundle);

    $bundle_label = $bundle_entity ? $bundle_entity->label() : $bundle;

    return $this->t('Configure @field for @type', [
      '@field' => $field_name,
      '@type' => $bundle_label,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?FieldConfig $field_config = NULL) {
    // The routes always pass the field; nothing to edit without one.
    if (!$field_config) {
      return $form;
    }
    $this->fieldConfig = $field_config;
    $this->entityType = $field_config->getTargetEntityTypeId();
    $this->fieldName = $field_config->getName();
    $this->bundle = $field_config->getTargetBundle();

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $field_config->label(),
      '#required' => TRUE,
    ];

    if ($this->entityType === 'node') {
      $form['target_bundle'] = [
        '#type' => 'select',
        '#title' => $this->t('Target node type'),
        '#options' => $this->getAllNodeTypes(),
        '#default_value' => $this->getCurrentTargetBundle($this->bundle, $this->fieldName),
        '#required' => TRUE,
        '#multiple' => FALSE,
      ];
      if ($this->fieldName == $this->fieldResolver->getRelationTypeField()) {
        $form['target_bundle']['#title'] = $this->t('Target relation type vocabulary');
        $form['target_bundle']['#options'] = $this->getAllRelationVocabs();
      }
      elseif (in_array($this->fieldName, $this->fieldResolver->getRelatedEntityFields())) {
        $form['target_bundle']['#title'] = $this->t('Target node type');
        $form['target_bundle']['#options'] = $this->getAllNodeTypes();
      }
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];
    $bundle_info = $this->settingsManager->getBundleInfo($this->bundle, $this->entityType);
    if (!$bundle_info || !$bundle_info->isRelation()) {
      $form['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('Delete RN Field'),
        '#url' => Url::fromRoute('relationship_nodes.rn_field_delete', [
          'field_config' => $this->fieldConfig->id(),
        ]),
        '#attributes' => [
          'class' => ['button', 'button--danger'],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Only one relation bundle may connect the same two bundles: the computed
   * relationship fields are named after the bundles they connect.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($this->entityType !== 'node') {
      return;
    }
    $target = $form_state->getValue('target_bundle');
    $current = $this->getCurrentTargetBundle($this->bundle, $this->fieldName);

    // Existing relations would point to content of the old target, and drop
    // out of the computed relationship fields of both content types.
    if ($target && $current && $target !== $current) {
      $in_use = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $this->bundle)
        ->exists($this->fieldName)
        ->count()
        ->execute();
      if ($in_use) {
        $form_state->setErrorByName('target_bundle', $this->formatPlural($in_use,
          'The target cannot be changed: 1 relation uses this field. Delete or change it first.',
          'The target cannot be changed: @count relations use this field. Delete or change them first.'));
        return;
      }
    }

    if (!in_array($this->fieldName, $this->fieldResolver->getRelatedEntityFields(), TRUE)) {
      return;
    }
    $other_field = $this->fieldResolver->getOppositeRelatedEntityField($this->fieldName);
    $other_target = $other_field ? $this->getCurrentTargetBundle($this->bundle, $other_field) : NULL;
    if (!$target || !$other_target) {
      return;
    }
    $existing = $this->bundleInfoService
      ->findRelationBundleForPair($target, $other_target, $this->bundle);
    if ($existing) {
      $form_state->setErrorByName('target_bundle', $this->t('The relation type %existing already connects %a and %b. Use one relation type per pair of content types.', [
        '%existing' => $existing,
        '%a' => $target,
        '%b' => $other_target,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $field = $this->entityTypeManager->getStorage('field_config')->load("{$this->entityType}.{$this->bundle}.{$this->fieldName}");
    if (!$field) {
      $this->messenger()->addError($this->t('Field not found.'));
      return;
    }

    $field->setLabel($form_state->getValue('label'));

    if ($this->entityType === 'node') {
      $target = $form_state->getValue('target_bundle');
      $field->setSetting('handler_settings', [
        'target_bundles' => [$target => $target],
      ]);
    }

    $field->save();

    $this->messenger()->addStatus($this->t('Field @field updated.', [
      '@field' => $this->fieldName,
    ]));

    $form_state->setRedirectUrl($this->uiUpdater->getRedirectUrl($field));
  }

  /**
   * Gets all available node types.
   *
   * @return array
   *   Array of node type labels keyed by machine name.
   */
  protected function getAllNodeTypes(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $options[$type->id()] = $type->label();
    }
    return $options;
  }

  /**
   * Gets all relation vocabularies.
   *
   * @return array
   *   Array of vocabulary labels keyed by machine name.
   */
  protected function getAllRelationVocabs(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $type) {
      $bundle_info = $this->settingsManager->getBundleInfo($type);
      if ($bundle_info && $bundle_info->isRelation()) {
        $options[$type->id()] = $type->label();
      }
    }
    return $options;
  }

  /**
   * Gets the current target bundle for a field.
   *
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field name.
   *
   * @return string|null
   *   The target bundle or NULL.
   */
  protected function getCurrentTargetBundle(string $bundle, string $field_name): ?string {
    $field = $this->entityTypeManager
      ->getStorage('field_config')
      ->load("{$this->entityType}.$bundle.$field_name");

    if ($field) {
      $handler_settings = $field->getSetting('handler_settings');
      if (!empty($handler_settings['target_bundles']) && is_array($handler_settings['target_bundles'])) {
        return reset($handler_settings['target_bundles']);
      }
    }
    return NULL;
  }

}
