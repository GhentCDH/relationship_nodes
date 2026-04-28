<?php

namespace Drupal\relationship_nodes\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex;
use Drupal\relationship_nodes\Form\Entity\RelationEntityFormHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Relation extended IEF widget.
 *
 * Opinionated IEF complex widget:
 * - No confirmation dialog on remove.
 * - Removed relations are always deleted.
 * - Duplication is disabled.
 * - Cleaner UX labels.
 *
 * @FieldWidget(
 *   id = "relation_extended_ief_complex_widget",
 *   label = @Translation("Relation extended IEF complex widget"),
 *   field_types = {"entity_reference"},
 *   multiple_values = true
 * )
 */
class RelationIefWidget extends InlineEntityFormComplex {

	protected RelationEntityFormHandler $relationFormHandler;


	/**
	 * {@inheritdoc}
	 */
	public function __construct(
		$plugin_id,
		$plugin_definition,
		FieldDefinitionInterface $field_definition,
		array $settings,
		array $third_party_settings,
		EntityTypeBundleInfoInterface $entity_type_bundle_info,
		EntityTypeManagerInterface $entity_type_manager,
		EntityDisplayRepositoryInterface $entity_display_repository,
		ModuleHandlerInterface $module_handler,
		SelectionPluginManagerInterface $selection_manager,
		RelationEntityFormHandler $relationFormHandler
	) {
		parent::__construct(
			$plugin_id,
			$plugin_definition,
			$field_definition,
			$settings,
			$third_party_settings,
			$entity_type_bundle_info,
			$entity_type_manager,
			$entity_display_repository,
			$module_handler,
			$selection_manager
		);
		$this->relationFormHandler = $relationFormHandler;
	}


	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
		return new static(
			$plugin_id,
			$plugin_definition,
			$configuration['field_definition'],
			$configuration['settings'],
			$configuration['third_party_settings'],
			$container->get('entity_type.bundle.info'),
			$container->get('entity_type.manager'),
			$container->get('entity_display.repository'),
			$container->get('module_handler'),
			$container->get('plugin.manager.entity_reference_selection'),
			$container->get('relationship_nodes.relation_entity_form_handler')
		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
		$element = parent::formElement($items, $delta, $element, $form, $form_state);

		$element['#relation_extended_widget'] = TRUE;
		$ief_id = $this->getIefId();

		// Set flag in widget state so RelationFormHelper can detect these widgets
		// during form build (before extractFormValues runs).
		$form_state->set(['inline_entity_form', $ief_id, 'relation_extended_widget'], TRUE);

		if (!empty($element['entities'])) {
			foreach ($element['entities'] as $key => &$entity_row) {
				if (!is_numeric($key)) {
					continue;
				}

				$widget_state = $form_state->get(['inline_entity_form', $ief_id]) ?? [];
				$has_form = !empty($widget_state['entities'][$key]['form']);

				if ($has_form) {
					if ($widget_state['entities'][$key]['form'] === 'edit'
						&& !empty($entity_row['form']['inline_entity_form'])) {
						$entity_row['form']['inline_entity_form']['#relation_extended_widget'] = TRUE;
					}
					continue;
				}

				// Replace Remove button — no confirmation dialog.
				if (isset($entity_row['actions']['ief_entity_remove'])) {
					$entity_row['actions']['ief_entity_remove']['#submit'] = [
						[static::class, 'submitRemoveDirectly'],
					];
					$entity_row['actions']['ief_entity_remove']['#ief_id'] = $ief_id;
					$entity_row['actions']['ief_entity_remove']['#ief_row_delta'] = $key;
					$entity_row['actions']['ief_entity_remove']['#limit_validation_errors'] = [];
				}
			}
		}

		if (!empty($element['form']['inline_entity_form'])) {
			$element['form']['inline_entity_form']['#relation_extended_widget'] = TRUE;
			$element['form']['inline_entity_form']['#after_build'][] = [
				static::class, 'customizeButtonLabels',
			];
		}

		return $element;
	}


	/**
	 * {@inheritdoc}
	 */
	public static function defaultSettings() {
		$defaults = parent::defaultSettings();
		$defaults['removed_reference'] = self::REMOVED_DELETE;
		$defaults['allow_duplicate'] = FALSE;
		return $defaults;
	}


	/**
	 * {@inheritdoc}
	 */
	public function settingsForm(array $form, FormStateInterface $form_state) {
		$element = parent::settingsForm($form, $form_state);
    $unset_els = ['removed_reference', 'allow_existing', 'match_operator', 'allow_duplicate'];
    foreach($unset_els as $unset_el){
      unset($element[$unset_el]);
    }
		return $element;
	}


	/**
	 * {@inheritdoc}
	 */
	public function settingsSummary() {
		$summary = [];
		$labels = $this->getEntityTypeLabels();

		$form_modes = $this->entityDisplayRepository
			->getFormModeOptions($this->getFieldSetting('target_type'));
		$form_mode = $this->getSetting('form_mode');
		$summary[] = $this->t('Form mode: @mode', [
			'@mode' => $form_modes[$form_mode] ?? $form_mode,
		]);

		$summary[] = $this->getSetting('allow_new')
			? $this->t('New @label can be added.', ['@label' => $labels['plural']])
			: $this->t('New @label can not be created.', ['@label' => $labels['plural']]);

		$summary[] = $this->t('Removed @label are always deleted.', [
			'@label' => $labels['plural'],
		]);

		return $summary;
	}


	/**
	 * After build callback: customize button labels for relation forms.
	 */
	public static function customizeButtonLabels(array $element, FormStateInterface $form_state): array {
		if (($element['#op'] ?? NULL) !== 'add') {
			return $element;
		}

		if (isset($element['actions']['ief_add_save'])) {
			$labels = $element['#ief_labels'] ?? ['singular' => 'item'];
			$element['actions']['ief_add_save']['#value'] = t(
				'Add @type (saved with parent)',
				['@type' => $labels['singular']]
			);
		}

		return $element;
	}


	/**
	 * Submit handler: remove entity directly without confirmation.
	 *
	 * Follows the same pattern as InlineEntityFormComplex::submitConfirmRemove(),
	 * but skips the confirmation form.
	 */
	public static function submitRemoveDirectly(array $form, FormStateInterface $form_state): void {
		$trigger = $form_state->getTriggeringElement();
		$ief_id = $trigger['#ief_id'];
		$delta = $trigger['#ief_row_delta'];

		$widget_state = $form_state->get(['inline_entity_form', $ief_id]) ?? [];

		if (!isset($widget_state['entities'][$delta])) {
			return;
		}

		$entity = $widget_state['entities'][$delta]['entity'];
		$entity_id = $entity->id();

		unset($widget_state['entities'][$delta]);

		if ($entity_id) {
			$widget_state['delete'][] = $entity;
		}

		$form_state->set(['inline_entity_form', $ief_id], $widget_state);
		$form_state->setRebuild();
	}
}