<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ViolationAnalytics aggregation, access filtering, and waiver split.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class ViolationAnalyticsTest extends KernelTestBase {

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
  }

  /**
   * Creates a completed scan for a node, returns the scan id.
   */
  private function makeScan(int $nid, ?int $authorUid = NULL, array $counts = []): int {
    $values = [
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
    ];
    foreach ($counts as $sev => $n) {
      $values['count_' . $sev] = $n;
    }
    if ($authorUid !== NULL) {
      $values['content_author'] = $authorUid;
    }
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create($values);
    $scan->save();
    return (int) $scan->id();
  }

  /**
   * Adds a violation to a scan.
   */
  private function addViolation(int $scanId, string $rule, string $impact, string $selector, string $wcag = 'wcag2aa'): void {
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scanId,
      'rule_id' => $rule,
      'impact' => $impact,
      'wcag_criterion' => $wcag,
      'selector' => $selector,
    ])->save();
  }

  /**
   * Tests by-rule aggregation splits open vs waived and counts pages.
   */
  public function testByRuleAggregatesAndSplitsWaivers(): void {
    $a = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $a->save();
    $b = Node::create(['type' => 'page', 'title' => 'B', 'status' => 1]);
    $b->save();
    $scanA = $this->makeScan((int) $a->id());
    $scanB = $this->makeScan((int) $b->id());
    // The image-alt rule appears on both pages; on B it is waived.
    $this->addViolation($scanA, 'image-alt', 'critical', 'img');
    $this->addViolation($scanB, 'image-alt', 'critical', 'img');
    // The label rule appears once and stays open.
    $this->addViolation($scanA, 'label', 'serious', 'input');
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver((int) $b->id(), 'image-alt', 'img', 'false_positive', 'decorative', 1);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $rows = \Drupal::service('accessguard.violation_analytics')->byRule();

    $byRule = [];
    foreach ($rows as $r) {
      $byRule[$r['rule_id']] = $r;
    }
    // image-alt: 2 pages affected, 1 open, 1 waived.
    $this->assertSame(2, $byRule['image-alt']['pages']);
    $this->assertSame(1, $byRule['image-alt']['open']);
    $this->assertSame(1, $byRule['image-alt']['waived']);
    // label: 1 page, 1 open.
    $this->assertSame(1, $byRule['label']['open']);
    $this->assertSame(0, $byRule['label']['waived']);
  }

  /**
   * Tests inaccessible nodes are excluded from aggregation.
   */
  public function testExcludesInaccessibleNodes(): void {
    $secret = Node::create(['type' => 'page', 'title' => 'secret', 'status' => 0]);
    $secret->save();
    $open = Node::create(['type' => 'page', 'title' => 'open', 'status' => 1]);
    $open->save();
    $this->addViolation($this->makeScan((int) $secret->id()), 'image-alt', 'critical', 'img');
    $this->addViolation($this->makeScan((int) $open->id()), 'label', 'serious', 'input');

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $rows = \Drupal::service('accessguard.violation_analytics')->byRule();

    $rules = array_column($rows, 'rule_id');
    $this->assertContains('label', $rules);
    $this->assertNotContains('image-alt', $rules);
  }

  /**
   * Tests the summary buckets every impact and reports the waived count.
   *
   * An impact outside the four standard severities (ScanRecorder stores
   * 'unknown' for those) must land in an "unknown" bucket, so the open total
   * always equals the sum of the per-severity lines in the report.
   */
  public function testSummaryBucketsEveryImpactAndCountsWaived(): void {
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    $scan = $this->makeScan((int) $node->id());
    $this->addViolation($scan, 'image-alt', 'critical', 'img');
    $this->addViolation($scan, 'custom-rule', 'unknown', 'div');
    $this->addViolation($scan, 'label', 'serious', 'input');
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver((int) $node->id(), 'label', 'input', 'accepted_risk', 'legacy form', 1);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $s = \Drupal::service('accessguard.violation_analytics')->summary();

    $this->assertSame(2, $s['open']);
    $this->assertSame(1, $s['waived'] ?? 0);
    $this->assertSame(1, $s['critical']);
    $this->assertSame(1, $s['unknown'] ?? 0);
    $this->assertSame(
      $s['open'],
      $s['critical'] + $s['serious'] + $s['moderate'] + $s['minor'] + ($s['unknown'] ?? 0),
      'The open total equals the sum of all severity buckets.',
    );
  }

  /**
   * Tests by-author buckets non-standard impacts instead of dropping them.
   */
  public function testByAuthorBucketsUnknownImpacts(): void {
    $author = $this->createUser(['access content']);
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    $scan = $this->makeScan((int) $node->id(), (int) $author->id());
    $this->addViolation($scan, 'custom-rule', 'unknown', 'div');

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $rows = \Drupal::service('accessguard.violation_analytics')->byAuthor();

    $this->assertCount(1, $rows);
    $this->assertSame(1, $rows[0]['unknown'] ?? 0);
  }

  /**
   * Tests authorless content yields no by-author row.
   *
   * Without this, a meaningless all-zero "Unknown" row renders and the
   * "no known author" empty state can never appear.
   */
  public function testByAuthorSkipsAuthorlessContent(): void {
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    $this->addViolation($this->makeScan((int) $node->id()), 'image-alt', 'critical', 'img');

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $this->assertSame([], \Drupal::service('accessguard.violation_analytics')->byAuthor());
  }

  /**
   * Tests the access-filtered context is not reused across account switches.
   *
   * The service memoizes its traversal per request; a second account in the
   * same process (account switching in cron/queue runs) must not be served
   * the first account's access-filtered data.
   */
  public function testAccessFilteringFollowsAccountSwitches(): void {
    $secret = Node::create(['type' => 'page', 'title' => 'secret', 'status' => 0]);
    $secret->save();
    $open = Node::create(['type' => 'page', 'title' => 'open', 'status' => 1]);
    $open->save();
    $this->addViolation($this->makeScan((int) $secret->id()), 'image-alt', 'critical', 'img');
    $this->addViolation($this->makeScan((int) $open->id()), 'label', 'serious', 'input');
    $analytics = \Drupal::service('accessguard.violation_analytics');

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $limited = array_column($analytics->byRule(), 'rule_id');
    $this->assertNotContains('image-alt', $limited);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content', 'bypass node access']));
    $admin = array_column($analytics->byRule(), 'rule_id');
    $this->assertContains('image-alt', $admin, 'A more privileged account sees its own access-filtered data.');
  }

  /**
   * Tests by-author counts open violations by severity per content author.
   */
  public function testByAuthorCountsOpenSeverities(): void {
    $author = $this->createUser(['access content']);
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    $scan = $this->makeScan((int) $node->id(), (int) $author->id());
    $this->addViolation($scan, 'image-alt', 'critical', 'img');
    $this->addViolation($scan, 'label', 'serious', 'input');

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $rows = \Drupal::service('accessguard.violation_analytics')->byAuthor();

    $this->assertCount(1, $rows);
    $this->assertSame((int) $author->id(), $rows[0]['uid']);
    $this->assertSame(1, $rows[0]['critical']);
    $this->assertSame(1, $rows[0]['serious']);
    $this->assertSame(1, $rows[0]['pages']);
  }

}
