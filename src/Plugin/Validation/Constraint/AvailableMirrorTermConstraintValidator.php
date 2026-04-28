<?php

namespace Drupal\relationship_nodes\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\relationship_nodes\RelationField\FieldNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;


/**
 * Validates the AvailableMirrorTermConstraint constraint.
 */
class AvailableMirrorTermConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FieldNameResolver $fieldNameResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('relationship_nodes.field_name_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint): void {
    if ($value->target_id != null) {
      $updated_term_id = $value->getParent()->getEntity()->id();
      $updated_term_mirror_id = $value->target_id;
      if ($updated_term_id == $updated_term_mirror_id) {
        $this->context->addViolation($constraint->noSelfMirroring);
      }
      $all_existing_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($value->getParent()->getEntity()->bundle(), 0, NULL, TRUE);
      $mirror_reference_field = $this->fieldNameResolver->getMirrorFields('entity_reference');
      foreach ($all_existing_terms as $loop_term) {
        $loop_term_id = $loop_term->id();
        $loop_term_mirror_id = $loop_term->$mirror_reference_field->target_id;
        if ($updated_term_id != $loop_term_id && $updated_term_mirror_id == $loop_term_mirror_id) {
          $this->context->addViolation($constraint->termAlreadyMirrored);
        }
      }
    }
  }
}
