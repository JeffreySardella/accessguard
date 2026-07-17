<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TrendChartBuilder's SVG generation.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class TrendChartBuilderTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'system'];

  /**
   * Builds a series row with the given per-severity counts.
   */
  private function row(string $date, int $c = 0, int $s = 0, int $m = 0, int $mi = 0, int $nr = 0): array {
    return [
      'date' => $date,
      'critical' => $c,
      'serious' => $s,
      'moderate' => $m,
      'minor' => $mi,
      'needs_review' => $nr,
      'total' => $c + $s + $m + $mi,
    ];
  }

  /**
   * Returns the chart builder service.
   */
  private function builder() {
    return \Drupal::service('accessguard.trend_chart_builder');
  }

  /**
   * Tests an empty series renders nothing.
   */
  public function testEmptySeriesRendersEmptyString(): void {
    $this->assertSame('', $this->builder()->render([]));
  }

  /**
   * Tests the accessibility scaffolding is present.
   */
  public function testSvgHasImageRoleTitleAndDesc(): void {
    $svg = $this->builder()->render([$this->row('2026-07-01', 2)]);
    $this->assertStringContainsString('role="img"', $svg);
    $this->assertStringContainsString('aria-labelledby', $svg);
    $this->assertStringContainsString('aria-describedby', $svg);
    $this->assertStringContainsString('<title id="ag-trend-title">', $svg);
    $this->assertStringContainsString('<desc id="ag-trend-desc">', $svg);
    // No focusable children inside the role=img chart.
    $this->assertStringNotContainsString('tabindex', $svg);
  }

  /**
   * Tests one marker per severity per day carries the point hooks.
   */
  public function testMarkersCarryDataHooksPerPoint(): void {
    $svg = $this->builder()->render([
      $this->row('2026-07-01', 1, 1, 1, 1, 1),
      $this->row('2026-07-02', 2, 0, 0, 0, 0),
      $this->row('2026-07-03', 0, 0, 0, 0, 0),
    ]);
    // 5 severities x 3 days = 15 data-point markers.
    $this->assertSame(15, substr_count($svg, 'class="ag-trend-point"'));
    $this->assertStringContainsString('data-date="2026-07-02"', $svg);
    $this->assertStringContainsString('data-series="Critical"', $svg);
    $this->assertStringContainsString('data-count="2"', $svg);
  }

  /**
   * Tests every severity contributes a coloured polyline.
   */
  public function testEachSeverityHasItsColour(): void {
    $svg = $this->builder()->render([$this->row('2026-07-01', 1, 1, 1, 1, 1)]);
    foreach (['#e34948', '#4a3aa7', '#eb6834', '#2a78d6', '#1baf7a'] as $hex) {
      $this->assertStringContainsString('stroke="' . $hex . '"', $svg);
    }
  }

  /**
   * Tests the legend names all five severities.
   */
  public function testLegendListsAllSeverities(): void {
    $svg = $this->builder()->render([$this->row('2026-07-01', 1)]);
    foreach (['Critical', 'Serious', 'Moderate', 'Minor', 'Needs review'] as $label) {
      $this->assertStringContainsString($label, $svg);
    }
  }

  /**
   * Tests distinct marker primitives are used (not colour alone).
   */
  public function testUsesDistinctMarkerShapes(): void {
    $svg = $this->builder()->render([$this->row('2026-07-01', 1, 1, 1, 1, 1)]);
    // circle, square+plus (rect), triangle+diamond (polygon).
    $this->assertStringContainsString('<circle', $svg);
    $this->assertStringContainsString('<rect', $svg);
    $this->assertStringContainsString('<polygon', $svg);
  }

  /**
   * Tests the y-axis scales to a nice max carrying the top label.
   */
  public function testYaxisShowsNiceMaxLabel(): void {
    // Max count 2 -> nice max 2 -> axis carries a '2' label.
    $svg = $this->builder()->render([$this->row('2026-07-01', 2)]);
    $this->assertStringContainsString('>2</text>', $svg);
  }

  /**
   * Tests a single-day series renders markers without crashing.
   */
  public function testSingleDayRenders(): void {
    $svg = $this->builder()->render([$this->row('2026-07-01', 3)]);
    $this->assertStringContainsString('<svg', $svg);
    $this->assertSame(5, substr_count($svg, 'class="ag-trend-point"'));
  }

}
