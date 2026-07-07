<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
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
   * @var array<int, array{nid:int, author_uid:?int, violations:array, waived:array<string,string>}>|null
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
          'critical' => 0,
          'serious' => 0,
          'moderate' => 0,
          'minor' => 0,
          'waived' => 0,
        ];
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
   * Open-violation totals across accessible nodes.
   *
   * @return array{pages:int, open:int, critical:int, serious:int, moderate:int, minor:int}
   *   Totals across accessible nodes.
   */
  public function summary(): array {
    $out = ['pages' => 0, 'open' => 0, 'critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
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
   * @return \Generator<array{nid:int, author_uid:?int, violations:array, waived:array<string,string>}>
   *   Per-node context for each latest scan the current user may view.
   */
  protected function accessibleScans(): \Generator {
    yield from $this->scanContextCache ??= $this->buildScanContext();
  }

  /**
   * Builds the per-node context for each latest scan the current user may view.
   *
   * @return array<int, array{nid:int, author_uid:?int, violations:array, waived:array<string,string>}>
   *   Per-node context for each latest scan the current user may view.
   */
  protected function buildScanContext(): array {
    $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
    $violationStorage = $this->entityTypeManager->getStorage('accessguard_violation');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $context = [];
    $latestIds = $this->scanRepository->latestScanIdByNode();
    $scans = $scanStorage->loadMultiple(array_values($latestIds));
    foreach ($scans as $scan) {
      $nid = (int) $scan->get('target_entity_id')->value;
      $node = $nodeStorage->load($nid);
      if (!$node || !$node->access('view', $this->currentUser)) {
        continue;
      }
      $violations = $violationStorage->loadByProperties(['scan_id' => $scan->id()]);
      $context[] = [
        'nid' => $nid,
        'author_uid' => $scan->get('content_author')->target_id ? (int) $scan->get('content_author')->target_id : NULL,
        'violations' => $violations,
        'waived' => $this->waiverMatcher->waivedFingerprints($nid),
      ];
    }
    return $context;
  }

}
