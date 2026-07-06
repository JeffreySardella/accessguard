<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Controller\DashboardController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the detail page's empty states and scan-history pagination.
 *
 * @group accessguard
 */
class DashboardDetailTest extends KernelTestBase {

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
    // Burn uid 1 so test users are not the superuser.
    $this->createUser([]);
    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
  }

  /**
   * Tests that an unscanned node says so instead of implying a clean scan.
   */
  public function testUnscannedNodeShowsNotScannedEmptyState(): void {
    $node = Node::create(['type' => 'page', 'title' => 'x', 'status' => 1]);
    $node->save();

    $controller = DashboardController::create($this->container);
    $build = $controller->detail($node);

    $this->assertStringContainsString('not been scanned', (string) $build['violations']['#empty']);
  }

  /**
   * Tests that a scanned-clean node keeps the "no violations" empty state.
   */
  public function testCleanScanShowsNoViolationsEmptyState(): void {
    $node = Node::create(['type' => 'page', 'title' => 'x', 'status' => 1]);
    $node->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
    ])->save();

    $controller = DashboardController::create($this->container);
    $build = $controller->detail($node);

    $this->assertStringContainsString('No violations', (string) $build['violations']['#empty']);
  }

  /**
   * Tests that a long scan history is paginated at 25 rows per page.
   */
  public function testScanHistoryIsPaginated(): void {
    $node = Node::create(['type' => 'page', 'title' => 'x', 'status' => 1]);
    $node->save();
    $storage = \Drupal::entityTypeManager()->getStorage('accessguard_scan');
    for ($i = 0; $i < 30; $i++) {
      $storage->create([
        'target_entity_type' => 'node',
        'target_entity_id' => $node->id(),
        'status' => 'complete',
        'created' => 1000000 + $i,
      ])->save();
    }

    $controller = DashboardController::create($this->container);
    $build = $controller->detail($node);

    $this->assertCount(25, $build['history']['#rows']);
    $this->assertSame('pager', $build['history_pager']['#type']);
  }

}
