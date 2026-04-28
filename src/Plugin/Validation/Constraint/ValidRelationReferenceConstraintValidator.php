<?php

namespace Drupal\relationship_nodes\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\relationship_nodes\RelationData\NodeHelper\RelationEntityValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;


/**
 * Validates the ValidRelationReferenceConstraint constraint.
 */
class ValidRelationReferenceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected RelationEntityValidator $relationEntityValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('relationship_nodes.relation_entity_validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    $error_type = $this->relationEntityValidator->checkRelationsValidity($entity);
    if (!empty($error_type)) {
      $this->context->addViolation($constraint->$error_type);
    }
  }
}
