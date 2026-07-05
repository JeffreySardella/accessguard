<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * @group accessguard
 */
class PublishGateTest extends KernelTestBase {

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
    // Kernel tests default to an anonymous session (uid 0), which has no
    // "bypass accessguard gating" permission — exactly what we want to test.
    // Ensure the anonymous role exists to grant the bypass permission.
    if (!\Drupal\user\Entity\Role::load(\Drupal\Core\Session\AccountInterface::ANONYMOUS_ROLE)) {
      \Drupal\user\Entity\Role::create(['id' => \Drupal\Core\Session\AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])->save();
    }
  }

  private function makeScan(int $nid, int $critical): void {
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
      'count_critical' => $critical,
    ])->save();
  }

  private function countGateViolations(Node $node): int {
    $count = 0;
    foreach ($node->validate() as $v) {
      if (str_contains((string) $v->getMessage(), 'cannot be published')) {
        $count++;
      }
    }
    return $count;
  }

  public function testUnscannedPublishTransitionIsNotGated(): void {
    // A node being published for the first time with no scan is not gated.
    $node = Node::create(['type' => 'page', 'title' => 'clean', 'status' => 0]);
    $node->save();
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }

  public function testPublishTransitionWithCriticalScanIsGated(): void {
    // Draft node (stored unpublished) with a critical scan cannot be published.
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->setPublished();
    $this->assertSame(1, $this->countGateViolations($node));
  }

  public function testAlreadyPublishedNodeIsNotGatedOnEdit(): void {
    // Regression test for the deadlock: an already-published node with a bad
    // scan must remain saveable so an editor can save a fix.
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 1]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->set('title', 'fixed title');
    $this->assertSame(0, $this->countGateViolations($node));
  }

  public function testGateDisabledAllowsPublish(): void {
    \Drupal::configFactory()->getEditable('accessguard.settings')->set('gate_enabled', FALSE)->save();
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }

  public function testBypassPermissionSkipsGate(): void {
    // Give the anonymous role the bypass permission.
    $role = \Drupal\user\Entity\Role::load(\Drupal\Core\Session\AccountInterface::ANONYMOUS_ROLE);
    $role->grantPermission('bypass accessguard gating')->save();
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }

}
