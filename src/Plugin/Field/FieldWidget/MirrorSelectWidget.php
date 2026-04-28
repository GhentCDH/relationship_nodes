<?php

namespace Drupal\relationship_nodes\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\relationship_nodes\RelationData\TermHelper\MirrorProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Plugin implementation of the 'mirror_select_widget' widget.
 *
 * Provides a select widget for mirror term fields with filtered options.
 *
 * @FieldWidget(
 *   id = "mirror_select_widget",
 *   label = @Translation("Mirror Select Widget"),
 *   field_types = {
 *     "entity_reference"
 *   },
 *   multiple_values = TRUE
 * )
 */
class MirrorSelectWidget extends OptionsSelectWidget {
  
  protected MirrorProvider $mirrorProvider;


  /**
   * Constructs a MirrorSelectWidget object.
   *
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Third party settings.
   * @param MirrorProvider $mirrorProvider
   *   The mirror term provider.
   * @param ElementInfoManagerInterface|null $elementInfoManager
   *   The element info manager.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition, 
    FieldDefinitionInterface $field_definition, 
    array $settings, 
    array $third_party_settings,
    MirrorProvider $mirrorProvider,
    ?ElementInfoManagerInterface $elementInfoManager = NULL
  ) {
    parent::__construct(
      $plugin_id, 
      $plugin_definition, 
      $field_definition, 
      $settings, 
      $third_party_settings, 
      $elementInfoManager
    );
    $this->mirrorProvider = $mirrorProvider;
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
      $container->get('relationship_nodes.mirror_provider'),
      $container->get('plugin.manager.element_info')
    );
  }
    
  
  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    if (!$this->mirrorProvider->mirroringRequired($items, $form, $form_state)) {
      return $element;
    }

    $element['#options'] = $this->mirrorProvider->getMirrorOptions($element['#options']);
     
    return $element;
  }  
}