<?php

namespace Drupal\fieldception\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Telephone constraint.
 *
 * @Constraint(
 *   id = "Fieldception",
 *   label = @Translation("Fieldception", context = "Validation")
 * )
 */
class FieldceptionConstraint extends Constraint {

  public $subfieldItems = NULL;
  public $subfieldConstraints = NULL;

}
