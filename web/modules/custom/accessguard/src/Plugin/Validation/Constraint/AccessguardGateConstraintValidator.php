<?php

namespace Drupal\accessguard\Plugin\Validation\Constraint;

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
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint) {
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

    $rank = ['minor' => 1, 'moderate' => 2, 'serious' => 3, 'critical' => 4];
    $thresholdName = $config->get('gate_threshold') ?: 'critical';
    $threshold = $rank[$thresholdName] ?? 4;

    $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
    $ids = $scanStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $entity->id())
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      // Never scanned: nothing to gate on.
      return;
    }

    $scan = $scanStorage->load(reset($ids));
    $blocking = 0;
    foreach ($rank as $sev => $r) {
      if ($r >= $threshold) {
        $blocking += (int) $scan->get('count_' . $sev)->value;
      }
    }

    if ($blocking > 0) {
      $this->context->addViolation($constraint->message, [
        '@count' => $blocking,
        '@threshold' => $thresholdName,
      ]);
    }
  }

}
