<?php

namespace Drupal\accessguard\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Blocks publishing a node with unresolved accessibility violations.
 */
#[Constraint(
  id: 'AccessguardGate',
  label: new TranslatableMarkup('AccessGuard publish gate'),
  type: ['entity:node'],
)]
class AccessguardGateConstraint extends SymfonyConstraint {

  /**
   * The violation message, with @count and @threshold placeholders.
   */
  public string $message = 'This page cannot be published: its latest accessibility scan found @count violation(s) at or above the "@threshold" severity. Fix them, or you need the "bypass accessguard gating" permission.';

}
