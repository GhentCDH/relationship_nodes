<?php

namespace Drupal\relationship_nodes\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\relationship_nodes\RelationData\TermHelper\MirrorProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays an entity reference field as the mirror label of the referenced term.
 *
 * Falls back to the plain term label if no mirror label is configured.
 *
 * @FieldFormatter(
 *   id = "relation_type_mirror_label",
 *   label = @Translation("Mirror label (relation type only)"),
 *   description = @Translation("Only for use on the relation type field of typed relation bundles."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class MirrorLabelFormatter extends EntityReferenceLabelFormatter implements ContainerFactoryPluginInterface {

  protected MirrorProvider $mirrorProvider;
  protected LanguageManagerInterface $languageManager;


  /**
   * Constructs a MirrorLabelFormatter object.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    MirrorProvider $mirror_provider,
    LanguageManagerInterface $language_manager
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->mirrorProvider = $mirror_provider;
    $this->languageManager = $language_manager;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('relationship_nodes.mirror_provider'),
      $container->get('language_manager')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $current_langcode = $this->languageManager->getCurrentLanguage()->getId();

    foreach ($items as $delta => $item) {
      $term_id = (string) ($item->target_id ?? '');
      if (empty($term_id)) {
        continue;
      }

      $label = $this->mirrorProvider->getMirrorLabelFromId($term_id, $current_langcode);

      // Fallback to plain term label if no mirror configured.
      if ($label === NULL) {
        $entity = $item->entity;
        if ($entity) {
          if ($entity->hasTranslation($current_langcode)) {
            $entity = $entity->getTranslation($current_langcode);
          }
          $label = $entity->label();
        }
      }

      $elements[$delta] = [
        '#plain_text' => $label ?? '',
      ];
    }

    return $elements;
  }

}