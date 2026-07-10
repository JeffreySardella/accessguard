<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that ScanRecorder persists a scan result and its violations.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class ScanRecorderTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'user', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('accessguard_scan');
    $this->installEntitySchema('accessguard_violation');
    $this->installEntitySchema('user');
  }

  /**
   * Tests that record() creates the scan entity and its violation entities.
   */
  public function testRecordCreatesScanAndViolations(): void {
    $result = [
      'url' => 'http://x/node/5',
      'violations' => [
      [
        'ruleId' => 'image-alt',
        'impact' => 'critical',
        'wcagCriterion' => 'wcag2a',
        'selector' => 'img',
        'html' => '<img>',
        'helpUrl' => 'http://h',
      ],
      [
        'ruleId' => 'link-name',
        'impact' => 'serious',
        'wcagCriterion' => 'wcag2a',
        'selector' => 'a',
        'html' => '<a>',
        'helpUrl' => 'http://h',
      ],
      ],
    ];
    $recorder = $this->container->get('accessguard.scan_recorder');
    $scan = $recorder->record('node', 5, NULL, 'manual', $result);

    $this->assertSame('complete', $scan->get('status')->value);
    $this->assertSame(1, (int) $scan->get('count_critical')->value);
    $this->assertSame(1, (int) $scan->get('count_serious')->value);
    $violations = \Drupal::entityTypeManager()->getStorage('accessguard_violation')
      ->loadByProperties(['scan_id' => $scan->id()]);
    $this->assertCount(2, $violations);
  }

}
