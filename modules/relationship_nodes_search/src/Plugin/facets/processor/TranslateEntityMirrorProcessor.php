<?php

namespace Drupal\relationship_nodes_search\Plugin\facets\processor;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\BuildProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;
use Drupal\relationship_nodes\RelationBundle\Settings\BundleSettingsManager;
use Drupal\relationship_nodes\RelationData\TermHelper\MirrorProvider;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Transforms entity IDs to their mirror label.
 *
 * Works like the built-in "Transform entity ID to label" processor, but
 * resolves the mirror label of the taxonomy term instead of the plain label.
 * Falls back to the plain term label if no mirror label is configured.
 *
 * Only applies to facets based on a typed relation type field, verified via
 * FieldNameResolver and BundleSettingsManager.
 *
 * @FacetsProcessor(
 *   id = "translate_entity_mirror_label",
 *   label = @Translation("Transform entity ID to mirror label"),
 *   description = @Translation("Displays the mirror label of the relation type term instead of its ID. Falls back to the term label if no mirror label is configured. If also 'Transform entity ID to label' is enabled, this mirror processor overrules the original one."),
 *   stages = {
 *     "build" = 25
 *   }
 * )
 */
class TranslateEntityMirrorProcessor extends ProcessorPluginBase implements BuildProcessorInterface, ContainerFactoryPluginInterface {

  protected MirrorProvider $mirrorProvider;
  protected LanguageManagerInterface $languageManager;
  protected FieldNameResolver $fieldNameResolver;
  protected BundleSettingsManager $bundleSettingsManager;


  /**
   * Constructs a TranslateEntityMirrorProcessor object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MirrorProvider $mirror_provider,
    LanguageManagerInterface $language_manager,
    FieldNameResolver $field_name_resolver,
    BundleSettingsManager $bundle_settings_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->mirrorProvider = $mirror_provider;
    $this->languageManager = $language_manager;
    $this->fieldNameResolver = $field_name_resolver;
    $this->bundleSettingsManager = $bundle_settings_manager;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id,$plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('relationship_nodes.mirror_provider'),
      $container->get('language_manager'),
      $container->get('relationship_nodes.field_name_resolver'),
      $container->get('relationship_nodes.bundle_settings_manager'),
    );
  }


  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet, array $results): array {
    if (!$this->isRelationTypeFacet($facet)) {
      return $results;
    }

    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    foreach ($results as $key => $result) {
      $term_id = (string) $result->getRawValue();

      if (!is_numeric($term_id)) {
        continue;
      }

      $mirror_label = $this->mirrorProvider->getMirrorLabelFromId($term_id, $langcode);

      if ($mirror_label !== NULL) {
        $result->setDisplayValue($mirror_label);
      }
    }
    return $results;
  }


  /**
   * Checks if the facet is based on a typed relation type field.
   *
   * Verifies via the Search API index field's property path and the bundle's
   * typed relation configuration — without relying on hardcoded field names.
   *
   * @param FacetInterface $facet
   *   The facet to check.
   *
   * @return bool
   *   TRUE if this is a typed relation type facet, FALSE otherwise.
   */
  protected function isRelationTypeFacet(FacetInterface $facet): bool {
    $source = $facet->getFacetSource();
    if (!$source || !method_exists($source, 'getIndex')) {
      return FALSE;
    }

    $field = $source->getIndex()->getField($facet->getFieldIdentifier());
    if (!$field) {
      return FALSE;
    }

    if ($field->getPropertyPath() !== $this->fieldNameResolver->getRelationTypeField()) {
      return FALSE;
    }

    if ($field->getDatasourceId() !== 'entity:node') {
      return FALSE;
    }

    $bundle = $field->getDataDefinition()
      ?->getFieldDefinition()
      ?->getTargetBundle();

    if (!$bundle) {
      return FALSE;
    }

    $bundle_info = $this->bundleSettingsManager->getBundleInfo($bundle);
    return $bundle_info !== NULL && $bundle_info->isTypedRelation();
  }
}