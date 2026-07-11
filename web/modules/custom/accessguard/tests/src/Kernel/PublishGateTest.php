<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the AccessguardGate publish-gating constraint.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class PublishGateTest extends KernelTestBase {

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
    // Kernel tests default to an anonymous session (uid 0), which has no
    // "bypass accessguard gating" permission — exactly what we want to test.
    // Ensure the anonymous role exists to grant the bypass permission.
    if (!Role::load(AccountInterface::ANONYMOUS_ROLE)) {
      Role::create(['id' => AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])->save();
    }
  }

  /**
   * Creates a completed scan for a node with the given critical count.
   */
  private function makeScan(int $nid, int $critical): void {
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
      'count_critical' => $critical,
    ]);
    $scan->save();
    for ($i = 0; $i < $critical; $i++) {
      \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
        'scan_id' => $scan->id(),
        'rule_id' => 'image-alt',
        'impact' => 'critical',
        'selector' => 'img',
      ])->save();
    }
  }

  /**
   * Creates a completed scan whose violations carry the given impacts.
   */
  private function makeScanWithImpacts(int $nid, array $impacts): void {
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
    ]);
    $scan->save();
    foreach ($impacts as $i => $impact) {
      \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
        'scan_id' => $scan->id(),
        'rule_id' => 'rule-' . $i,
        'impact' => $impact,
        'selector' => '.sel-' . $i,
      ])->save();
    }
  }

  /**
   * Counts the publish-gate constraint violations reported for a node.
   */
  private function countGateViolations(Node $node): int {
    $count = 0;
    foreach ($node->validate() as $v) {
      if (str_contains((string) $v->getMessage(), 'cannot be published')) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Tests that a first-time publish with no scan is not gated.
   */
  public function testUnscannedPublishTransitionIsNotGated(): void {
    // A node being published for the first time with no scan is not gated.
    $node = Node::create(['type' => 'page', 'title' => 'clean', 'status' => 0]);
    $node->save();
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }

  /**
   * Tests that publishing a node with a critical scan is gated.
   */
  public function testPublishTransitionWithCriticalScanIsGated(): void {
    // Draft node (stored unpublished) with a critical scan cannot be published.
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->setPublished();
    $this->assertSame(1, $this->countGateViolations($node));
  }

  /**
   * Tests that editing an already-published node is not gated.
   */
  public function testAlreadyPublishedNodeIsNotGatedOnEdit(): void {
    // Regression test for the deadlock: an already-published node with a bad
    // scan must remain saveable so an editor can save a fix.
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 1]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->set('title', 'fixed title');
    $this->assertSame(0, $this->countGateViolations($node));
  }

  /**
   * Tests that disabling the gate allows publishing regardless of scans.
   */
  public function testGateDisabledAllowsPublish(): void {
    \Drupal::configFactory()->getEditable('accessguard.settings')->set('gate_enabled', FALSE)->save();
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }

  /**
   * Tests that the bypass permission skips the gate.
   */
  public function testBypassPermissionSkipsGate(): void {
    // Give the anonymous role the bypass permission.
    $role = Role::load(AccountInterface::ANONYMOUS_ROLE);
    $role->grantPermission('bypass accessguard gating')->save();
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }

  /**
   * Tests the severity-threshold ranking, not just the default 'critical'.
   *
   * A 'serious' threshold must block serious (and critical) violations while
   * letting moderate ones publish — the ranking arithmetic is what makes the
   * gate's central setting mean anything.
   */
  public function testThresholdRankingBlocksAtAndAboveOnly(): void {
    \Drupal::configFactory()->getEditable('accessguard.settings')->set('gate_threshold', 'serious')->save();

    $moderate = Node::create(['type' => 'page', 'title' => 'moderate only', 'status' => 0]);
    $moderate->save();
    $this->makeScanWithImpacts((int) $moderate->id(), ['moderate', 'minor']);
    $moderate->setPublished();
    $this->assertSame(0, $this->countGateViolations($moderate));

    $serious = Node::create(['type' => 'page', 'title' => 'has serious', 'status' => 0]);
    $serious->save();
    $this->makeScanWithImpacts((int) $serious->id(), ['moderate', 'serious']);
    $serious->setPublished();
    $this->assertSame(1, $this->countGateViolations($serious));
  }

  /**
   * Tests that unknown-impact violations are gateable, not invisible.
   *
   * The axe engine can return a null impact (stored as 'unknown'); it ranks
   * alongside moderate so it blocks at a moderate threshold but not at the
   * default critical one.
   */
  public function testUnknownImpactRanksAsModerate(): void {
    $node = Node::create(['type' => 'page', 'title' => 'unknown impact', 'status' => 0]);
    $node->save();
    $this->makeScanWithImpacts((int) $node->id(), ['unknown']);

    // Default threshold (critical): unknown does not block.
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));

    // Moderate threshold: unknown blocks like a moderate violation would.
    \Drupal::configFactory()->getEditable('accessguard.settings')->set('gate_threshold', 'moderate')->save();
    $this->assertSame(1, $this->countGateViolations($node));
  }

  /**
   * Tests the unpublish → fix → re-scan → republish path.
   *
   * Regression test for the gate deadlock: a node unpublished with a failing
   * scan is blocked from republishing, but once a newer clean scan is
   * recorded (draft saves enqueue scans, and the worker scans unpublished
   * nodes with an access token), the gate opens again.
   */
  public function testCleanRescanUnblocksRepublish(): void {
    $node = Node::create(['type' => 'page', 'title' => 'was bad', 'status' => 1]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);

    // Unpublish, then try to republish: the stale failing scan blocks it.
    $node->setUnpublished();
    $node->save();
    $node->setPublished();
    $this->assertSame(1, $this->countGateViolations($node));

    // The editor fixes the content and a re-scan of the draft comes back
    // clean. Recording it must unblock the publish transition.
    $this->makeScan((int) $node->id(), 0);
    $this->assertSame(0, $this->countGateViolations($node));
  }

  /**
   * Tests that a waived violation no longer blocks publishing.
   */
  public function testWaivedViolationDoesNotBlockPublish(): void {
    $node = Node::create(['type' => 'page', 'title' => 'bad but waived', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    // Waive the image-alt/img violation for this node.
    \Drupal::entityTypeManager()->getStorage('accessguard_waiver')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'rule_id' => 'image-alt',
      'selector' => 'img',
      'status' => 'false_positive',
    ])->save();
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }

}
