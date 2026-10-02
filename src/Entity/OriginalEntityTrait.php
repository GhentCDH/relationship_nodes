<?php

namespace Drupal\relationship_nodes\Entity;

use Drupal\Core\Entity\EntityInterface;

/**
 * Reads the unchanged entity during an update.
 *
 * Drupal 11.2 added EntityInterface::getOriginal() and deprecated the
 * dynamic 'original' property; earlier versions only have the property.
 */
trait OriginalEntityTrait {

  /**
   * Returns the unchanged entity of an update, if available.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The original entity, or NULL.
   */
  protected function getOriginalEntity(EntityInterface $entity): ?EntityInterface {
    if (method_exists($entity, 'getOriginal')) {
      return $entity->getOriginal();
    }
    return $entity->original ?? NULL;
  }

}
