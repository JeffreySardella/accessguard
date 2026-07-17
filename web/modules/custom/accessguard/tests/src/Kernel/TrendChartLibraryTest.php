<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the trend_chart asset library is discoverable with its assets.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class TrendChartLibraryTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'system'];

  /**
   * Tests the library exists and declares the chart CSS and JS.
   */
  public function testLibraryDeclaresCssAndJs(): void {
    $library = \Drupal::service('library.discovery')->getLibraryByName('accessguard', 'trend_chart');
    $this->assertNotEmpty($library, 'The accessguard/trend_chart library is defined.');

    $css = array_column($library['css'], 'data');
    $js = array_column($library['js'], 'data');
    // Drupal's LibraryDiscoveryParser rewrites asset paths to be
    // module-relative (modules/custom/accessguard/...), so match the suffix
    // rather than the bare path.
    $this->assertNotEmpty(array_filter($css, fn ($p) => str_ends_with($p, 'css/trend-chart.css')));
    $this->assertNotEmpty(array_filter($js, fn ($p) => str_ends_with($p, 'js/trend-chart.js')));
  }

}
