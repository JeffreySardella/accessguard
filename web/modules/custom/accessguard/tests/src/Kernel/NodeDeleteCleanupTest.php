<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests that deleting a node cleans up its scans and violations.
 *
 * @group accessguard
 */
class NodeDeleteCleanupTest extends KernelTestBase {

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
   * Tests that deleting a node removes its scans and violations.
   */
  public function testDeletingNodeRemovesItsScansAndViolations(): void {
    $node = Node::create(['type' => 'page', 'title' => 'x', 'status' => 1]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
    ]);
    $scan->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'image-alt',
      'impact' => 'critical',
    ])->save();

    $node->delete();

    $scans = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->loadMultiple();
    $violations = \Drupal::entityTypeManager()->getStorage('accessguard_violation')->loadMultiple();
    $this->assertCount(0, $scans);
    $this->assertCount(0, $violations);
  }

}
