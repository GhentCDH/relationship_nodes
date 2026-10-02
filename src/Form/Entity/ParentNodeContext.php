<?php

namespace Drupal\relationship_nodes\Form\Entity;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * Keeps track of the node whose form contains the relation widget.
 *
 * Relations are edited inline in the form of the node they belong to. That
 * node was taken from the {node} route parameter, which is wrong when the
 * node form is shown on another route, e.g. in a modal or embedded in
 * another page. The relation widget registers the node of its form here, and
 * the route parameter is only used as a fallback.
 */
class ParentNodeContext {

  /**
   * The node of the form with the relation widget, if registered.
   */
  protected ?NodeInterface $parentNode = NULL;

  /**
   * Constructs a ParentNodeContext object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(protected RouteMatchInterface $routeMatch) {}

  /**
   * Registers the node of a form, if the form is a form of a saved node.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the node form.
   */
  public function setFromFormState(FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $entity = $form_object->getEntity();
    if ($entity instanceof NodeInterface && !$entity->isNew()) {
      $this->parentNode = $entity;
    }
  }

  /**
   * Returns the saved node whose relations are being edited.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node, or NULL for a new node or outside a node form.
   */
  public function getParentNode(): ?NodeInterface {
    if ($this->parentNode) {
      return $this->parentNode;
    }
    $node = $this->routeMatch->getParameter('node');
    return $node instanceof NodeInterface ? $node : NULL;
  }

}
