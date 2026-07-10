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

}
