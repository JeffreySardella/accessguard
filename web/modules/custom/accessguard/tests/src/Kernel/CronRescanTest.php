<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests cron's site-wide re-scanning of stale or unscanned published nodes.
 *
 * @group accessguard
 */
class CronRescanTest extends KernelTestBase {

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
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'filter', 'node', 'accessguard']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Tests that cron enqueues a published node that has never been scanned.
   */
  public function testCronEnqueuesUnscannedPublishedNode(): void {
    $node = Node::create(['type' => 'page', 'title' => 'never scanned', 'status' => 1]);
    $node->save();

    // Saving a published node also enqueues via the save hook, so measure the
    // count that cron specifically adds as a delta.
    $queue = \Drupal::queue('accessguard_scan_queue');
    $before = $queue->numberOfItems();

    accessguard_cron();

    $this->assertSame(1, $queue->numberOfItems() - $before);
  }

  /**
   * Tests that cron skips a node whose latest scan is still fresh.
   */
  public function testCronSkipsRecentlyScannedNode(): void {
    $node = Node::create(['type' => 'page', 'title' => 'fresh', 'status' => 1]);
    $node->save();
    // A scan created "now" is within the default 86400s interval, so not stale.
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
      'created' => \Drupal::time()->getRequestTime(),
    ])->save();

    $queue = \Drupal::queue('accessguard_scan_queue');
    $before = $queue->numberOfItems();

    accessguard_cron();

    // Cron adds nothing because the node was recently scanned.
    $this->assertSame(0, $queue->numberOfItems() - $before);
  }

  /**
   * Tests that cron does not re-enqueue a node awaiting a queued scan.
   */
  public function testCronDoesNotReenqueueWhileScanPending(): void {
    $node = Node::create(['type' => 'page', 'title' => 'stale', 'status' => 1]);
    $node->save();
    $queue = \Drupal::queue('accessguard_scan_queue');
    $before = $queue->numberOfItems();

    accessguard_cron();
    $this->assertSame(1, $queue->numberOfItems() - $before);

    // A second cron run before the queued scan is processed must not pile up
    // duplicate items for the same node.
    accessguard_cron();
    $this->assertSame(1, $queue->numberOfItems() - $before);
  }

  /**
   * Tests that an expired pending marker lets cron re-enqueue the node.
   *
   * If a queued item is lost (e.g. the queue is wiped), the node must not be
   * skipped forever: once the marker is older than the re-scan interval, cron
   * tries again.
   */
  public function testCronRetriesWhenPendingMarkerExpires(): void {
    $node = Node::create(['type' => 'page', 'title' => 'stale', 'status' => 1]);
    $node->save();
    $queue = \Drupal::queue('accessguard_scan_queue');
    $before = $queue->numberOfItems();

    accessguard_cron();
    $this->assertSame(1, $queue->numberOfItems() - $before);

    // Age the marker past the re-scan interval, as if the enqueue happened
    // long ago but no scan ever completed.
    $enqueued = \Drupal::state()->get('accessguard.cron_enqueued', []);
    $enqueued[(int) $node->id()] = \Drupal::time()->getRequestTime() - 999999;
    \Drupal::state()->set('accessguard.cron_enqueued', $enqueued);

    accessguard_cron();
    $this->assertSame(2, $queue->numberOfItems() - $before);
  }

  /**
   * Tests that cron-enqueued items carry the 'cron' trigger in their payload.
   */
  public function testCronEnqueuesWithCronTrigger(): void {
    $node = Node::create(['type' => 'page', 'title' => 't', 'status' => 1]);
    $node->save();
    $queue = \Drupal::queue('accessguard_scan_queue');
    // Drain anything the save hook queued.
    while ($item = $queue->claimItem()) {
      $queue->deleteItem($item);
    }
    accessguard_cron();
    $item = $queue->claimItem();
    $this->assertSame('cron', $item->data['trigger'] ?? NULL);
  }

}
