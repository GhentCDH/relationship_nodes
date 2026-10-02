<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting a Relationship Nodes field.
 *
 * Fields on relation bundles are managed by the module and cannot be
 * deleted here; other RN-created fields (e.g. mirror fields) can.
 */
class FieldDeleteForm extends ConfirmFormBase {

  /**
   * The bundle settings manager.
   */
  protected BundleSettingsManager $settingsManager;

  /**
   * The field UI manager.
   */
  protected FieldUiManager $uiUpdater;

  /**
   * The field config.
   */
  protected ?FieldConfig $fieldConfig = NULL;

  /**
   * Constructs a FieldDeleteForm object.
   *
   * @param \Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager $settingsManager
   *   The settings manager service.
   * @param FieldUiManager $uiUpdater
   *   The UI updater service.
   */
  public function __construct(BundleSettingsManager $settingsManager, FieldUiManager $uiUpdater) {
    $this->settingsManager = $settingsManager;
    $this->uiUpdater = $uiUpdater;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('relationship_nodes.bundle_settings_manager'),
      $container->get('relationship_nodes.field_ui_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'relationship_nodes_field_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the field %field?', [
      '%field' => $this->fieldConfig->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('All data stored in this field will be deleted. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->uiUpdater->getRedirectUrl($this->fieldConfig);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?FieldConfig $field_config = NULL) {
    $this->fieldConfig = $field_config;

    if ($this->isRelationBundleField()) {
      $this->messenger()->addError($this->t('This field cannot be deleted because it is managed by Relationship Nodes.'));
      return ['cancel' => ['#type' => 'link', '#title' => $this->t('Back'), '#url' => $this->getCancelUrl()]];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->isRelationBundleField()) {
      $this->fieldConfig->delete();
      $this->messenger()->addStatus($this->t('RN-managed field deleted.'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Checks whether the field belongs to a relation bundle.
   *
   * @return bool
   *   TRUE if the field is on a relation bundle.
   */
  protected function isRelationBundleField(): bool {
    $bundle_info = $this->settingsManager->getBundleInfo($this->fieldConfig->getTargetBundle(), $this->fieldConfig->getTargetEntityTypeId());
    return $bundle_info && $bundle_info->isRelation();
  }

}
