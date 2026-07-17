<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Folds scan history into a per-day site-state severity series.
 *
 * Each row is "site state as of end of that day": for every node scanned
 * on or before day D, its latest scan up to D contributes its stored
 * per-severity counts. Counts are as recorded at scan time — waivers are
 * not applied retroactively (historical waiver state is not
 * reconstructable), so this series can differ from the Overview's
 * open-violation numbers by design.
 */
class TrendBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRepository $scanRepository,
    protected AccountProxyInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The daily state series, oldest day first.
   *
   * @return array<int, array{date: string, critical: int, serious: int, moderate: int, minor: int, needs_review: int, total: int}>
   *   One row per day that had at least one scan of an accessible node.
   */
  public function dailySeries(): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $allowed = [];
    $byDay = [];
    foreach ($this->scanRepository->allScanMeta() as $row) {
      $nid = (int) $row['nid'];
      // Access-check each node once; scans of deleted or restricted nodes
      // are excluded entirely (same posture as ViolationAnalytics).
      if (!isset($allowed[$nid])) {
        $node = $nodeStorage->load($nid);
        $allowed[$nid] = $node && $node->access('view', $this->currentUser);
      }
      if (!$allowed[$nid]) {
        continue;
      }
      $day = $this->dateFormatter->format((int) $row['created'], 'custom', 'Y-m-d');
      $byDay[$day][] = $row;
    }
    ksort($byDay);

    $series = [];
    $state = [];
    foreach ($byDay as $day => $scans) {
      // Rows arrive (created, id)-ascending, so within a day the last write
      // per node wins — a same-day re-scan replaces the earlier counts.
      foreach ($scans as $row) {
        $state[(int) $row['nid']] = $row;
      }
      $sum = [
        'date' => $day,
        'critical' => 0,
        'serious' => 0,
        'moderate' => 0,
        'minor' => 0,
        'needs_review' => 0,
      ];
      foreach ($state as $row) {
        foreach (['critical', 'serious', 'moderate', 'minor', 'needs_review'] as $key) {
          $sum[$key] += (int) $row['count_' . $key];
        }
      }
      // Needs-review findings are uncertain by definition and stay out of
      // the total, consistent with the gate and the compliance summary.
      $sum['total'] = $sum['critical'] + $sum['serious'] + $sum['moderate'] + $sum['minor'];
      $series[] = $sum;
    }
    return $series;
  }

}
