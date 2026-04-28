<?php

namespace Drupal\relationship_nodes\RelationBundle\Settings;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\relationship_nodes\RelationBundle\BundleInfoService;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;


/**
 * Service for cleaning up relationship nodes settings.
 *
 * Removes module settings and cleans up form displays when module is uninstalled.
 */
class SettingsCleanupService {
      
  protected EntityTypeManagerInterface $entityTypeManager;
  protected BundleInfoService $bundleInfoService;
  protected RelationshipFieldManager $relationFieldManager;
  protected KeyValueFactoryInterface $keyValueFactory;


  /**
   * Constructs a SettingsCleanupService object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param BundleInfoService $bundleInfoService
   *   The bundle info service.
   * @param RelationshipFieldManager $relationFieldManager
   *   The field configurator.
   * @param KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    BundleInfoService $bundleInfoService, 
    RelationshipFieldManager $relationFieldManager,
      KeyValueFactoryInterface $keyValueFactory
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->bundleInfoService = $bundleInfoService;
    $this->relationFieldManager = $relationFieldManager;
    $this->keyValueFactory = $keyValueFactory;
  }


  /**
   * Removes all module settings.
   */
  public function removeModuleSettings(): void {
    $this->unsetRnEntitySettings();
    $this->cleanFormDisplays();
    $this->cleanRelationWeights();
  }


  /**
   * Unsets relationship nodes settings from entities.
   */
  protected function unsetRnEntitySettings(): void {
    try {
      foreach ($this->bundleInfoService->getAllRelationBundles() as $entity_type) {
        $this->unsetRnThirdPartySettings($entity_type);
      }
      foreach ($this->relationFieldManager->getAllRnCreatedFields() as $field) {
        $this->unsetRnThirdPartySettings($field);
      }
      \Drupal::cache()->deleteAll();
    } 
    catch (\Exception $e) {
      \Drupal::logger('relationship_nodes')->error('Error cleaning up Relationship Nodes data: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }


  /**
   * Cleans form and view displays of relationship node widgets.
   */
  protected function cleanFormDisplays(): void {
    $display_storages = [
      'form' => $this->entityTypeManager->getStorage('entity_form_display'), 
      'view' => $this->entityTypeManager->getStorage('entity_view_display')
    ];

    foreach ($display_storages as $mode => $storage) {
      $displays = $storage->loadMultiple();

      foreach ($displays as $display) {
        $changed = FALSE;
        $content = $display->get('content');

        foreach ($content as $field_name => $settings) {
          if (str_starts_with($field_name, 'computed_relationshipfield__')) {
            unset($content[$field_name]);
            $changed = TRUE;
          } elseif (!empty($settings['type']) && $settings['type'] === 'relation_extended_ief_widget') {
            $content[$field_name]['type'] = 'entity_reference_autocomplete';
            $content[$field_name]['settings'] = [
              'match_operator' => 'CONTAINS',
              'size' => 60,
              'placeholder' => '',
            ];
            $changed = TRUE;
          }
        }
        if ($changed) {
          $display->set('content', $content);
          $display->save();
        }
      }
    }
  }


  /**
   * Unsets third-party settings for a config entity.
   *
   * @param ConfigEntityBase $entity
   *   The config entity.
   */
  protected function unsetRnThirdPartySettings(ConfigEntityBase $entity): void {
    $rn_settings = $entity->getThirdPartySettings('relationship_nodes');
    foreach ($rn_settings as $key => $value) {
      $entity->unsetThirdPartySetting('relationship_nodes', $key);
    }
    if (method_exists($entity, 'setLocked')) {
      $entity->setLocked(FALSE);
    }
    $entity->save();
  }


  /**
   * Cleans up all relation weight data.
   */
  protected function cleanRelationWeights(): void {
    try {
      $this->keyValueFactory->get('relationship_nodes_weights')->deleteAll();
      \Drupal::logger('relationship_nodes')->info('Cleaned up all relation weights.');
    } 
    catch (\Exception $e) {
      \Drupal::logger('relationship_nodes')->error('Error cleaning up relation weights: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }
}  

