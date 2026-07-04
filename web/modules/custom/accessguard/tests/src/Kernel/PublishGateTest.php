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
  }

  private function makeScan(int $nid, int $critical): void {
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
      'count_critical' => $critical,
    ])->save();
  }

  public function testUnscannedPublishedNodeIsNotGated(): void {
    $node = Node::create(['type' => 'page', 'title' => 'clean', 'status' => 1]);
    $node->save();
    // No scan exists -> no AccessguardGate violation.
    $count = 0;
    foreach ($node->validate() as $v) {
      if (str_contains((string) $v->getMessage(), 'cannot be published')) {
        $count++;
      }
    }
    $this->assertSame(0, $count);
  }

  public function testPublishedNodeWithCriticalScanIsGated(): void {
    $node = Node::create(['type' => 'page', 'title' => 'bad', 'status' => 1]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $count = 0;
    foreach ($node->validate() as $v) {
      if (str_contains((string) $v->getMessage(), 'cannot be published')) {
        $count++;
      }
    }
    $this->assertSame(1, $count);
  }

}
