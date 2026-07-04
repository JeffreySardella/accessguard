<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

class ScanRecorder {
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public function record(string $entityType, int $entityId, ?int $authorUid, string $triggeredBy, array $scanResult) {
    $violations = $scanResult['violations'] ?? [];
    $counts = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
    foreach ($violations as $v) {
      $impact = $v['impact'] ?? 'minor';
      if (isset($counts[$impact])) {
        $counts[$impact]++;
      }
    }

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
    foreach ($violations as $v) {
      $vStorage->create([
        'scan_id' => $scan->id(),
        'rule_id' => $v['ruleId'] ?? '',
        'impact' => $v['impact'] ?? '',
        'wcag_criterion' => $v['wcagCriterion'] ?? '',
        'selector' => $v['selector'] ?? '',
        'html_snippet' => $v['html'] ?? '',
        'help_url' => $v['helpUrl'] ?? '',
      ])->save();
    }
    return $scan;
  }
}
