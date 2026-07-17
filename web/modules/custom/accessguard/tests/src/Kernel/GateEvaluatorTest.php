<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests GateEvaluator's blocking-violation policy.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class GateEvaluatorTest extends KernelTestBase {

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
    $this->installConfig(['field', 'filter', 'node', 'accessguard']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Creates a published node and returns its id.
   */
  private function makeNode(): int {
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Creates a completed scan carrying the given violations.
   *
   * @param int $nid
   *   Target node id.
   * @param array $violations
   *   Items of [impact, selector, result_type] — result_type optional.
   */
  private function makeScan(int $nid, array $violations): int {
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
    ]);
    $scan->save();
    foreach ($violations as $v) {
      \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
        'scan_id' => $scan->id(),
        'rule_id' => 'image-alt',
        'impact' => $v[0],
        'selector' => $v[1],
        'result_type' => $v[2] ?? 'violation',
      ])->save();
    }
    return (int) $scan->id();
  }

  /**
   * Tests only violations at or above the threshold rank block.
   */
  public function testThresholdRankFiltersBlocking(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img'], ['serious', 'input'], ['minor', 'p']]);
    \Drupal::configFactory()->getEditable('accessguard.settings')
      ->set('gate_threshold', 'serious')->save();

    $this->assertSame(2, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

  /**
   * Tests waived fingerprints do not block.
   */
  public function testWaivedViolationDoesNotBlock(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img'], ['critical', 'div']]);
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver($nid, 'image-alt', 'img', 'false_positive', 'decorative', 1);

    $this->assertSame(1, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

  /**
   * Tests needs-review results are excluded unless configured in.
   */
  public function testNeedsReviewRespectsConfig(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img'], ['critical', 'div', 'needs_review']]);
    $evaluator = \Drupal::service('accessguard.gate_evaluator');

    $this->assertSame(1, $evaluator->blockingCount($nid));

    \Drupal::configFactory()->getEditable('accessguard.settings')
      ->set('gate_includes_needs_review', TRUE)->save();
    $this->assertSame(2, $evaluator->blockingCount($nid));
  }

  /**
   * Tests unknown impact ranks alongside moderate (gateable, not invisible).
   */
  public function testUnknownImpactBlocksAtModerateThreshold(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['weird-impact', 'div']]);
    $config = \Drupal::configFactory()->getEditable('accessguard.settings');

    $config->set('gate_threshold', 'moderate')->save();
    $this->assertSame(1, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));

    $config->set('gate_threshold', 'serious')->save();
    $this->assertSame(0, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

  /**
   * Tests only the LATEST scan is evaluated, and never-scanned yields NULL.
   */
  public function testLatestScanOnlyAndNullWhenUnscanned(): void {
    $nid = $this->makeNode();
    $this->assertNull(\Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));

    $this->makeScan($nid, [['critical', 'img']]);
    $this->makeScan($nid, []);
    $this->assertSame(0, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

  /**
   * Tests an excluded content type yields NULL regardless of scan data.
   */
  public function testExcludedTypeYieldsNull(): void {
    NodeType::load('page')->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')->save();
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img']]);

    $this->assertNull(\Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

}
