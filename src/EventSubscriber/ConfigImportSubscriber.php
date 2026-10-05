<?php

namespace Drupal\relationship_nodes\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\relationship_nodes\RelationField\RelationshipFieldManager;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationBundle\Settings\SettingsCleanupService;
use Drupal\relationship_nodes\Validation\ConfigImportValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for configuration import events related to relationship nodes.
 *
 * Validates and processes relationship node configuration during config import,
 * including cleanup when the module is disabled.
 */
class ConfigImportSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The settings cleanup service.
   */
  protected SettingsCleanupService $cleanupService;

  /**
   * The bundle settings manager.
   */
  protected BundleSettingsManager $settingsManager;

  /**
   * The config import validator.
   */
  protected ConfigImportValidator $cimValidationService;

  /**
   * The relationship field manager.
   */
  protected RelationshipFieldManager $relationFieldManager;

  /**
   * Constructs a ConfigImportSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\relationship_nodes\RelationBundle\Settings\SettingsCleanupService $cleanupService
   *   The cleanup service.
   * @param \Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager $settingsManager
   *   The settings manager.
   * @param \Drupal\relationship_nodes\Validation\ConfigImportValidator $cimValidationService
   *   The validation service.
   * @param \Drupal\relationship_nodes\RelationField\RelationshipFieldManager $relationFieldManager
   *   The field configurator.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    SettingsCleanupService $cleanupService,
    BundleSettingsManager $settingsManager,
    ConfigImportValidator $cimValidationService,
    RelationshipFieldManager $relationFieldManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->cleanupService = $cleanupService;
    $this->settingsManager = $settingsManager;
    $this->cimValidationService = $cimValidationService;
    $this->relationFieldManager = $relationFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::IMPORT_VALIDATE => 'onConfigImportValidate',
      ConfigEvents::IMPORT => 'onConfigImport',
    ];
  }

  /**
   * Validates configuration before import.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The config import event.
   */
  public function onConfigImportValidate(ConfigImporterEvent $event): void {
    $storage_comparer = $event->getConfigImporter()->getStorageComparer();

    if ($this->getModuleStateChange($storage_comparer) === 'disabling') {
      return;
    }
    $source_storage = $storage_comparer->getSourceStorage();

    foreach ($this->getUpdatedBundleConfigsToValidate($storage_comparer) as $bundle_config_name) {
      // Validate all relation node bundles and their linked fields.
      $this->cimValidationService->displayBundleCimValidationErrors($bundle_config_name, $event, $source_storage);
    }
    foreach ($this->getDeletedFieldsToValidate($storage_comparer) as $field_config_name) {
      // Prevent deletion of fields used by the module.
      $this->cimValidationService->displayCimFieldDependenciesValidationErrors($field_config_name, $event, $source_storage);
    }
  }

  /**
   * Processes configuration after import.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The config import event.
   */
  public function onConfigImport(ConfigImporterEvent $event): void {
    $storage_comparer = $event->getConfigImporter()->getStorageComparer();

    if ($this->getModuleStateChange($storage_comparer) === 'disabling') {
      // The imported configuration already has the module's settings removed;
      // only clean up data that is not configuration.
      $this->cleanupService->removeModuleSettings(FALSE);
      return;
    }

    foreach ($this->fromConfigToEntities($this->getUpdatedRelationBundleConfigs($storage_comparer)) as $entity) {
      $this->relationFieldManager->implementFieldUpdates($entity);
    }
  }

  /**
   * Determines if the module is being enabled or disabled.
   *
   * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
   *   The storage comparer.
   *
   * @return string|null
   *   Returns 'enabling', 'disabling', or NULL.
   */
  protected function getModuleStateChange(StorageComparerInterface $storage_comparer): ?string {
    $source_storage = $storage_comparer->getSourceStorage();
    $target_storage = $storage_comparer->getTargetStorage();
    $source_extensions = $source_storage->read('core.extension');
    $target_extensions = $target_storage->read('core.extension');

    // The source storage holds the configuration being imported, the target
    // storage the active configuration.
    if (!isset($source_extensions['module']['relationship_nodes']) && isset($target_extensions['module']['relationship_nodes'])) {
      return 'disabling';
    }
    if (isset($source_extensions['module']['relationship_nodes']) && !isset($target_extensions['module']['relationship_nodes'])) {
      return 'enabling';
    }
    return NULL;
  }

  /**
   * Gets bundle configurations that need validation.
   *
   * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
   *   The storage comparer.
   *
   * @return array
   *   Array of configuration names.
   */
  protected function getUpdatedBundleConfigsToValidate(StorageComparerInterface $storage_comparer): array {
    $result = [];
    $source = $storage_comparer->getSourceStorage();
    $bundle_prefixes = ['node' => 'node.type.', 'taxonomy_term' => 'taxonomy.vocabulary.'];
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      foreach (['create', 'update'] as $op) {
        foreach ($storage_comparer->getChangelist($op, $collection) ?? [] as $config_name) {
          if (str_starts_with($config_name, 'taxonomy.vocabulary.') || str_starts_with($config_name, 'node.type.')) {
            $result[] = $config_name;
            continue;
          }
          // A changed field is validated with its bundle; a changed field
          // storage with every bundle that has the field.
          $parts = explode('.', $config_name);
          if (str_starts_with($config_name, 'field.field.') && count($parts) === 5 && isset($bundle_prefixes[$parts[2]])) {
            $result[] = $bundle_prefixes[$parts[2]] . $parts[3];
          }
          elseif (str_starts_with($config_name, 'field.storage.') && count($parts) === 4 && isset($bundle_prefixes[$parts[2]])) {
            foreach ($source->listAll('field.field.' . $parts[2] . '.') as $field_config_name) {
              $field_parts = explode('.', $field_config_name);
              if (($field_parts[4] ?? NULL) === $parts[3]) {
                $result[] = $bundle_prefixes[$parts[2]] . $field_parts[3];
              }
            }
          }
        }
      }
    }
    // Only bundles that exist in the imported configuration.
    return array_values(array_filter(array_unique($result), fn($name) => $source->exists($name)));
  }

  /**
   * Gets updated relation bundle configurations.
   *
   * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
   *   The storage comparer.
   *
   * @return array
   *   Array of configuration data keyed by configuration name.
   */
  protected function getUpdatedRelationBundleConfigs(StorageComparerInterface $storage_comparer): array {
    $result = [];
    $all_updated_bundles = $this->getUpdatedBundleConfigsToValidate($storage_comparer);
    $source_storage = $storage_comparer->getSourceStorage();
    foreach ($all_updated_bundles as $bundle_config_name) {
      $config_data = $source_storage->read($bundle_config_name);
      if ($config_data && $this->settingsManager->isCimRelationEntity($config_data)) {
        $result[$bundle_config_name] = $config_data;
      }
    }
    return $result;
  }

  /**
   * Converts configuration data to loaded entities.
   *
   * @param array $config_list
   *   Array of configuration data keyed by configuration name.
   *
   * @return array
   *   Array of loaded entities.
   */
  protected function fromConfigToEntities(array $config_list): array {
    $load = ['node_type' => [], 'taxonomy_vocabulary' => []];
    $result = [];
    foreach ($config_list as $config_name => $config_data) {
      $class_names = $this->settingsManager->getConfigFileEntityClasses($config_name);
      $entity_type = $class_names['entity_type_id'];
      if (isset($load[$entity_type])) {
        $load[$entity_type][] = $class_names['bundle'];
      }
    }
    foreach ($load as $entity_type => $entities) {
      if (empty($entities)) {
        continue;
      }
      $entity_list = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($entities);
      $result = array_merge($result, $entity_list);
    }
    return $result;
  }

  /**
   * Gets field configurations that are being deleted and need validation.
   *
   * @param \Drupal\Core\Config\StorageComparerInterface $storage_comparer
   *   The storage comparer.
   *
   * @return array
   *   Array of field configuration names.
   */
  protected function getDeletedFieldsToValidate(StorageComparerInterface $storage_comparer): array {
    $result = [];
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      $change_list = $storage_comparer->getChangelist('delete', $collection) ?? [];
      foreach ($change_list as $config_name) {
        if (
          str_starts_with($config_name, 'field.storage.taxonomy_term.') ||
          str_starts_with($config_name, 'field.storage.node.') ||
          str_starts_with($config_name, 'field.field.taxonomy_term') ||
          str_starts_with($config_name, 'field.field.node.')
        ) {
          $result[] = $config_name;
        }
      }
    }
    return $result;
  }

}
