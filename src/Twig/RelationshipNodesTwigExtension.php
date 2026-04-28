<?php

namespace Drupal\relationship_nodes\Twig;

use Drupal\Core\Render\RendererInterface;
use Drupal\relationship_nodes\Display\RelationshipTwigFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for relationship nodes rendering.
 */
class RelationshipNodesTwigExtension extends AbstractExtension {

  protected RelationshipTwigFormatter $formatter;
  protected RendererInterface $renderer;

  /**
   * Constructs a RelationshipNodesTwigExtension object.
   *
   * @param RelationshipTwigFormatter $formatter
   *   The formatter service that resolves and formats relationship data.
   * @param RendererInterface $renderer
   *   The Drupal renderer, used to bubble cache metadata from render arrays.
   */
  public function __construct(RelationshipTwigFormatter $formatter, RendererInterface $renderer) {
    $this->formatter = $formatter;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('rn', [$this, 'rn']),
    ];
  }

  /**
   * Twig function for relationship nodes operations.
   *
   * @param string $operation
   *   The operation to perform:
   *   - 'relation_fields_list': Get all relation field names
   *   - 'formatted_relations': Get formatted relationship data
   * @param mixed ...$args
   *   Additional arguments for the operation.
   *
   * @return mixed
   *   The result of the operation.
   */
  public function rn(string $operation, ...$args) {
    $result = match($operation) {
      'relation_fields_list' => $this->formatter->getAllRelationFields(...$args),
      'formatted_relations' => $this->formatter->getFormattedRelationships(...$args),
      default => NULL,
    };
    if (is_array($result) && isset($result['_cache'])) {
      $build = [];
      $result['_cache']->applyTo($build);
      $this->renderer->render($build);
      unset($result['_cache']);
    }

    return $result;
}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'relationship_nodes.twig_extension';
  }
}