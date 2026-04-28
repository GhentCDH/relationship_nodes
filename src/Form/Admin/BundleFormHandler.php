<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;
use Drupal\relationship_nodes\Form\Admin\FieldUiManager;


/**
 * Service for handling relationship bundle form submissions.
 */
class BundleFormHandler {

  use StringTranslationTrait;

  protected BundleSettingsManager $settingsManager;
  protected RelationshipFieldManager $relationFieldManager;
  protected FieldUiManager $fieldUiUpdater;


  /**
   * Constructs a BundleFormHandler object.
   *
   * @param BundleSettingsManager $settingsManager
   *   The settings manager.
   * @param RelationshipFieldManager $relationFieldManager
   *   The field configurator.
   * @param FieldUiManager $fieldUiUpdater
   *   The field UI updater.
   */
  public function __construct(
    BundleSettingsManager $settingsManager,
    RelationshipFieldManager $relationFieldManager,
    FieldUiManager $fieldUiUpdater,
  ) {
    $this->settingsManager = $settingsManager;
    $this->relationFieldManager = $relationFieldManager;
    $this->fieldUiUpdater = $fieldUiUpdater;
  }


  /**
   * Handles form submission for relationship bundle forms.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public function handleSubmission(array &$form, FormStateInterface $form_state): void {
    $entity = $this->getFormEntity($form_state);
    if (!$entity) {
      return;
    }
    $values = $form_state->getValue('relationship_nodes') ?? [];
    $this->settingsManager->setProperties($entity, $values); 
    $bundle_info = $this->settingsManager->getBundleInfo($entity);   
    if (!$bundle_info || !$bundle_info->isRelation()) {
      return;
    }

    $updates = $this->relationFieldManager->implementFieldUpdates($entity);

    if (isset($updates['created'])) {
      $this->showFieldCreationMessage($entity, $updates['created']);
    }
  }



  /**
   * Gets the form entity from form state.
   *
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return NodeType|Vocabulary|null
   *   The form entity or NULL.
   */
  public function getFormEntity(FormStateInterface $form_state): NodeType|Vocabulary|null {
    $entity = $form_state->getFormObject()->getEntity();
    return ($entity instanceof NodeType || $entity instanceof Vocabulary) ? $entity : null;
  }


  protected function showFieldCreationMessage(ConfigEntityBundleBase $entity, array $missing_fields): void {
    if (empty($missing_fields)) {
      return;
    }

    $url_info = $this->fieldUiUpdater->getDefaultRoutingInfo($this->settingsManager->getEntityTypeObjectClass($entity));
    $url = Url::fromRoute($url_info['field_ui_fields_route'], [
      $url_info['bundle_param_key'] => $entity->id(),
    ]);

    $link = Link::fromTextAndUrl($this->t('Manage fields'), $url)->toString();

    if ($entity instanceof NodeType) {
      $message = 'The following relationship fields were created but need to be configured: @fields. @link';
    } else {
      $message = 'The following relationship fields were created: @fields. You can review them here: @link';
    }
    
    \Drupal::messenger()->addStatus($this->t(
      $message, ['@fields' => implode(', ', array_keys($missing_fields)), '@link' => $link]
    ));
  }
}