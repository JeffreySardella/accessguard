<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Severity;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Aggregates latest-scan violations by rule and by content author.
 *
 * Node-access filtering and the open/waived split happen here in PHP (not in
 * SQL) so callers — the analytics tabs and the PDF report — share one
 * definition of the numbers. See WaiverMatcher for the fingerprint scheme.
 */
class ViolationAnalytics {

  /**
   * Memoized per-node context, built on first call to accessibleScans().
   *
   * @var array<int, array{nid:int, created:int, url:string, author_uid:?int, violations:array, waived:array<string,string>, waiver_reasons:array<string,string>}>|null
   */
  protected ?array $scanContextCache = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRepository $scanRepository,
    protected WaiverMatcher $waiverMatcher,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Latest-scan violations grouped by rule.
   *
   * @return array<int, array{rule_id:string, impact:string, wcag:string, pages:int, open:int, waived:int}>
   *   Sorted by open count descending.
   */
  public function byRule(): array {
    $rules = [];
    foreach ($this->accessibleScans() as $ctx) {
      foreach ($ctx['violations'] as $v) {
        $rule = (string) $v->get('rule_id')->value;
        $selector = (string) $v->get('selector')->value;
        $rules[$rule] ??= [
          'rule_id' => $rule,
          'impact' => (string) $v->get('impact')->value,
          'wcag' => (string) $v->get('wcag_criterion')->value,
          'pages' => 0,
          'open' => 0,
          'waived' => 0,
          '_pages_seen' => [],
        ];
        if (empty($rules[$rule]['_pages_seen'][$ctx['nid']])) {
          $rules[$rule]['_pages_seen'][$ctx['nid']] = TRUE;
          $rules[$rule]['pages']++;
        }
        $fp = WaiverMatcher::fingerprint($rule, $selector);
        if (isset($ctx['waived'][$fp])) {
          $rules[$rule]['waived']++;
        }
        else {
          $rules[$rule]['open']++;
        }
      }
    }
    foreach ($rules as &$r) {
      unset($r['_pages_seen']);
    }
    unset($r);
    usort($rules, fn($a, $b) => $b['open'] <=> $a['open']);
    return array_values($rules);
  }

  /**
   * Latest-scan open violations grouped by content author.
   *
   * @return array<int, array{uid:?int, name:string, pages:int, critical:int, serious:int, moderate:int, minor:int, waived:int}>
   *   Sorted by total open descending.
   */
  public function byAuthor(): array {
    $userStorage = $this->entityTypeManager->getStorage('user');
    $authors = [];
    foreach ($this->accessibleScans() as $ctx) {
      $uid = $ctx['author_uid'];
      $key = $uid ?? 0;
      if (!isset($authors[$key])) {
        $name = 'Unknown';
        if ($uid && ($u = $userStorage->load($uid))) {
          $name = $u->getDisplayName();
        }
        $authors[$key] = [
          'uid' => $uid,
          'name' => $name,
          'pages' => 0,
        ] + Severity::zeroCounts() + ['waived' => 0];
      }
      $authors[$key]['pages']++;
      foreach ($ctx['violations'] as $v) {
        $fp = WaiverMatcher::fingerprint((string) $v->get('rule_id')->value, (string) $v->get('selector')->value);
        if (isset($ctx['waived'][$fp])) {
          $authors[$key]['waived']++;
          continue;
        }
        $impact = (string) $v->get('impact')->value;
        if (isset($authors[$key][$impact])) {
          $authors[$key][$impact]++;
        }
      }
    }
    $total = fn(array $a) => $a['critical'] + $a['serious'] + $a['moderate'] + $a['minor'];
    usort($authors, fn($a, $b) => $total($b) <=> $total($a));
    return array_values($authors);
  }

  /**
   * Latest-scan per-page open/waived counts, keyed by node id.
   *
   * This is the dashboard overview's data source, so the overview shows the
   * same open-vs-waived numbers as the gate, the analytics tabs, and the PDF
   * summary — not the raw stored per-scan counts (which include waived
   * violations and omit unknown-impact ones).
   *
   * @return array<int, array{nid:int, created:int, open:int, critical:int, serious:int, moderate:int, minor:int, unknown:int, waived:int}>
   *   Per-node counts for each latest scan the current user may view.
   */
  public function byPage(): array {
    $pages = [];
    foreach ($this->accessibleScans() as $ctx) {
      $row = [
        'nid' => $ctx['nid'],
        'created' => $ctx['created'],
        'open' => 0,
      ] + Severity::zeroCounts() + ['unknown' => 0, 'waived' => 0];
      foreach ($ctx['violations'] as $v) {
        $fp = WaiverMatcher::fingerprint((string) $v->get('rule_id')->value, (string) $v->get('selector')->value);
        if (isset($ctx['waived'][$fp])) {
          $row['waived']++;
          continue;
        }
        $row['open']++;
        $impact = Severity::normalize((string) $v->get('impact')->value);
        $row[$impact]++;
      }
      $pages[$ctx['nid']] = $row;
    }
    return $pages;
  }

  /**
   * Open-violation totals across accessible nodes.
   *
   * @return array{pages:int, open:int, critical:int, serious:int, moderate:int, minor:int}
   *   Totals across accessible nodes.
   */
  public function summary(): array {
    $out = ['pages' => 0, 'open' => 0] + Severity::zeroCounts();
    foreach ($this->accessibleScans() as $ctx) {
      $out['pages']++;
      foreach ($ctx['violations'] as $v) {
        $fp = WaiverMatcher::fingerprint((string) $v->get('rule_id')->value, (string) $v->get('selector')->value);
        if (isset($ctx['waived'][$fp])) {
          continue;
        }
        $out['open']++;
        $impact = (string) $v->get('impact')->value;
        if (isset($out[$impact])) {
          $out[$impact]++;
        }
      }
    }
    return $out;
  }

  /**
   * Yields per-node context for the latest scan of each accessible node.
   *
   * Builds the context array at most once per request and memoizes it on
   * $scanContextCache, since the underlying node/violation/waiver lookups
   * are identical on every call within the same request.
   *
   * @return \Generator<array{nid:int, created:int, url:string, author_uid:?int, violations:array, waived:array<string,string>, waiver_reasons:array<string,string>}>
   *   Per-node context for each latest scan the current user may view.
   */
  protected function accessibleScans(): \Generator {
    yield from $this->latestScanContexts();
  }

  /**
   * The memoized per-node latest-scan contexts, for callers needing arrays.
   *
   * Public so the CSV export and the PDF report builder can iterate the same
   * batched, access-filtered data instead of re-querying per node.
   *
   * @return array<int, array{nid:int, created:int, url:string, author_uid:?int, violations:array, waived:array<string,string>, waiver_reasons:array<string,string>}>
   *   Per-node context for each latest scan the current user may view.
   */
  public function latestScanContexts(): array {
    return $this->scanContextCache ??= $this->buildScanContext();
  }

  /**
   * Builds the per-node context for each latest scan the current user may view.
   *
   * Everything is batch-loaded — one query for scans, nodes, violations, and
   * waivers each — so reporting cost doesn't grow to N-queries-per-node on
   * sites with many scanned pages.
   *
   * @return array<int, array{nid:int, created:int, url:string, author_uid:?int, violations:array, waived:array<string,string>, waiver_reasons:array<string,string>}>
   *   Per-node context for each latest scan the current user may view.
   */
  protected function buildScanContext(): array {
    $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
    $violationStorage = $this->entityTypeManager->getStorage('accessguard_violation');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $latestIds = $this->scanRepository->latestScanIdByNode();
    $scans = $scanStorage->loadMultiple(array_values($latestIds));

    // Batch-load the nodes, then keep only the scans the user may see.
    $nodeStorage->loadMultiple(array_map(fn($scan) => (int) $scan->get('target_entity_id')->value, $scans));
    $accessible = [];
    foreach ($scans as $scan) {
      $nid = (int) $scan->get('target_entity_id')->value;
      $node = $nodeStorage->load($nid);
      if ($node && $node->access('view', $this->currentUser)) {
        $accessible[(int) $scan->id()] = $scan;
      }
    }
    if (!$accessible) {
      return [];
    }

    // All violations of all accessible latest scans in one query.
    $violationsByScan = [];
    $vids = $violationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('scan_id', array_keys($accessible), 'IN')
      ->execute();
    foreach ($violationStorage->loadMultiple($vids) as $violation) {
      $violationsByScan[(int) $violation->get('scan_id')->target_id][] = $violation;
    }

    // All waivers of all accessible nodes in one query.
    $waivers = $this->waiverMatcher->waiversByNodes(
      array_map(fn($scan) => (int) $scan->get('target_entity_id')->value, $accessible)
    );

    $context = [];
    foreach ($accessible as $scanId => $scan) {
      $nid = (int) $scan->get('target_entity_id')->value;
      $nodeWaivers = $waivers[$nid] ?? [];
      $context[] = [
        'nid' => $nid,
        'created' => (int) $scan->get('created')->value,
        'url' => (string) $scan->get('url')->value,
        'author_uid' => $scan->get('content_author')->target_id ? (int) $scan->get('content_author')->target_id : NULL,
        'violations' => $violationsByScan[$scanId] ?? [],
        'waived' => array_map(fn(array $w) => $w['status'], $nodeWaivers),
        'waiver_reasons' => array_map(fn(array $w) => $w['reason'], $nodeWaivers),
      ];
    }
    return $context;
  }

}
