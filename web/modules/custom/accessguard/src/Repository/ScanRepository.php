<?php

namespace Drupal\accessguard\Repository;

use Drupal\Core\Database\Connection;

/**
 * Efficient lookups over accessguard_scan without loading every scan.
 */
class ScanRepository {

  public function __construct(protected Connection $database) {}

  /**
   * Latest scan entity id per node.
   *
   * "Latest" means the same ordering the publish gate and RegressionService
   * use — greatest `created`, tie-broken by greatest `id` — so the dashboard,
   * exports, and analytics name the same scan the gate enforces against. A
   * plain MAX(id) can disagree with that ordering when `created` (request
   * time) and `id` (commit order) diverge under concurrent scanning.
   *
   * @return array<int, int>
   *   Node id => scan id.
   */
  public function latestScanIdByNode(): array {
    // Per node, pick the row for which no other row of that node is later by
    // (created, id). Portable across MySQL/PostgreSQL/SQLite.
    $rows = $this->database->query(
      'SELECT s.target_entity_id AS nid, s.id AS scan_id
       FROM {accessguard_scan} s
       WHERE s.target_entity_type = :type
         AND NOT EXISTS (
           SELECT 1 FROM {accessguard_scan} s2
           WHERE s2.target_entity_type = :type
             AND s2.target_entity_id = s.target_entity_id
             AND (s2.created > s.created OR (s2.created = s.created AND s2.id > s.id))
         )',
      [':type' => 'node']
    )->fetchAllKeyed();
    return array_map('intval', $rows);
  }

  /**
   * Latest scan entity id for one node (created DESC, id DESC — see above).
   */
  public function latestScanIdForNode(int $nid): ?int {
    $id = $this->database->select('accessguard_scan', 's')
      ->fields('s', ['id'])
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nid)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $id ? (int) $id : NULL;
  }

  /**
   * Latest scan timestamp per node, without loading any entity.
   *
   * @return array<int, int>
   *   Node id => created timestamp.
   */
  public function latestScanCreatedByNode(): array {
    $rows = $this->database->query(
      'SELECT target_entity_id AS nid, MAX(created) AS latest FROM {accessguard_scan} WHERE target_entity_type = :type GROUP BY target_entity_id',
      [':type' => 'node']
    )->fetchAllKeyed();
    return array_map('intval', $rows);
  }

}
