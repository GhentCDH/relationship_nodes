<?php

namespace Drupal\relationship_nodes\Form\Admin;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\relationship_nodes\Form\Admin\BundleFormHandler;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\taxonomy\Entity\Vocabulary;


/**
 * Form alter service for vocabulary forms.
 *
 * Adds relationship nodes configuration to taxonomy vocabulary forms.
 */
class VocabFormAlter {

  use StringTranslationTrait;

  protected BundleFormHandler $formHandler;
  protected BundleSettingsManager $settingsManager;


  /**
   * Constructs a VocabFormAlter object.
   *
   * @param BundleFormHandler $formHandler
   *   The form handler.
   * @param BundleSettingsManager $settingsManager
   *   The settings manager.
   */
  public function __construct(
    BundleFormHandler $formHandler,
    BundleSettingsManager $settingsManager
  ) {
      $this->formHandler = $formHandler;
      $this->settingsManager = $settingsManager;
  }
  
  
  /**
   * Alters vocabulary forms to add relationship nodes settings.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, $form_id) {
    $vocab = $this->formHandler->getFormEntity($form_state);
    if (!$vocab instanceof Vocabulary) {
      return;
    }

    $form['relationship_nodes'] = [
      '#type' => 'details',
      '#title' => $this->t('Relation type settings'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#tree' => TRUE,
      '#attributes' => ['class' => ['relationship-nodes-settings-form']],
    ];

    $form['relationship_nodes']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('This vocabulary is a relation type vocabulary'),
      '#default_value' => $this->settingsManager->getProperty($vocab, 'enabled'),
      '#description' => $this->t('If this is checked, this vocabulary will be validated as a relationship types list. It gets a mirror field that can contain the reverse relation type of the term.'),
      '#id' => 'relationship-nodes-enabled',
    ];
    $saved_referencing_type = $this->settingsManager->getProperty($vocab, 'referencing_type') ?? null;
    $form['relationship_nodes']['referencing_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Relation type'),
      '#default_value' => $saved_referencing_type ? $saved_referencing_type : 'none',
      '#options' => [
        'none' => $this->t('No mirroring field (relation type is undirectional)'),
        'entity_reference' => $this->t('The mirror field is a term reference field, referring to terms of the same list.'),
        'string' => $this->t('The mirror field is a string field.'),
      ],
      '#states' => [
        'visible' => [
          ':input[name="relationship_nodes[enabled]"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="relationship_nodes[enabled]"]' => ['checked' => TRUE],
        ],
      ],
      '#id' => 'relationship-nodes-referencing-type',
    ];

    $form['relationship_nodes']['confirm_mirror_change'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->getValue(['relationship_nodes', 'confirm_mirror_change']) ?? 0,
      '#attributes' => [
        'id' => 'relationship-nodes-confirm-mirror-change',
        'data-original' => $this->settingsManager->getProperty($vocab, 'referencing_type'),
      ],
    ];

    $form['#attached']['library'][] = 'relationship_nodes/mirror_change_confirm';

    $original_submit_callbacks = [];
    if (!empty($form['actions']['submit']['#submit'])) {
      $original_submit_callbacks = $form['actions']['submit']['#submit'];
      unset($form['actions']['submit']);
    }

    $form['actions']['confirm'] = [
      '#type' => 'button',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => [static::class, 'openConfirmationModal'],
        'event' => 'click',
        'progress' => ['type' => 'none'],
        'disable-refocus' => TRUE,
      ],
    ];

    $form['actions']['hidden_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Hidden Save'),
      '#attributes' => [
        'style' => 'display:none;',
        'id' => 'relationship-nodes-hidden-submit',
      ],
      '#submit' => array_merge($original_submit_callbacks, [[static::class, 'handleSubmission']]),
    ];

    $form['#validate'][] = [static::class, 'validateConflicts'];
  }


  /**
   * Validates relationship configuration conflicts.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   */
  public static function validateConflicts(array &$form, FormStateInterface $form_state) {
    \Drupal::service('relationship_nodes.validation_service')->displayFormStateValidationErrors($form, $form_state);
  }


  /**
   * Handles form submission.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   */
 public static function handleSubmission(array &$form, FormStateInterface $form_state) {
    \Drupal::service('relationship_nodes.bundle_form_handler')->handleSubmission($form, $form_state);
  }


  /**
   * Opens confirmation modal for mirror field changes.
   *
   * @param array $form
   *   The form array (passed by reference).
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return AjaxResponse
   *   The AJAX response.
   */
  public static function openConfirmationModal(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $new_value = $form_state->getValue(['relationship_nodes', 'referencing_type']);
    $original_value = $form['relationship_nodes']['confirm_mirror_change']['#attributes']['data-original'];

    if (empty($form_state->getValue(['relationship_nodes', 'enabled'])) || !$original_value || $new_value === $original_value) {
      $response->addCommand(new InvokeCommand(
        '#relationship-nodes-hidden-submit',
        'click',
        []
      ));
      return $response;
    }

    $dialog_content = [
      '#type' => 'container',
      'message' => [
        '#markup' => t('You changed the relation type. Existing fields may be deleted if incompatible with the input. Are you sure you want to save these relationship settings?'),
      ],
      'actions' => [
        '#type' => 'container',
        'save' => [
          '#type' => 'button',
          '#value' => t('Save'),
          '#attributes' => [
            'id' => 'relationship-nodes-modal-save',
            'class' => ['button', 'button--primary'],
          ],
        ],
        'cancel' => [
          '#type' => 'button',
          '#value' => t('Cancel'),
          '#attributes' => [
            'id' => 'relationship-nodes-modal-cancel',
            'class' => ['button'],
          ],
          '#limit_validation_errors' => [],
          '#executes_submit_callback' => FALSE,
        ],
      ],
    ];

    $response->addCommand(new OpenModalDialogCommand(
      t('Confirm save'),
      $dialog_content,
      ['width' => '400']
    ));

    return $response;
  }
}