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
   */
  public static function fingerprint(string $ruleId, string $selector): string {
    return $ruleId . '|' . $selector;
  }

  /**
   * Waived fingerprints for a node.
   *
   * @return array<string, string>
   *   Map of "rule_id|selector" => waiver status.
   */
  public function waivedFingerprints(int $nid): array {
    $storage = $this->entityTypeManager->getStorage('accessguard_waiver');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nid)
      ->execute();
    $map = [];
    foreach ($storage->loadMultiple($ids) as $waiver) {
      $fp = self::fingerprint($waiver->get('rule_id')->value, (string) $waiver->get('selector')->value);
      $map[$fp] = $waiver->get('status')->value;
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
