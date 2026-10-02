<?php

namespace Drupal\relationship_nodes\Hook;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\relationship_nodes\Form\Admin\LockedFieldListBuilder;
use Drupal\relationship_nodes\Validation\ValidationResultFormatter;
use Drupal\relationship_nodes\Validation\ValidationService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hook implementations for Drupal 11.2 and later.
 *
 * Drupal 10 uses the procedural implementations in relationship_nodes.module
 * and relationship_nodes.install, which call the same methods. Those are
 * marked as legacy, so Drupal 11.2+ only runs the implementations here.
 */
class RelationshipNodesHooks implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a RelationshipNodesHooks object.
   *
   * Drupal 11 autowires hook classes (hence the Autowire attributes);
   * Drupal 10 instantiates this class through create().
   *
   * @param \Drupal\relationship_nodes\Validation\ValidationService $validationService
   *   The validation service.
   * @param \Drupal\relationship_nodes\Validation\ValidationResultFormatter $validationResultFormatter
   *   The validation result formatter.
   */
  public function __construct(
    #[Autowire(service: 'relationship_nodes.validation_service')]
    protected ValidationService $validationService,
    #[Autowire(service: 'relationship_nodes.validation_result_formatter')]
    protected ValidationResultFormatter $validationResultFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('relationship_nodes.validation_service'),
      $container->get('relationship_nodes.validation_result_formatter'),
    );
  }

  /**
   * Implements hook_entity_type_alter().
   *
   * Runs last, so the field list builder is not replaced by other modules.
   */
  #[Hook('entity_type_alter', order: Order::Last)]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['node'])) {
      $entity_types['node']->addConstraint('valid_relation_reference_constraint');
    }
    if (isset($entity_types['field_config'])) {
      $entity_types['field_config']->setListBuilderClass(LockedFieldListBuilder::class);
    }
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return $this->buildRequirements();
  }

  /**
   * Builds the status report entry for the relation configuration.
   *
   * @return array
   *   The requirements, keyed by requirement ID.
   */
  public function buildRequirements(): array {
    $title = $this->t('Relationship Nodes Configuration');
    try {
      $validation_result = $this->validationService->validateAllRelationConfig();
      if ($validation_result->isValid()) {
        return [
          'relationship_nodes_config' => [
            'title' => $title,
            'value' => $this->t('Configuration validated successfully'),
            'severity' => $this->severity('ok'),
          ],
        ];
      }
      $errors = $validation_result->getFormattedErrors($this->validationResultFormatter, 'relationship_nodes');
      // The formatter returns a heading line followed by "- error" lines.
      $items = [];
      foreach (explode("\n", $errors) as $line) {
        if (str_starts_with($line, '- ')) {
          $items[] = substr($line, 2);
        }
      }
      return [
        'relationship_nodes_config' => [
          'title' => $title,
          'value' => $this->t('Configuration validation failed'),
          'description' => [
            '#theme' => 'item_list',
            '#title' => $this->t('The following configuration issues must be resolved:'),
            '#items' => $items,
          ],
          'severity' => $this->severity('warning'),
        ],
      ];
    }
    catch (\Throwable $e) {
      return [
        'relationship_nodes_config' => [
          'title' => $title,
          'value' => $this->t('Validation failed'),
          'description' => $this->t('Error during validation: @error', ['@error' => $e->getMessage()]),
          'severity' => $this->severity('error'),
        ],
      ];
    }
  }

  /**
   * Returns a requirement severity for the running Drupal version.
   *
   * Drupal 11.2 replaced the REQUIREMENT_* constants with an enum.
   *
   * @param string $level
   *   One of 'info', 'ok', 'warning' or 'error'.
   *
   * @return mixed
   *   The severity enum case, or the legacy integer constant.
   */
  protected function severity(string $level): mixed {
    if (enum_exists(RequirementSeverity::class)) {
      return match ($level) {
        'info' => RequirementSeverity::Info,
        'ok' => RequirementSeverity::OK,
        'warning' => RequirementSeverity::Warning,
        default => RequirementSeverity::Error,
      };
    }
    return match ($level) {
      'info' => REQUIREMENT_INFO,
      'ok' => REQUIREMENT_OK,
      'warning' => REQUIREMENT_WARNING,
      default => REQUIREMENT_ERROR,
    };
  }

}
