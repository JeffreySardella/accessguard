<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TrendBuilder's daily state-series fold.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class TrendBuilderTest extends KernelTestBase {

  use UserCreationTrait;

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
    $this->installEntitySchema('accessguard_waiver');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'accessguard']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->createUser([]);
    // Day bucketing happens in the site timezone; pin it so the fixture
    // timestamps land on deterministic days.
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
  }

  /**
   * Creates a node, returns its id.
   */
  private function makeNode(bool $published = TRUE): int {
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => $published ? 1 : 0]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Records a scan with explicit counts at an explicit time.
   */
  private function makeScan(int $nid, string $when, array $counts): void {
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
      'created' => strtotime($when),
      'count_critical' => $counts['critical'] ?? 0,
      'count_serious' => $counts['serious'] ?? 0,
      'count_moderate' => $counts['moderate'] ?? 0,
      'count_minor' => $counts['minor'] ?? 0,
      'count_needs_review' => $counts['needs_review'] ?? 0,
    ])->save();
  }

  /**
   * Tests the fold: latest scan per node as of each day, summed.
   */
  public function testDailySeriesFoldsLatestScanPerNode(): void {
    $a = $this->makeNode();
    $b = $this->makeNode();
    // Day 1: only A, 3 critical.
    $this->makeScan($a, '2026-07-01 12:00:00 UTC', ['critical' => 3]);
    // Day 2: B arrives with 2 serious; A's state persists.
    $this->makeScan($b, '2026-07-02 12:00:00 UTC', ['serious' => 2]);
    // Day 3: A re-scanned down to 1 critical — replaces its day-1 counts.
    $this->makeScan($a, '2026-07-03 12:00:00 UTC', ['critical' => 1]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertCount(3, $series);
    $this->assertSame(['2026-07-01', 3, 0], [$series[0]['date'], $series[0]['critical'], $series[0]['serious']]);
    $this->assertSame(['2026-07-02', 3, 2], [$series[1]['date'], $series[1]['critical'], $series[1]['serious']]);
    $this->assertSame(['2026-07-03', 1, 2], [$series[2]['date'], $series[2]['critical'], $series[2]['serious']]);
    $this->assertSame(5, $series[1]['total']);
    $this->assertSame(3, $series[2]['total']);
  }

  /**
   * Tests a same-day re-scan uses only the later scan's counts.
   */
  public function testSameDayRescanUsesLatest(): void {
    $a = $this->makeNode();
    $this->makeScan($a, '2026-07-01 09:00:00 UTC', ['critical' => 5]);
    $this->makeScan($a, '2026-07-01 15:00:00 UTC', ['critical' => 2]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertCount(1, $series);
    $this->assertSame(2, $series[0]['critical']);
  }

  /**
   * Tests scans of inaccessible nodes are excluded.
   */
  public function testExcludesInaccessibleNodes(): void {
    $secret = $this->makeNode(FALSE);
    $open = $this->makeNode();
    $this->makeScan($secret, '2026-07-01 12:00:00 UTC', ['critical' => 9]);
    $this->makeScan($open, '2026-07-01 12:00:00 UTC', ['minor' => 1]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertCount(1, $series);
    $this->assertSame(0, $series[0]['critical']);
    $this->assertSame(1, $series[0]['minor']);
  }

  /**
   * Tests needs-review is reported but excluded from the total.
   */
  public function testNeedsReviewExcludedFromTotal(): void {
    $a = $this->makeNode();
    $this->makeScan($a, '2026-07-01 12:00:00 UTC', ['critical' => 1, 'needs_review' => 4]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertSame(4, $series[0]['needs_review']);
    $this->assertSame(1, $series[0]['total']);
  }

}
