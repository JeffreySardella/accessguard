<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Compares a node's two latest scans into new/fixed/persisting rule sets.
 */
class RegressionService {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Diffs the two most recent scans of a node.
   *
   * @return array{new: string[], fixed: string[], persisting: string[], latest_scan: ?string, previous_scan: ?string}
   */
  public function diff(int $nid): array {
    $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
    $ids = array_values($scanStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nid)
      ->sort('created', 'DESC')
      ->range(0, 2)
      ->execute());

    $rulesOf = function (?string $scanId): array {
      if (!$scanId) {
        return [];
      }
      $violations = $this->entityTypeManager->getStorage('accessguard_violation')
        ->loadByProperties(['scan_id' => $scanId]);
      $rules = [];
      foreach ($violations as $v) {
        $rules[$v->get('rule_id')->value] = TRUE;
      }
      return array_keys($rules);
    };

    $latest = $rulesOf($ids[0] ?? NULL);
    $previous = $rulesOf($ids[1] ?? NULL);

    return [
      'new' => array_values(array_diff($latest, $previous)),
      'fixed' => array_values(array_diff($previous, $latest)),
      'persisting' => array_values(array_intersect($latest, $previous)),
      'latest_scan' => $ids[0] ?? NULL,
      'previous_scan' => $ids[1] ?? NULL,
    ];
  }

}
