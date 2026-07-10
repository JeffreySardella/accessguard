<?php

namespace Drupal\accessguard\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\accessguard\Service\WaiverMatcher;
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
    protected WaiverMatcher $waiverMatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('accessguard.waiver_matcher'),
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

    // axe-core can return a null impact; ScanRecorder stores it as 'unknown'.
    // Rank unknown alongside moderate so it is gateable (at a moderate or
    // minor threshold) instead of invisibly passing every gate — an
    // unrankable violation should not be weaker than the weakest known rank.
    $rank = ['minor' => 1, 'moderate' => 2, 'unknown' => 2, 'serious' => 3, 'critical' => 4];
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
    $scanId = reset($ids);

    $waived = $this->waiverMatcher->waivedFingerprints((int) $entity->id());
    $violationStorage = $this->entityTypeManager->getStorage('accessguard_violation');
    $violations = $violationStorage->loadByProperties(['scan_id' => $scanId]);

    $blocking = 0;
    foreach ($violations as $v) {
      $impact = $v->get('impact')->value;
      if (($rank[$impact] ?? 0) < $threshold) {
        continue;
      }
      $fp = WaiverMatcher::fingerprint(
        $v->get('rule_id')->value,
        (string) $v->get('selector')->value
      );
      if (!isset($waived[$fp])) {
        $blocking++;
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
