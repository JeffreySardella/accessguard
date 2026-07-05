<?php

namespace Drupal\accessguard\Repository;

use Drupal\Core\Database\Connection;

/**
 * Efficient lookups over accessguard_scan without loading every scan.
 */
class ScanRepository {

  public function __construct(protected Connection $database) {}

  /**
   * Latest scan entity id per node (latest = highest scan id).
   *
   * @return array<int, int> node id => scan id
   */
  public function latestScanIdByNode(): array {
    $rows = $this->database->query(
      'SELECT target_entity_id AS nid, MAX(id) AS scan_id FROM {accessguard_scan} WHERE target_entity_type = :type GROUP BY target_entity_id',
      [':type' => 'node']
    )->fetchAllKeyed();
    return array_map('intval', $rows);
  }

  /**
   * Latest scan timestamp per node, without loading any entity.
   *
   * @return array<int, int> node id => created timestamp
   */
  public function latestScanCreatedByNode(): array {
    $rows = $this->database->query(
      'SELECT target_entity_id AS nid, MAX(created) AS latest FROM {accessguard_scan} WHERE target_entity_type = :type GROUP BY target_entity_id',
      [':type' => 'node']
    )->fetchAllKeyed();
    return array_map('intval', $rows);
  }

}
