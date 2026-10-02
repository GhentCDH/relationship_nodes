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
      // Is another term of the vocabulary already mirrored by this term's
      // mirror? Queried instead of loading the whole vocabulary.
      $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', $value->getParent()->getEntity()->bundle())
        ->condition($this->fieldNameResolver->getMirrorFields('entity_reference'), $updated_term_mirror_id);
      if ($updated_term_id) {
        $query->condition('tid', $updated_term_id, '<>');
      }
      if ($query->range(0, 1)->count()->execute()) {
        $this->context->addViolation($constraint->termAlreadyMirrored);
      }
    }
  }
}
