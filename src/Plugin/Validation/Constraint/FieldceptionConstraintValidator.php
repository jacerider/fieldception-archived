<?php

namespace Drupal\fieldception\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;

/**
 * Validates the FieldceptionConstraintValidator constraint.
 */
class FieldceptionConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The typed data manager.
   *
   * @var \Drupal\Core\TypedData\TypedDataManagerInterface
   */
  protected $typedDataManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('typed_data_manager'));
  }

  /**
   * Constructs a new FieldceptionConstraintValidatorValidator.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typed_data_manager
   *   The typed data manager.
   */
  public function __construct(TypedDataManagerInterface $typed_data_manager) {
    $this->typedDataManager = $typed_data_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    $item = $constraint->subfieldItems->first();
    if ($item) {
      $field_definition = $item->getFieldDefinition();
      $name = $field_definition->getName();
      $field_definition->setName($field_definition->getParentfield());
      $violations = $this->typedDataManager->getValidator()->validate($item, $constraint->subfieldConstraints);
      $field_definition->setName($name);
      $subfield_definition = $constraint->subfieldItems->getFieldDefinition();
      foreach ($violations as $violation) {
        $new = $this->context->buildViolation($violation->getMessage(), $violation->getParameters(), $violation->getInvalidValue(), $violation->getPlural(), $violation->getCode());
        if (empty($violation->getPropertyPath())) {
          // Flag only the first value.
          $columns = $subfield_definition->getSchema()['columns'];
          $new->atPath($subfield_definition->getSubfield() . '_' . key($columns));
        }
        $new->addViolation();
      }
    }
  }

}
