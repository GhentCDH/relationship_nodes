<?php

namespace Drupal\relationship_nodes\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\relationship_nodes\Form\Admin\FieldUiManager;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;


/**
 * Controller for deleting relationship node fields.
 */
class FieldDeleteController extends ControllerBase {

  protected BundleSettingsManager $settingsManager;
  protected FieldUiManager $uiUpdater;


  /**
   * Constructs a FieldDeleteController object.
   *
   * @param BundleSettingsManager $settingsManager
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
   * Deletes a field configuration.
   *
   * @param FieldConfig $field_config
   *   The field configuration entity.
   *
   * @return RedirectResponse
   *   Redirect response to the field list.
   */
  public function delete(FieldConfig $field_config): RedirectResponse {
    $rn_field = (bool) $field_config->getThirdPartySetting('relationship_nodes', 'rn_created', FALSE);
    $bundle_info = $this->settingsManager->getBundleInfo($field_config->getTargetBundle()); 
    $relation_entity = $bundle_info && $bundle_info->isRelation();

    $redirect_url = $this->uiUpdater->getRedirectUrl($field_config);
    if ($relation_entity) {
      $this->messenger()->addError($this->t('This field cannot be deleted because it is managed by Relationship Nodes.'));
    } elseif ($rn_field) {
      $field_config->delete();
      $this->messenger()->addStatus($this->t('RN-managed field deleted.'));
    }
    
    return new RedirectResponse($redirect_url->toString());
  }
}