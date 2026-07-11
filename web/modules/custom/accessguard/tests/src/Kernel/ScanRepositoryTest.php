<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ScanRepository's aggregate lookups over accessguard_scan.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class ScanRepositoryTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'node', 'user', 'system', 'field', 'text', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('accessguard_scan');
    $this->installEntitySchema('accessguard_violation');
    $this->installEntitySchema('user');
  }

  /**
   * Creates a scan entity with the given node id and created timestamp.
   */
  private function makeScan(int $nid, int $created): int {
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
    ]);
    $scan->save();
    $scan->set('created', $created)->save();
    return (int) $scan->id();
  }

  /**
   * Tests the latest-scan-id and latest-created lookups per node.
   */
  public function testLatestScanIdAndCreatedPerNode(): void {
    // Node 7: two scans; node 9: one scan.
    $this->makeScan(7, 1000);
    $latest7 = $this->makeScan(7, 2000);
    $only9 = $this->makeScan(9, 1500);

    $repo = \Drupal::service('accessguard.scan_repository');

    $ids = $repo->latestScanIdByNode();
    $this->assertSame($latest7, $ids[7]);
    $this->assertSame($only9, $ids[9]);

    $created = $repo->latestScanCreatedByNode();
    $this->assertSame(2000, $created[7]);
    $this->assertSame(1500, $created[9]);
  }

}
