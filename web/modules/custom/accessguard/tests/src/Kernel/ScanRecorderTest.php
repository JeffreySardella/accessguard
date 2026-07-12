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

  /**
   * Tests that needs-review items are recorded and counted separately.
   */
  public function testNeedsReviewRecordedSeparately(): void {
    $result = [
      'url' => 'http://x/node/8',
      'violations' => [
        ['ruleId' => 'image-alt', 'impact' => 'critical', 'selector' => 'img'],
      ],
      'needsReview' => [
        ['ruleId' => 'color-contrast', 'impact' => 'serious', 'selector' => '.hero'],
        ['ruleId' => 'color-contrast', 'impact' => NULL, 'selector' => '.banner'],
      ],
    ];
    $scan = $this->container->get('accessguard.scan_recorder')->record('node', 8, NULL, 'manual', $result);

    $this->assertSame(1, (int) $scan->get('count_critical')->value);
    $this->assertSame(2, (int) $scan->get('count_needs_review')->value);

    $vStorage = \Drupal::entityTypeManager()->getStorage('accessguard_violation');
    $all = $vStorage->loadByProperties(['scan_id' => $scan->id()]);
    $this->assertCount(3, $all);
    $confirmed = $vStorage->loadByProperties(['scan_id' => $scan->id(), 'result_type' => 'violation']);
    $review = $vStorage->loadByProperties(['scan_id' => $scan->id(), 'result_type' => 'needs_review']);
    $this->assertCount(1, $confirmed);
    $this->assertCount(2, $review);
  }

  /**
   * Tests that the axe engine version is persisted on the scan.
   */
  public function testEngineVersionRecorded(): void {
    $result = [
      'url' => 'http://x/node/2',
      'engineVersion' => '4.12.1',
      'violations' => [],
    ];
    $scan = $this->container->get('accessguard.scan_recorder')->record('node', 2, NULL, 'manual', $result);
    $this->assertSame('4.12.1', $scan->get('engine_version')->value);
  }

  /**
   * Tests that the scan-access token is stripped from the stored URL.
   *
   * The token is a live bearer credential; keeping it in the stored URL would
   * leak it into the CSV and PDF audit exports that render that URL.
   */
  public function testScanTokenStrippedFromStoredUrl(): void {
    $result = [
      'url' => 'http://site/node/9?accessguard-scan-token=1799999999.abcDEF123&keep=1',
      'violations' => [],
    ];
    $scan = $this->container->get('accessguard.scan_recorder')->record('node', 9, NULL, 'manual', $result);
    $stored = $scan->get('url')->value;
    $this->assertStringNotContainsString('accessguard-scan-token', $stored);
    // Unrelated query args and the path are preserved.
    $this->assertStringContainsString('/node/9', $stored);
    $this->assertStringContainsString('keep=1', $stored);
  }

  /**
   * Tests that an oversized selector is capped, not left to abort the scan.
   *
   * On strict SQL backends an over-2048-char selector would throw SQLSTATE
   * 22001 and roll back the whole transaction; capping keeps the scan intact.
   */
  public function testOversizedSelectorIsCapped(): void {
    $longSelector = str_repeat('div > ', 500) . 'img';
    $this->assertGreaterThan(2048, strlen($longSelector));
    $result = [
      'url' => 'http://x/node/3',
      'violations' => [
      [
        'ruleId' => 'image-alt',
        'impact' => 'critical',
        'wcagCriterion' => 'wcag2a',
        'selector' => $longSelector,
        'html' => '<img>',
        'helpUrl' => 'http://h',
      ],
      ],
    ];
    $scan = $this->container->get('accessguard.scan_recorder')->record('node', 3, NULL, 'manual', $result);
    $violations = \Drupal::entityTypeManager()->getStorage('accessguard_violation')
      ->loadByProperties(['scan_id' => $scan->id()]);
    $this->assertCount(1, $violations);
    $stored = reset($violations)->get('selector')->value;
    $this->assertSame(2048, mb_strlen($stored));
  }

}
