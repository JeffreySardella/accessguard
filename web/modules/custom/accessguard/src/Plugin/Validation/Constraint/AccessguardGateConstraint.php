<?php

namespace Drupal\accessguard\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Blocks publishing a node with unresolved accessibility violations.
 *
 * @Constraint(
 *   id = "AccessguardGate",
 *   label = @Translation("AccessGuard publish gate"),
 *   type = "entity:node"
 * )
 */
class AccessguardGateConstraint extends Constraint {

  /**
   * Violation message.
   *
   * @var string
   */
  public string $message = 'This page cannot be published: its latest accessibility scan found @count violation(s) at or above the "@threshold" severity. Fix them, or you need the "bypass accessguard gating" permission.';

}
