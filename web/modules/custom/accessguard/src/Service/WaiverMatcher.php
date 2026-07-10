<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Looks up active waivers for a node, keyed by rule+selector fingerprint.
 */
class WaiverMatcher {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * The fingerprint that ties a waiver to a violation across scans.
   *
   * JSON-encoded so a delimiter character appearing inside the selector
   * (e.g. attribute selectors like [xml|lang]) can never make two different
   * rule+selector pairs collide.
   */
  public static function fingerprint(string $ruleId, string $selector): string {
    return json_encode([$ruleId, $selector]);
  }

  /**
   * Waived fingerprints for a node.
   *
   * @return array<string, string>
   *   Map of fingerprint => waiver status.
   */
  public function waivedFingerprints(int $nid): array {
    $details = $this->waiversByNodes([$nid])[$nid] ?? [];
    return array_map(fn(array $w) => $w['status'], $details);
  }

  /**
   * Waiver status and reason for many nodes in one query.
   *
   * Reporting surfaces iterate every scanned node; querying waivers per node
   * turns each dashboard/CSV/PDF request into N queries.
   *
   * @param array<int, int> $nids
   *   Node ids to look up.
   *
   * @return array<int, array<string, array{status: string, reason: string}>>
   *   Per-node map of fingerprint => waiver details. Every requested node id
   *   is present (empty array when the node has no waivers).
   */
  public function waiversByNodes(array $nids): array {
    $map = array_fill_keys(array_map('intval', $nids), []);
    if (!$nids) {
      return $map;
    }
    $storage = $this->entityTypeManager->getStorage('accessguard_waiver');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nids, 'IN')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $waiver) {
      $fp = self::fingerprint($waiver->get('rule_id')->value, (string) $waiver->get('selector')->value);
      $map[(int) $waiver->get('target_entity_id')->value][$fp] = [
        'status' => (string) $waiver->get('status')->value,
        'reason' => (string) $waiver->get('reason')->value,
      ];
    }
    return $map;
  }

  /**
   * Records a waiver for a violation on a node.
   *
   * A fingerprint that is already waived on the node is left untouched, so
   * repeated submissions cannot pile up duplicate waivers.
   */
  public function createWaiver(int $nid, string $ruleId, string $selector, string $status, string $reason, ?int $reviewerUid): void {
    if (isset($this->waivedFingerprints($nid)[self::fingerprint($ruleId, $selector)])) {
      return;
    }
    $this->entityTypeManager->getStorage('accessguard_waiver')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'rule_id' => $ruleId,
      'selector' => $selector,
      'status' => in_array($status, ['accepted_risk', 'false_positive'], TRUE) ? $status : 'accepted_risk',
      'reason' => $reason,
      'reviewer' => $reviewerUid,
    ])->save();
  }

  /**
   * Deletes the waivers matching a rule+selector fingerprint on a node.
   */
  public function deleteWaivers(int $nid, string $ruleId, string $selector): void {
    $storage = $this->entityTypeManager->getStorage('accessguard_waiver');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nid)
      ->condition('rule_id', $ruleId)
      ->condition('selector', $selector)
      ->execute();
    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

}
