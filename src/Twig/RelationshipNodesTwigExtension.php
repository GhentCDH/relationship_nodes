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

  /**
   * The relationship twig formatter.
   */
  protected RelationshipTwigFormatter $formatter;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * Constructs a RelationshipNodesTwigExtension object.
   *
   * @param \Drupal\relationship_nodes\Display\RelationshipTwigFormatter $formatter
   *   The formatter service that resolves and formats relationship data.
   * @param \Drupal\Core\Render\RendererInterface $renderer
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
   *   - 'formatted_relations': Get formatted relationship data.
   * @param mixed ...$args
   *   Additional arguments for the operation.
   *
   * @return mixed
   *   The result of the operation.
   */
  public function rn(string $operation, ...$args) {
    if ($operation === 'formatted_relations') {
      $built = $this->formatter->buildFormattedRelationships(...$args);
      // Bubble the cacheability also for empty results, so that they are
      // invalidated when a relation is added or becomes visible.
      $build = [];
      $built['cache']->applyTo($build);
      $this->renderer->render($build);
      $result = $built['result'];
      unset($result['_cache']);
      return $result;
    }

    return match($operation) {
      'relation_fields_list' => $this->formatter->getAllRelationFields(...$args),
      default => NULL,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'relationship_nodes.twig_extension';
  }

}
