<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Interprets a content type's re-scan policy (third-party settings).
 *
 * One owner for the inherit/custom/disabled semantics so the save hook,
 * cron, and the gate can't drift apart — the same centralization move as
 * GateEvaluator. 'disabled' means AccessGuard ignores the type entirely:
 * no automatic scans and no gating.
 */
class RescanPolicy {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether AccessGuard ignores this bundle entirely.
   */
  public function isExcluded(string $bundle): bool {
    return $this->mode($bundle) === 'disabled';
  }

  /**
   * The re-scan staleness interval for this bundle, in seconds.
   *
   * Custom mode uses the type's own interval when it is sane (>= 60);
   * anything else — inherit, an unknown bundle, hand-edited nonsense —
   * falls back to the global rescan_interval.
   */
  public function intervalFor(string $bundle): int {
    $global = (int) ($this->configFactory->get('accessguard.settings')->get('rescan_interval') ?: 86400);
    if ($this->mode($bundle) !== 'custom') {
      return $global;
    }
    $type = $this->entityTypeManager->getStorage('node_type')->load($bundle);
    $interval = (int) $type->getThirdPartySetting('accessguard', 'rescan_interval', 0);
    return $interval >= 60 ? $interval : $global;
  }

  /**
   * The bundle's rescan_mode ('inherit' when unset or the type is unknown).
   */
  protected function mode(string $bundle): string {
    $type = $this->entityTypeManager->getStorage('node_type')->load($bundle);
    return $type ? (string) $type->getThirdPartySetting('accessguard', 'rescan_mode', 'inherit') : 'inherit';
  }

}
