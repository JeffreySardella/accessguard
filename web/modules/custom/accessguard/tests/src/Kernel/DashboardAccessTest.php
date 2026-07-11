<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Controller\DashboardController;
use Drupal\Core\Link;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests node-access enforcement and triage-link visibility on the dashboard.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class DashboardAccessTest extends KernelTestBase {

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
  }

  /**
   * Creates a completed scan with one critical image-alt violation.
   */
  private function makeScanWithViolation(int $nid): void {
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
      'count_critical' => 1,
    ]);
    $scan->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'image-alt',
      'impact' => 'critical',
      'selector' => 'img',
    ])->save();
  }

  /**
   * Tests the detail route denies users who cannot view the node.
   */
  public function testDetailRouteRequiresNodeViewAccess(): void {
    $secret = Node::create(['type' => 'page', 'title' => 'secret', 'status' => 0]);
    $secret->save();
    $open = Node::create(['type' => 'page', 'title' => 'open', 'status' => 1]);
    $open->save();
    $viewer = $this->createUser(['view accessguard reports', 'access content']);

    $accessManager = \Drupal::service('access_manager');
    $this->assertFalse($accessManager->checkNamedRoute('accessguard.node_detail', ['node' => $secret->id()], $viewer));
    $this->assertTrue($accessManager->checkNamedRoute('accessguard.node_detail', ['node' => $open->id()], $viewer));
  }

  /**
   * Tests the waive route requires both triage permission and node access.
   */
  public function testWaiveRouteRequiresTriageAndNodeAccess(): void {
    $secret = Node::create(['type' => 'page', 'title' => 'secret', 'status' => 0]);
    $secret->save();
    $open = Node::create(['type' => 'page', 'title' => 'open', 'status' => 1]);
    $open->save();
    $viewer = $this->createUser(['view accessguard reports', 'access content']);
    $triager = $this->createUser(['triage accessguard violations', 'access content']);

    $accessManager = \Drupal::service('access_manager');
    $this->assertFalse($accessManager->checkNamedRoute('accessguard.waive', ['node' => $open->id()], $viewer));
    $this->assertTrue($accessManager->checkNamedRoute('accessguard.waive', ['node' => $open->id()], $triager));
    $this->assertFalse($accessManager->checkNamedRoute('accessguard.waive', ['node' => $secret->id()], $triager));
  }

  /**
   * Tests the un-waive route exists and requires the triage permission.
   */
  public function testUnwaiveRouteRequiresTriagePermission(): void {
    $open = Node::create(['type' => 'page', 'title' => 'open', 'status' => 1]);
    $open->save();
    $viewer = $this->createUser(['view accessguard reports', 'access content']);
    $triager = $this->createUser(['triage accessguard violations', 'access content']);

    $accessManager = \Drupal::service('access_manager');
    $this->assertFalse($accessManager->checkNamedRoute('accessguard.unwaive', ['node' => $open->id()], $viewer));
    $this->assertTrue($accessManager->checkNamedRoute('accessguard.unwaive', ['node' => $open->id()], $triager));
  }

  /**
   * Tests the overview only lists nodes the current user can view.
   */
  public function testOverviewExcludesInaccessibleNodes(): void {
    $secret = Node::create(['type' => 'page', 'title' => 'secret page', 'status' => 0]);
    $secret->save();
    $open = Node::create(['type' => 'page', 'title' => 'open page', 'status' => 1]);
    $open->save();
    $this->makeScanWithViolation((int) $secret->id());
    $this->makeScanWithViolation((int) $open->id());

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $controller = DashboardController::create($this->container);
    $build = $controller->overview();

    $this->assertCount(1, $build['table']['#rows']);
    $this->assertStringContainsString('Pages scanned: 1', (string) $build['summary']['#items'][0]);
    $this->assertStringContainsString('Critical: 1,', (string) $build['summary']['#items'][2]);
  }

  /**
   * Tests the CSV export only includes nodes the current user can view.
   */
  public function testCsvExcludesInaccessibleNodes(): void {
    $secret = Node::create(['type' => 'page', 'title' => 'secret page', 'status' => 0]);
    $secret->save();
    $open = Node::create(['type' => 'page', 'title' => 'open page', 'status' => 1]);
    $open->save();
    $this->makeScanWithViolation((int) $secret->id());
    $this->makeScanWithViolation((int) $open->id());

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $controller = DashboardController::create($this->container);
    $csv = $controller->exportCsv()->getContent();

    $this->assertStringContainsString('open page', $csv);
    $this->assertStringNotContainsString('secret page', $csv);
  }

  /**
   * Tests the Waive link only renders for users with the triage permission.
   */
  public function testWaiveLinkHiddenWithoutTriagePermission(): void {
    $open = Node::create(['type' => 'page', 'title' => 'open page', 'status' => 1]);
    $open->save();
    $this->makeScanWithViolation((int) $open->id());

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $controller = DashboardController::create($this->container);
    $build = $controller->detail($open);
    $statusCell = $build['violations']['#rows'][0][3];
    $this->assertNotInstanceOf(Link::class, $statusCell);
    $this->assertSame('Open', (string) $statusCell);

    $this->setCurrentUser($this->createUser([
      'view accessguard reports',
      'triage accessguard violations',
      'access content',
    ]));
    $controller = DashboardController::create($this->container);
    $build = $controller->detail($open);
    $statusCell = $build['violations']['#rows'][0][3];
    $this->assertInstanceOf(Link::class, $statusCell);
  }

  /**
   * Tests waived rows offer an un-waive link to triagers only.
   */
  public function testUnwaiveLinkShownForTriagersOnWaivedRows(): void {
    $open = Node::create(['type' => 'page', 'title' => 'open page', 'status' => 1]);
    $open->save();
    $this->makeScanWithViolation((int) $open->id());
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver((int) $open->id(), 'image-alt', 'img', 'false_positive', 'decorative', 1);

    // A triager sees the un-waive link inside the status cell.
    $this->setCurrentUser($this->createUser([
      'view accessguard reports',
      'triage accessguard violations',
      'access content',
    ]));
    $controller = DashboardController::create($this->container);
    $build = $controller->detail($open);
    $statusCell = $build['violations']['#rows'][0][3];
    $this->assertIsArray($statusCell);
    $this->assertSame('accessguard.unwaive', $statusCell['data']['unwaive']['#url']->getRouteName());

    // A plain viewer sees only the waived status text.
    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $controller = DashboardController::create($this->container);
    $build = $controller->detail($open);
    $statusCell = $build['violations']['#rows'][0][3];
    $this->assertIsNotArray($statusCell);
    $this->assertStringContainsString('Waived', (string) $statusCell);
  }

}
