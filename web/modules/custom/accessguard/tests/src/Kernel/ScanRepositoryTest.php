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

    $this->assertSame($latest7, $repo->latestScanIdForNode(7));
    $this->assertNull($repo->latestScanIdForNode(404));
  }

  /**
   * Tests that "latest" follows created DESC, id DESC — not MAX(id).
   *
   * Under concurrent scanning, a scan with a later id can carry an earlier
   * `created` (request time). The repository must agree with the gate and
   * RegressionService, which order by created DESC then id DESC, so the
   * dashboard doesn't name a different "latest" scan than the gate enforces.
   */
  public function testLatestFollowsCreatedThenId(): void {
    // Insert the later-created scan FIRST (so it gets the lower id), then an
    // earlier-created scan (higher id). MAX(id) would wrongly pick the second;
    // created DESC, id DESC correctly picks the first.
    $laterCreatedLowerId = $this->makeScan(11, 2000);
    $earlierCreatedHigherId = $this->makeScan(11, 1000);
    $this->assertGreaterThan($laterCreatedLowerId, $earlierCreatedHigherId);

    $repo = \Drupal::service('accessguard.scan_repository');
    $this->assertSame($laterCreatedLowerId, $repo->latestScanIdByNode()[11]);
    $this->assertSame($laterCreatedLowerId, $repo->latestScanIdForNode(11));
  }

  /**
   * Tests the same-second tie-break: greatest id wins.
   */
  public function testSameSecondTieBreaksById(): void {
    $this->makeScan(13, 5000);
    $tieWinner = $this->makeScan(13, 5000);

    $repo = \Drupal::service('accessguard.scan_repository');
    $this->assertSame($tieWinner, $repo->latestScanIdByNode()[13]);
    $this->assertSame($tieWinner, $repo->latestScanIdForNode(13));
  }

}
