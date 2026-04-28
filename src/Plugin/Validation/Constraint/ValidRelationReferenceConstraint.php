<?php

namespace Drupal\relationship_nodes\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validates that a relation node references two distinct, non-self entities.
 *
 * @Constraint(
 *   id = "valid_relation_reference_constraint",
 *   label = @Translation("Valid Related Entities", context = "Validation"),
 * )
 */
class ValidRelationReferenceConstraint extends Constraint {
  public $incomplete = 'A relation cannot have empty related item fields.';
  public $selfReferring = 'An item cannot have a relation with itself.';
}

