<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group accessguard
 */
class ScanRepositoryTest extends KernelTestBase {

  protected static $modules = ['accessguard', 'node', 'user', 'system', 'field', 'text', 'filter'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('accessguard_scan');
    $this->installEntitySchema('accessguard_violation');
    $this->installEntitySchema('user');
  }

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
