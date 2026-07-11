<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Persists a scan result as scan and violation entities.
 */
class ScanRecorder {

  /**
   * Column width of the varchar fields we persist axe output into.
   *
   * Matches the max_length on AccessguardScan::url and
   * AccessguardViolation::selector/help_url.
   */
  protected const VARCHAR_LIMIT = 2048;

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
        'url' => $this->cap($this->stripScanToken($scanResult['url'] ?? '')),
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
          'selector' => $this->cap($v['selector'] ?? ''),
          'html_snippet' => $v['html'] ?? '',
          'help_url' => $this->cap($v['helpUrl'] ?? ''),
        ])->save();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return $scan;
  }

  /**
   * Removes the scan-access token from a URL before it is persisted.
   *
   * The token is a short-lived bearer credential the queue worker adds so the
   * scanner can view unpublished nodes. The stored URL is only ever displayed
   * (dashboard, CSV, PDF) and never re-fetched, so keeping the token would
   * leak a live credential into shareable audit exports for no benefit.
   */
  protected function stripScanToken(string $url): string {
    if (!str_contains($url, ScanAccessToken::QUERY_KEY)) {
      return $url;
    }
    $parts = parse_url($url);
    if ($parts === FALSE) {
      return $url;
    }
    $query = [];
    if (isset($parts['query'])) {
      parse_str($parts['query'], $query);
      unset($query[ScanAccessToken::QUERY_KEY]);
    }
    $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '';
    $qs = $query ? '?' . http_build_query($query) : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $scheme . $host . $port . $path . $qs . $fragment;
  }

  /**
   * Caps a value to the varchar column width.
   *
   * An oversized axe selector would otherwise abort the whole scan
   * transaction (SQLSTATE 22001 on strict backends) or be silently truncated
   * to a corrupt element pointer.
   */
  protected function cap(string $value): string {
    return mb_substr($value, 0, self::VARCHAR_LIMIT);
  }

}
