<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests cron's site-wide re-scanning of stale or unscanned published nodes.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
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
   * Creates a published node without the save hook enqueueing a scan.
   *
   * Saving normally enqueues a scan AND records the cron dedup marker; these
   * tests need nodes that look "never enqueued" so they exercise cron's own
   * behavior in isolation.
   */
  private function createPublishedNodeQuietly(string $title, string $type = 'page'): Node {
    $config = \Drupal::configFactory()->getEditable('accessguard.settings');
    $config->set('rescan_enabled', FALSE)->save();
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1]);
    $node->save();
    $config->set('rescan_enabled', TRUE)->save();
    return $node;
  }

  /**
   * Tests that cron enqueues a published node that has never been scanned.
   */
  public function testCronEnqueuesUnscannedPublishedNode(): void {
    $this->createPublishedNodeQuietly('never scanned');
    $queue = \Drupal::queue('accessguard_scan_queue');
    $this->assertSame(0, $queue->numberOfItems());

    accessguard_cron();

    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * Tests that cron skips a node whose latest scan is still fresh.
   */
  public function testCronSkipsRecentlyScannedNode(): void {
    $node = $this->createPublishedNodeQuietly('fresh');
    // A scan created "now" is within the default 86400s interval, so not stale.
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
      'created' => \Drupal::time()->getRequestTime(),
    ])->save();

    $queue = \Drupal::queue('accessguard_scan_queue');

    accessguard_cron();

    // Cron adds nothing because the node was recently scanned.
    $this->assertSame(0, $queue->numberOfItems());
  }

  /**
   * Tests that cron does not re-enqueue a node awaiting a queued scan.
   */
  public function testCronDoesNotReenqueueWhileScanPending(): void {
    $this->createPublishedNodeQuietly('stale');
    $queue = \Drupal::queue('accessguard_scan_queue');

    accessguard_cron();
    $this->assertSame(1, $queue->numberOfItems());

    // A second cron run before the queued scan is processed must not pile up
    // duplicate items for the same node.
    accessguard_cron();
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * Tests that a save-triggered enqueue suppresses the cron duplicate.
   *
   * An editor saving a stale node enqueues a scan via the save hook; the
   * next cron run must see that pending enqueue and not add a second item
   * for the same node.
   */
  public function testSaveEnqueueSuppressesCronDuplicate(): void {
    Node::create(['type' => 'page', 'title' => 'just saved', 'status' => 1])->save();
    $queue = \Drupal::queue('accessguard_scan_queue');
    // The save hook enqueued the scan.
    $this->assertSame(1, $queue->numberOfItems());

    accessguard_cron();

    // Cron adds nothing: the save-triggered scan is already pending.
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * Tests that an expired pending marker lets cron re-enqueue the node.
   *
   * If a queued item is lost (e.g. the queue is wiped), the node must not be
   * skipped forever: once the marker is older than the re-scan interval, cron
   * tries again.
   */
  public function testCronRetriesWhenPendingMarkerExpires(): void {
    $node = $this->createPublishedNodeQuietly('stale');
    $queue = \Drupal::queue('accessguard_scan_queue');

    accessguard_cron();
    $this->assertSame(1, $queue->numberOfItems());

    // Age the marker past the re-scan interval, as if the enqueue happened
    // long ago but no scan ever completed.
    $enqueued = \Drupal::state()->get('accessguard.cron_enqueued', []);
    $enqueued[(int) $node->id()] = \Drupal::time()->getRequestTime() - 999999;
    \Drupal::state()->set('accessguard.cron_enqueued', $enqueued);

    accessguard_cron();
    $this->assertSame(2, $queue->numberOfItems());
  }

  /**
   * Tests that retention purges old scans but always keeps the latest.
   *
   * The latest scan per node feeds the publish gate and every dashboard, so
   * retention must never delete it — even when it is itself older than the
   * window.
   */
  public function testRetentionPurgesOldScansButKeepsLatest(): void {
    \Drupal::configFactory()->getEditable('accessguard.settings')->set('retention_days', 30)->save();
    $node = $this->createPublishedNodeQuietly('retention');
    $scanStorage = \Drupal::entityTypeManager()->getStorage('accessguard_scan');
    $violationStorage = \Drupal::entityTypeManager()->getStorage('accessguard_violation');
    $now = \Drupal::time()->getRequestTime();

    // Three scans, all older than the 30-day window; the newest of them is
    // still the node's latest scan and must survive.
    $ids = [];
    foreach ([100, 90, 60] as $daysAgo) {
      $scan = $scanStorage->create([
        'target_entity_type' => 'node',
        'target_entity_id' => $node->id(),
        'status' => 'complete',
        'created' => $now - $daysAgo * 86400,
      ]);
      $scan->save();
      $ids[$daysAgo] = (int) $scan->id();
    }
    $violation = $violationStorage->create([
      'scan_id' => $ids[100],
      'rule_id' => 'image-alt',
      'impact' => 'critical',
      'selector' => 'img',
    ]);
    $violation->save();

    accessguard_cron();

    $remaining = array_map('intval', array_values($scanStorage->getQuery()->accessCheck(FALSE)->execute()));
    $this->assertSame([$ids[60]], $remaining, 'Only the latest scan survives.');
    // The purged scan's violations went with it.
    $this->assertEmpty($violationStorage->getQuery()->accessCheck(FALSE)->execute());
  }

  /**
   * Tests that cron-enqueued items carry the 'cron' trigger in their payload.
   */
  public function testCronEnqueuesWithCronTrigger(): void {
    $this->createPublishedNodeQuietly('t');
    $queue = \Drupal::queue('accessguard_scan_queue');
    accessguard_cron();
    $item = $queue->claimItem();
    $this->assertSame('cron', $item->data['trigger'] ?? NULL);
  }

  /**
   * Tests that saving a node of an excluded type enqueues nothing.
   */
  public function testSaveDoesNotEnqueueExcludedType(): void {
    NodeType::create(['type' => 'internal', 'name' => 'Internal'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')
      ->save();

    Node::create(['type' => 'internal', 'title' => 'excluded', 'status' => 1])->save();

    $this->assertSame(0, \Drupal::queue('accessguard_scan_queue')->numberOfItems());
    // No cron dedup marker either: nothing was enqueued to deduplicate.
    $this->assertSame([], \Drupal::state()->get('accessguard.cron_enqueued', []));
  }

  /**
   * Tests that each type is compared against its own staleness cutoff.
   *
   * Both nodes were scanned two hours ago: stale for the news type's custom
   * one-hour interval, fresh for the page type's inherited 24-hour default.
   */
  public function testCronUsesPerTypeIntervals(): void {
    NodeType::create(['type' => 'news', 'name' => 'News'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'custom')
      ->setThirdPartySetting('accessguard', 'rescan_interval', 3600)
      ->save();
    $page = $this->createPublishedNodeQuietly('page node');
    $news = $this->createPublishedNodeQuietly('news node', 'news');
    $twoHoursAgo = \Drupal::time()->getRequestTime() - 7200;
    foreach ([$page, $news] as $node) {
      \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
        'target_entity_type' => 'node',
        'target_entity_id' => $node->id(),
        'status' => 'complete',
        'created' => $twoHoursAgo,
      ])->save();
    }
    $queue = \Drupal::queue('accessguard_scan_queue');

    accessguard_cron();

    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame((int) $news->id(), $item->data['nid']);
  }

  /**
   * Tests that an excluded type is never enqueued, however stale.
   */
  public function testCronSkipsExcludedType(): void {
    NodeType::create(['type' => 'internal', 'name' => 'Internal'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')
      ->save();
    $this->createPublishedNodeQuietly('never scanned, still skipped', 'internal');

    accessguard_cron();

    $this->assertSame(0, \Drupal::queue('accessguard_scan_queue')->numberOfItems());
  }

}
