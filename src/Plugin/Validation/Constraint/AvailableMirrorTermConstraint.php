<?php

namespace Drupal\relationship_nodes\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Validates that a mirror term is not already used by another relation type.
 */
#[ConstraintAttribute(
  id: 'available_mirror_term_constraint',
  label: new TranslatableMarkup('Term Mirror Validation', options: ['context' => 'Validation']),
)]
class AvailableMirrorTermConstraint extends Constraint {

  /**
   * The message when the mirror term is already mirrored by another term.
   *
   * @var string
   */
  public $termAlreadyMirrored = 'The selected mirror term is already linked to another relationship type. Please choose a different mirror term or remove the existing link before proceeding.';

  /**
   * The message when a term is its own mirror.
   *
   * @var string
   */
  public $noSelfMirroring = 'A relationship type cannot mirror itself. For one-way (unidirectional) relationships, leave the mirror field blank. For directional relationships, select a different term for the reverse relationship or remove the existing link first.';

}
