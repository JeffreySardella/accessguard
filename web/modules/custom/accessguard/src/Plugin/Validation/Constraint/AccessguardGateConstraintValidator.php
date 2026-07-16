<?php

namespace Drupal\accessguard\Plugin\Validation\Constraint;

use Drupal\accessguard\Service\GateEvaluator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the AccessguardGate constraint.
 */
class AccessguardGateConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected GateEvaluator $gateEvaluator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('accessguard.gate_evaluator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    if (!$entity || $entity->isNew() || !$entity->isPublished()) {
      return;
    }
    if ($this->currentUser->hasPermission('bypass accessguard gating')) {
      return;
    }
    $config = $this->configFactory->get('accessguard.settings');
    if (!$config->get('gate_enabled')) {
      return;
    }

    // Only gate the transition INTO a published state. If the node is already
    // published in storage, allow the save — otherwise an editor could never
    // save a fix, because the pre-fix scan would keep blocking it. Edits are
    // re-scanned via the save hook, and cron keeps published content reviewed.
    $stored = $this->entityTypeManager->getStorage('node')->loadUnchanged($entity->id());
    if ($stored && $stored->isPublished()) {
      return;
    }

    // The counting policy (threshold ranks, waivers, needs-review handling)
    // lives in GateEvaluator, shared with the accessguard:gate CI command.
    // NULL means never scanned: nothing to gate on.
    $blocking = $this->gateEvaluator->blockingCount((int) $entity->id());
    if ($blocking !== NULL && $blocking > 0) {
      $this->context->addViolation($constraint->message, [
        '@count' => $blocking,
        '@threshold' => $config->get('gate_threshold') ?: 'critical',
      ]);
    }
  }

}
