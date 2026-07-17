<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Drush\Commands\AccessguardCommands;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests the drush accessguard:scan command paths.
 *
 * The README quick-start drives the whole pipeline through this command, so
 * both the queue path and the --now path need coverage.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class AccessguardCommandsTest extends KernelTestBase {

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
   * Builds the command instance with quiet console I/O.
   */
  private function createCommand(): AccessguardCommands {
    $command = AccessguardCommands::create($this->container);
    $command->setInput(new ArrayInput([]));
    $command->setOutput(new NullOutput());
    return $command;
  }

  /**
   * Tests that the default (queue) path enqueues with the manual trigger.
   */
  public function testQueuePathEnqueuesItem(): void {
    $this->createCommand()->scan(42);

    $item = \Drupal::queue('accessguard_scan_queue')->claimItem();
    $this->assertNotFalse($item);
    $this->assertSame(42, $item->data['nid']);
    $this->assertSame('manual', $item->data['trigger']);
  }

  /**
   * Tests that --now scans immediately and records the result.
   */
  public function testNowPathScansAndRecords(): void {
    $node = Node::create(['type' => 'page', 'title' => 'scan me', 'status' => 1]);
    $node->save();
    $runner = $this->createMock(ScanRunner::class);
    $runner->method('scan')->willReturn([
      'url' => 'http://example.test/node/' . $node->id(),
      'violations' => [
        ['ruleId' => 'image-alt', 'impact' => 'critical', 'selector' => 'img'],
      ],
    ]);
    $this->container->set('accessguard.scan_runner', $runner);

    $this->createCommand()->scan((int) $node->id(), ['now' => TRUE]);

    $scanStorage = \Drupal::entityTypeManager()->getStorage('accessguard_scan');
    $ids = $scanStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_id', $node->id())
      ->execute();
    $this->assertCount(1, $ids);
    $scan = $scanStorage->load(reset($ids));
    $this->assertSame('manual', $scan->get('triggered_by')->value);
    $this->assertSame(1, (int) $scan->get('count_critical')->value);
  }

  /**
   * Tests that --now on a missing node fails loudly.
   */
  public function testNowPathRejectsMissingNode(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->createCommand()->scan(999, ['now' => TRUE]);
  }

  /**
   * Creates a published node with a completed scan carrying violations.
   *
   * @return int
   *   The node id.
   */
  private function makeScannedNode(string $title, array $impacts, bool $published = TRUE): int {
    $node = Node::create(['type' => 'page', 'title' => $title, 'status' => $published ? 1 : 0]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
    ]);
    $scan->save();
    foreach ($impacts as $i => $impact) {
      \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
        'scan_id' => $scan->id(),
        'rule_id' => 'image-alt',
        'impact' => $impact,
        'selector' => 'img.v' . $i,
      ])->save();
    }
    return (int) $node->id();
  }

  /**
   * Tests the gate fails (exit 1) when a published node has a blocker.
   */
  public function testGateFailsOnBlockingViolation(): void {
    $clean = $this->makeScannedNode('clean', []);
    $dirty = $this->makeScannedNode('dirty', ['critical']);

    $result = $this->createCommand()->gate();

    $this->assertSame(1, $result->getExitCode());
    $rows = $result->getOutputData()->getArrayCopy();
    $byNid = array_column($rows, NULL, 'nid');
    $this->assertSame(0, $byNid[$clean]['blocking']);
    $this->assertSame(1, $byNid[$dirty]['blocking']);
  }

  /**
   * Tests waived violations pass the gate (exit 0).
   */
  public function testGatePassesWhenViolationWaived(): void {
    $nid = $this->makeScannedNode('waived', ['critical']);
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver($nid, 'image-alt', 'img.v0', 'false_positive', 'decorative', 1);

    $result = $this->createCommand()->gate();

    $this->assertSame(0, $result->getExitCode());
  }

  /**
   * Tests unpublished nodes are excluded from the all-nodes evaluation.
   */
  public function testGateIgnoresUnpublishedNodes(): void {
    $this->makeScannedNode('draft', ['critical'], FALSE);

    $result = $this->createCommand()->gate();

    $this->assertSame(0, $result->getExitCode());
    $this->assertCount(0, $result->getOutputData()->getArrayCopy());
  }

  /**
   * Tests a single-nid evaluation gates that node only, published or not.
   */
  public function testGateSingleNode(): void {
    $this->makeScannedNode('other-dirty', ['critical']);
    $draft = $this->makeScannedNode('draft-dirty', ['critical'], FALSE);

    $result = $this->createCommand()->gate($draft);

    $this->assertSame(1, $result->getExitCode());
    $rows = $result->getOutputData()->getArrayCopy();
    $this->assertCount(1, $rows);
    $this->assertSame($draft, $rows[0]['nid']);
  }

  /**
   * Tests a single-nid evaluation of an unknown node fails loudly.
   */
  public function testGateRejectsMissingNode(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->createCommand()->gate(999);
  }

  /**
   * Tests the gate command exempts nodes of excluded content types.
   */
  public function testGateSkipsExcludedType(): void {
    NodeType::create(['type' => 'internal', 'name' => 'Internal'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')
      ->save();
    $node = Node::create(['type' => 'internal', 'title' => 'excluded dirty', 'status' => 1]);
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
      'selector' => 'img',
    ])->save();

    $result = $this->createCommand()->gate();

    $this->assertSame(0, $result->getExitCode());
    $this->assertCount(0, $result->getOutputData()->getArrayCopy());
  }

}
