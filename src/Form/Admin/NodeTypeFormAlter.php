<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\NodeType;
use Drupal\relationship_nodes\Form\Admin\BundleFormHandler;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\Validation\ValidationService;


/**
 * Form alter service for node type forms.
 *
 * Adds relationship nodes configuration to node type forms.
 */
class NodeTypeFormAlter {

  use StringTranslationTrait;

  protected BundleFormHandler $formHandler;
  protected ValidationService $validationService;
  protected BundleSettingsManager $settingsManager;


  /**
   * Constructs a NodeTypeFormAlter object.
   *
   * @param BundleFormHandler $formHandler
   *   The form handler.
   * @param ValidationService $validationService
   *   The validation service.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager.
   */
  public function __construct(
    BundleFormHandler $formHandler,
    ValidationService $validationService,
    BundleSettingsManager $settingsManager
  ) {
    $this->formHandler = $formHandler;
    $this->validationService = $validationService;
    $this->settingsManager = $settingsManager;
  }


  /**
   * Alters node type forms to add relationship nodes settings.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, $form_id) {
    $node_type = $this->formHandler->getFormEntity($form_state);
    if (!$node_type instanceof NodeType) {
      return;
    }

    $form['relationship_nodes'] = [
      '#type' => 'details',
      '#title' => $this->t('Relationship Node'),
      '#collapsed' => FALSE,
      '#collapsible' => TRUE,
      '#tree' => TRUE,
      '#group' => 'additional_settings',
      '#weight' => 1,
      '#attributes' => ['class' => ['relationship-nodes-settings-form']],
    ];

    $form['relationship_nodes']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('This is a relationship node entity type'),
      '#default_value' => $this->settingsManager->getProperty($node_type, 'enabled'),
      '#description' => $this->t('If this is checked, this content type will be validated as a relationship node. It gets two "related entity fields" that need to be configured.'),
    ];

    $form['relationship_nodes']['typed_relation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('This is a typed relation: a vocabulary term describes the relation type'),
      '#default_value' => $this->settingsManager->getProperty($node_type, 'typed_relation'),
      '#description' => $this->t('If this is checked, this content type will be validated as a typed relationship node. It get an extra "relation type field" that needs to be configured.'),
      '#states' => [
        'visible' => [
          ':input[name="relationship_nodes[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['relationship_nodes']['auto_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide title field and generate a title automatically'),
      '#default_value' => $this->settingsManager->getProperty($node_type, 'auto_title'),
      '#description' => $this->t('If this is checked, the title field will automatically filled/updated on node save.'),
      '#states' => [
        'visible' => [
          ':input[name="relationship_nodes[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['#validate'][] = [$this->validationService, 'displayFormStateValidationErrors'];
    $form['actions']['submit']['#submit'][] = [$this->formHandler,  'handleSubmission'];
  }
}