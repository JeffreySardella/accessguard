<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Plugin\QueueWorker\AccessguardScanWorker;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests the scan queue worker's failure handling and URL building.
 *
 * @group accessguard
 */
class ScanWorkerTest extends KernelTestBase {

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
   * Replaces the scan runner with a mock and returns it.
   */
  private function mockRunner(): ScanRunner&\PHPUnit\Framework\MockObject\MockObject {
    $runner = $this->createMock(ScanRunner::class);
    $this->container->set('accessguard.scan_runner', $runner);
    return $runner;
  }

  /**
   * Instantiates the queue worker plugin (after any container overrides).
   */
  private function createWorker(): AccessguardScanWorker {
    return \Drupal::service('plugin.manager.queue_worker')->createInstance('accessguard_scan_queue');
  }

  /**
   * Removes any items the save hook enqueued, isolating worker behavior.
   */
  private function drainQueue(): void {
    $queue = \Drupal::queue('accessguard_scan_queue');
    while ($item = $queue->claimItem()) {
      $queue->deleteItem($item);
    }
  }

  /**
   * Tests that a failure with the scanner down suspends the whole queue.
   */
  public function testScannerOutageSuspendsQueue(): void {
    $node = Node::create(['type' => 'page', 'title' => 't', 'status' => 1]);
    $node->save();
    $runner = $this->mockRunner();
    $runner->method('scan')->willThrowException(new \RuntimeException('connection refused'));
    $runner->method('isHealthy')->willReturn(FALSE);

    $this->expectException(SuspendQueueException::class);
    $this->createWorker()->processItem(['nid' => (int) $node->id(), 'trigger' => 'cron']);
  }

  /**
   * Tests bounded retry when one scan fails against a healthy scanner.
   *
   * A single permanently failing page must not suspend the queue (that would
   * block every other node's scan at the head of the FIFO forever). It gets
   * re-enqueued with an attempt counter and is dropped after MAX_ATTEMPTS.
   */
  public function testItemFailureRetriesThenDrops(): void {
    $node = Node::create(['type' => 'page', 'title' => 't', 'status' => 1]);
    $node->save();
    $this->drainQueue();
    $runner = $this->mockRunner();
    $runner->method('scan')->willThrowException(new \RuntimeException('scan_failed'));
    $runner->method('isHealthy')->willReturn(TRUE);
    $worker = $this->createWorker();
    $queue = \Drupal::queue('accessguard_scan_queue');

    // First failure: no exception, item re-enqueued with attempts = 1.
    $worker->processItem(['nid' => (int) $node->id(), 'trigger' => 'cron']);
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $queue->deleteItem($item);
    $this->assertSame(1, $item->data['attempts']);
    // The original payload survives the requeue.
    $this->assertSame('cron', $item->data['trigger']);

    // Second failure: re-enqueued with attempts = 2.
    $worker->processItem($item->data);
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $queue->deleteItem($item);
    $this->assertSame(2, $item->data['attempts']);

    // Third failure reaches MAX_ATTEMPTS: dropped, nothing re-enqueued.
    $worker->processItem($item->data);
    $this->assertSame(0, $queue->numberOfItems());
    // No scan was ever recorded for the node.
    $scans = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_id', $node->id())
      ->execute();
    $this->assertEmpty($scans);
  }

  /**
   * Tests that unpublished nodes are scanned via a token-bearing URL.
   *
   * Without the token the anonymous scanner would receive the site's 403
   * page, and its (clean or dirty) markup would be recorded as the node's
   * compliance state.
   */
  public function testUnpublishedNodeIsScannedWithAccessToken(): void {
    $node = Node::create(['type' => 'page', 'title' => 'draft', 'status' => 0]);
    $node->save();
    $captured = NULL;
    $runner = $this->mockRunner();
    $runner->method('scan')->willReturnCallback(function (string $url) use (&$captured) {
      $captured = $url;
      return ['url' => $url, 'violations' => []];
    });

    $this->createWorker()->processItem(['nid' => (int) $node->id(), 'trigger' => 'save']);

    $this->assertIsString($captured);
    $query = [];
    parse_str((string) parse_url($captured, PHP_URL_QUERY), $query);
    $token = $query['accessguard-scan-token'] ?? '';
    $this->assertNotSame('', $token, 'Scan URL for an unpublished node carries an access token.');
    $this->assertTrue(\Drupal::service('accessguard.scan_access_token')->validate((int) $node->id(), $token));

    // The successful scan was recorded.
    $scans = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_id', $node->id())
      ->execute();
    $this->assertCount(1, $scans);
  }

  /**
   * Tests that published nodes are scanned at their plain canonical URL.
   */
  public function testPublishedNodeUrlCarriesNoToken(): void {
    $node = Node::create(['type' => 'page', 'title' => 'live', 'status' => 1]);
    $node->save();
    $captured = NULL;
    $runner = $this->mockRunner();
    $runner->method('scan')->willReturnCallback(function (string $url) use (&$captured) {
      $captured = $url;
      return ['url' => $url, 'violations' => []];
    });

    $this->createWorker()->processItem(['nid' => (int) $node->id(), 'trigger' => 'save']);

    $this->assertIsString($captured);
    $this->assertStringNotContainsString('accessguard-scan-token', $captured);
  }

  /**
   * Tests that a deleted node's queue item is skipped without a scan.
   */
  public function testMissingNodeIsSkipped(): void {
    $runner = $this->mockRunner();
    $runner->expects($this->never())->method('scan');
    $this->createWorker()->processItem(['nid' => 12345, 'trigger' => 'cron']);
  }

  /**
   * Tests that saving an unpublished node enqueues a scan.
   *
   * Draft re-scans are what let a node blocked by the publish gate record a
   * clean scan after a fix, instead of being stuck behind a stale failing
   * scan that only a published node could ever refresh.
   */
  public function testUnpublishedSaveEnqueuesScan(): void {
    $queue = \Drupal::queue('accessguard_scan_queue');
    $before = $queue->numberOfItems();
    Node::create(['type' => 'page', 'title' => 'draft', 'status' => 0])->save();
    $this->assertSame(1, $queue->numberOfItems() - $before);
  }

}
