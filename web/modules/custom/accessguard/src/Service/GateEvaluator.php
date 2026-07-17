<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Severity;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The gate policy: how many latest-scan violations block a node.
 *
 * Owned here so the publish-gate constraint validator and the
 * accessguard:gate CI command apply one definition of "blocking" —
 * threshold rank from Severity, waived fingerprints excluded, needs-review
 * results excluded unless gate_includes_needs_review opts them in.
 */
class GateEvaluator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected WaiverMatcher $waiverMatcher,
    protected ScanRepository $scanRepository,
    protected RescanPolicy $rescanPolicy,
  ) {}

  /**
   * Counts the blocking violations in a node's latest scan.
   *
   * @return int|null
   *   NULL when the node has never been scanned or its content type is
   *   excluded by its re-scan policy (nothing to gate on);
   *   otherwise the number of violations at or above the configured
   *   gate_threshold rank that are not waived (and not needs-review,
   *   unless gate_includes_needs_review is set).
   */
  public function blockingCount(int $nid): ?int {
    // An excluded type is exempt from the gate entirely — otherwise a type
    // excluded after a failing scan could never be published again
    // (automatic re-scans are off, so the stale scan would gate forever).
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if ($node && $this->rescanPolicy->isExcluded($node->bundle())) {
      return NULL;
    }

    $scanId = $this->scanRepository->latestScanIdForNode($nid);
    if (!$scanId) {
      return NULL;
    }

    $config = $this->configFactory->get('accessguard.settings');
    $threshold = Severity::rank($config->get('gate_threshold') ?: 'critical') ?: 4;
    $includeNeedsReview = (bool) $config->get('gate_includes_needs_review');

    $waived = $this->waiverMatcher->waivedFingerprints($nid);
    $violations = $this->entityTypeManager->getStorage('accessguard_violation')
      ->loadByProperties(['scan_id' => $scanId]);

    $blocking = 0;
    foreach ($violations as $v) {
      if (!$includeNeedsReview && $v->get('result_type')->value === 'needs_review') {
        continue;
      }
      // Normalize before ranking: ScanRecorder stores normalized impacts,
      // but rows predating that (or written by hand) may carry raw values,
      // and an unrecognized impact must rank as UNKNOWN (gateable), not 0
      // (invisible to every gate).
      if (Severity::rank(Severity::normalize($v->get('impact')->value)) < $threshold) {
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
    return $blocking;
  }

}
