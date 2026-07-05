<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Persists a scan result as scan and violation entities.
 */
class ScanRecorder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Records a scan result and its violations, in a single transaction.
   *
   * @return \Drupal\accessguard\Entity\AccessguardScan
   *   The saved scan entity.
   */
  public function record(string $entityType, int $entityId, ?int $authorUid, string $triggeredBy, array $scanResult) {
    $validImpacts = ['critical', 'serious', 'moderate', 'minor'];
    $violations = $scanResult['violations'] ?? [];
    $counts = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
    $normalizedImpacts = [];
    foreach ($violations as $key => $v) {
      $impact = in_array($v['impact'] ?? NULL, $validImpacts, TRUE) ? $v['impact'] : 'unknown';
      $normalizedImpacts[$key] = $impact;
      if (isset($counts[$impact])) {
        $counts[$impact]++;
      }
    }

    $transaction = $this->database->startTransaction();
    try {
      $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
      $scan = $scanStorage->create([
        'target_entity_type' => $entityType,
        'target_entity_id' => $entityId,
        'url' => $scanResult['url'] ?? '',
        'triggered_by' => $triggeredBy,
        'content_author' => $authorUid,
        'status' => 'complete',
        'count_critical' => $counts['critical'],
        'count_serious' => $counts['serious'],
        'count_moderate' => $counts['moderate'],
        'count_minor' => $counts['minor'],
      ]);
      $scan->save();

      $vStorage = $this->entityTypeManager->getStorage('accessguard_violation');
      foreach ($violations as $key => $v) {
        $vStorage->create([
          'scan_id' => $scan->id(),
          'rule_id' => $v['ruleId'] ?? '',
          'impact' => $normalizedImpacts[$key],
          'wcag_criterion' => $v['wcagCriterion'] ?? '',
          'selector' => $v['selector'] ?? '',
          'html_snippet' => $v['html'] ?? '',
          'help_url' => $v['helpUrl'] ?? '',
        ])->save();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return $scan;
  }

}
