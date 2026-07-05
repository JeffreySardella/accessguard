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

}
