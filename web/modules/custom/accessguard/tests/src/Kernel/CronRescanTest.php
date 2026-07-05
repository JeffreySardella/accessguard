<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * @group accessguard
 */
class CronRescanTest extends KernelTestBase {

  protected static $modules = ['accessguard', 'node', 'user', 'system', 'field', 'text', 'filter'];

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

}
