<?php

namespace Drupal\relationship_nodes\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Validates that a relation node references two distinct, non-self entities.
 */
#[ConstraintAttribute(
  id: 'valid_relation_reference_constraint',
  label: new TranslatableMarkup('Valid Related Entities', options: ['context' => 'Validation']),
)]
class ValidRelationReferenceConstraint extends Constraint {
  public $incomplete = 'A relation cannot have empty related item fields.';
  public $selfReferring = 'An item cannot have a relation with itself.';

}
