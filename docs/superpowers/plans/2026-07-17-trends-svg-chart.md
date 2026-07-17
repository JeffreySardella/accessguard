# Trends inline-SVG chart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dependency-free inline-SVG line chart above the Trends tab's existing table, plotting the five per-severity daily counts.

**Architecture:** A new pure `TrendChartBuilder` service turns `TrendBuilder::dailySeries()` into an `<svg>` string (all geometry, axes, five polylines with distinct marker shapes, legend, `role="img"` + `<title>`/`<desc>`). A new `accessguard/trend_chart` library (CSS + a hover-tooltip JS behaviour) styles and enhances it. `AnalyticsController::trends()` renders the SVG above the unchanged note and table via `inline_template` and attaches the library. Spec: `docs/superpowers/specs/2026-07-17-trends-svg-chart-design.md`.

**Tech Stack:** Drupal 11 custom module (`web/modules/custom/accessguard`), PHPUnit kernel tests, phpcs (Drupal + DrupalPractice), vanilla JS `Drupal.behaviors` (core/drupal + core/once), plain CSS.

## Global Constraints

- All commands run from the repo root on the host; PHP runs inside DDEV via `ddev exec`.
- Kernel tests: `ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core <path>"`. Run through the Bash tool — PowerShell mangles the quoting.
- phpcs: `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo` (must stay clean). Note: phpcs is scoped to php/module/inc/install/yml — it does NOT lint `.css`/`.js`, but write them cleanly anyway.
- Validated severity palette (exact hex, do not substitute): critical `#e34948`, serious `#4a3aa7`, moderate `#eb6834`, minor `#2a78d6`, needs-review `#1baf7a`.
- Marker shape per severity (primary non-colour differentiator): critical=circle, serious=square, moderate=triangle, minor=diamond, needs-review=plus.
- Accessibility: the SVG is `role="img"` with `<title>`/`<desc>`; the data table stays as the text alternative; NO focusable children inside the SVG. The JS is a pointer-hover enhancement only.
- Commit messages: `feat(trends): ...` / `test(trends): ...` / `docs(trends): ...`, ending with a blank line then `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Comments state constraints/rationale, not narration; match existing files' density.
- Light-only admin surface (Claro); no dark-mode variant.

---

### Task 1: `TrendChartBuilder` service

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/TrendChartBuilder.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml` (append service)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/TrendChartBuilderTest.php`

**Interfaces:**
- Consumes: `dailySeries()` rows — `array{date:string, critical:int, serious:int, moderate:int, minor:int, needs_review:int, total:int}`, oldest first.
- Produces: service `accessguard.trend_chart_builder`, class `Drupal\accessguard\Service\TrendChartBuilder` with `render(array $series): string` — returns a complete `<svg>…</svg>` string, or `''` for an empty series. Markers carry `class="ag-trend-point"` and `data-date`/`data-series`/`data-count` (Task 2's JS and Task 3's test rely on these exact names).

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Kernel/TrendChartBuilderTest.php`:

```php
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
  public function testYAxisShowsNiceMaxLabel(): void {
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/TrendChartBuilderTest.php"
```
Expected: FAIL — `ServiceNotFoundException` for `accessguard.trend_chart_builder`.

- [ ] **Step 3: Write the implementation**

Create `web/modules/custom/accessguard/src/Service/TrendChartBuilder.php`:

```php
<?php

namespace Drupal\accessguard\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Renders the daily severity series as an inline SVG line chart.
 *
 * A pure series->markup transform (no entity or config access), so it
 * unit-tests in isolation. The chart is role="img" with a title/desc
 * summary; the Trends table remains the full text alternative, so the SVG
 * deliberately has no focusable children. Colour is a secondary cue — each
 * severity also has a distinct marker shape.
 */
class TrendChartBuilder {

  use StringTranslationTrait;

  // Internal coordinate space; the SVG is CSS-scaled to the container width.
  private const W = 720;
  private const H = 320;
  private const PLOT_LEFT = 44;
  private const PLOT_RIGHT = 704;
  private const PLOT_TOP = 16;
  private const PLOT_BOTTOM = 250;

  /**
   * Severity rows: key => [label, colour, marker shape].
   *
   * Order is the fixed severity order and also the legend order. Colours are
   * the dataviz-validated palette; shapes are the primary differentiator.
   */
  private const SERIES = [
    'critical' => ['Critical', '#e34948', 'circle'],
    'serious' => ['Serious', '#4a3aa7', 'square'],
    'moderate' => ['Moderate', '#eb6834', 'triangle'],
    'minor' => ['Minor', '#2a78d6', 'diamond'],
    'needs_review' => ['Needs review', '#1baf7a', 'plus'],
  ];

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Renders the series as an SVG string ('' when the series is empty).
   */
  public function render(array $series): string {
    $n = count($series);
    if ($n === 0) {
      return '';
    }
    $max = $this->niceMax($series);

    $title = (string) $this->t('Accessibility violations over time');
    $desc = (string) $this->t('Per-severity daily counts across @n scan-day(s), @first to @last. Full figures in the table below.', [
      '@n' => $n,
      '@first' => $series[0]['date'],
      '@last' => $series[$n - 1]['date'],
    ]);

    $svg = sprintf(
      '<svg class="ag-trend-chart" role="img" viewBox="0 0 %d %d" preserveAspectRatio="xMidYMid meet" aria-labelledby="ag-trend-title" aria-describedby="ag-trend-desc">',
      self::W,
      self::H
    );
    $svg .= '<title id="ag-trend-title">' . Html::escape($title) . '</title>';
    $svg .= '<desc id="ag-trend-desc">' . Html::escape($desc) . '</desc>';
    $svg .= $this->axes($series, $max);
    foreach (self::SERIES as $key => [$label, $colour, $shape]) {
      $svg .= $this->line($series, $key, $colour, $max);
      $svg .= $this->markers($series, $key, $colour, $shape, (string) $this->t($label), $max);
    }
    $svg .= $this->legend();
    $svg .= '</svg>';
    return $svg;
  }

  /**
   * Smallest "nice" (1/2/5 x 10^k) ceiling at or above the largest count.
   */
  private function niceMax(array $series): int {
    $raw = 0;
    foreach ($series as $row) {
      foreach (array_keys(self::SERIES) as $key) {
        $raw = max($raw, (int) $row[$key]);
      }
    }
    if ($raw <= 0) {
      // A flat, all-zero series still needs a positive axis range.
      return 1;
    }
    $pow = 10 ** (int) floor(log10($raw));
    foreach ([1, 2, 5, 10] as $m) {
      if ($m * $pow >= $raw) {
        return (int) ($m * $pow);
      }
    }
    return (int) (10 * $pow);
  }

  /**
   * Number of y gridline intervals that keep integer labels.
   */
  private function gridSteps(int $max): int {
    foreach ([5, 4, 3, 2] as $g) {
      if ($max % $g === 0) {
        return $g;
      }
    }
    return 1;
  }

  /**
   * The x pixel for the i-th of n points (centred when there is only one).
   */
  private function x(int $i, int $n): float {
    if ($n === 1) {
      return (self::PLOT_LEFT + self::PLOT_RIGHT) / 2;
    }
    return self::PLOT_LEFT + ($i * (self::PLOT_RIGHT - self::PLOT_LEFT)) / ($n - 1);
  }

  /**
   * The y pixel for a count against the axis max.
   */
  private function y(int $value, int $max): float {
    $height = self::PLOT_BOTTOM - self::PLOT_TOP;
    return self::PLOT_BOTTOM - ($value / $max) * $height;
  }

  /**
   * Gridlines with integer y labels and thinned x date labels.
   */
  private function axes(array $series, int $max): string {
    $out = '';
    $steps = $this->gridSteps($max);
    for ($k = 0; $k <= $steps; $k++) {
      $value = (int) ($max * $k / $steps);
      $yy = $this->y($value, $max);
      $out .= sprintf(
        '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e1e0d9" stroke-width="1"/>',
        self::PLOT_LEFT,
        $yy,
        self::PLOT_RIGHT,
        $yy
      );
      $out .= sprintf(
        '<text x="%d" y="%.1f" text-anchor="end" font-size="11" fill="#898781">%d</text>',
        self::PLOT_LEFT - 6,
        $yy + 3,
        $value
      );
    }
    // At most ~6 x labels so dates do not collide.
    $n = count($series);
    $every = (int) max(1, ceil($n / 6));
    foreach ($series as $i => $row) {
      if ($i % $every !== 0 && $i !== $n - 1) {
        continue;
      }
      $out .= sprintf(
        '<text x="%.1f" y="%d" text-anchor="middle" font-size="11" fill="#898781">%s</text>',
        $this->x($i, $n),
        self::PLOT_BOTTOM + 16,
        Html::escape(substr((string) $row['date'], 5))
      );
    }
    return $out;
  }

  /**
   * The polyline for one severity across all days.
   */
  private function line(array $series, string $key, string $colour, int $max): string {
    $n = count($series);
    $points = [];
    foreach ($series as $i => $row) {
      $points[] = sprintf('%.1f,%.1f', $this->x($i, $n), $this->y((int) $row[$key], $max));
    }
    return sprintf(
      '<polyline fill="none" stroke="%s" stroke-width="2" points="%s"/>',
      $colour,
      implode(' ', $points)
    );
  }

  /**
   * The point markers for one severity, each with a native title + data hooks.
   */
  private function markers(array $series, string $key, string $colour, string $shape, string $label, int $max): string {
    $n = count($series);
    $out = '';
    foreach ($series as $i => $row) {
      $count = (int) $row[$key];
      $cx = $this->x($i, $n);
      $cy = $this->y($count, $max);
      $tip = $row['date'] . ': ' . $label . ' ' . $count;
      $out .= sprintf(
        '<g class="ag-trend-point" fill="%s" data-date="%s" data-series="%s" data-count="%d">',
        $colour,
        Html::escape((string) $row['date']),
        Html::escape($label),
        $count
      );
      $out .= $this->glyph($shape, $cx, $cy);
      $out .= '<title>' . Html::escape($tip) . '</title>';
      $out .= '</g>';
    }
    return $out;
  }

  /**
   * A single marker glyph centred at (cx, cy).
   */
  private function glyph(string $shape, float $cx, float $cy): string {
    return match ($shape) {
      'square' => sprintf('<rect x="%.1f" y="%.1f" width="6" height="6"/>', $cx - 3, $cy - 3),
      'triangle' => sprintf('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f"/>', $cx, $cy - 4, $cx - 3.6, $cy + 3, $cx + 3.6, $cy + 3),
      'diamond' => sprintf('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f"/>', $cx, $cy - 4, $cx + 4, $cy, $cx, $cy + 4, $cx - 4, $cy),
      'plus' => sprintf('<rect x="%.1f" y="%.1f" width="2" height="8"/><rect x="%.1f" y="%.1f" width="8" height="2"/>', $cx - 1, $cy - 4, $cx - 4, $cy - 1),
      default => sprintf('<circle cx="%.1f" cy="%.1f" r="3.5"/>', $cx, $cy),
    };
  }

  /**
   * A horizontal legend row: marker glyph + label per severity.
   */
  private function legend(): string {
    $out = '';
    $i = 0;
    foreach (self::SERIES as [$label, $colour, $shape]) {
      $lx = self::PLOT_LEFT + $i * 132;
      $ly = 290;
      $out .= '<g fill="' . $colour . '">';
      $out .= $this->glyph($shape, $lx + 4, $ly - 4);
      $out .= sprintf(
        '<text x="%d" y="%d" font-size="12" fill="#52514e">%s</text>',
        $lx + 14,
        $ly,
        Html::escape((string) $this->t($label))
      );
      $out .= '</g>';
      $i++;
    }
    return $out;
  }

}
```

Append to `web/modules/custom/accessguard/accessguard.services.yml` (after `accessguard.trend_builder`):

```yaml
  accessguard.trend_chart_builder:
    class: Drupal\accessguard\Service\TrendChartBuilder
    arguments: ['@string_translation']
```

- [ ] **Step 4: Run test to verify it passes**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/TrendChartBuilderTest.php"
```
Expected: OK (8 tests).

- [ ] **Step 5: phpcs**

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard
```
Expected: clean. Fix anything reported (watch for a missing `use` or an over-long line).

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/src/Service/TrendChartBuilder.php web/modules/custom/accessguard/accessguard.services.yml web/modules/custom/accessguard/tests/src/Kernel/TrendChartBuilderTest.php
git commit -m "feat(trends): TrendChartBuilder renders the daily series as inline SVG

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: `accessguard/trend_chart` frontend library

**Files:**
- Create: `web/modules/custom/accessguard/accessguard.libraries.yml`
- Create: `web/modules/custom/accessguard/css/trend-chart.css`
- Create: `web/modules/custom/accessguard/js/trend-chart.js`
- Test: `web/modules/custom/accessguard/tests/src/Kernel/TrendChartLibraryTest.php`

**Interfaces:**
- Consumes: the `class="ag-trend-point"` markers with `data-date`/`data-series`/`data-count` from Task 1; the `.ag-trend-wrapper` element Task 3 wraps the SVG in.
- Produces: library `accessguard/trend_chart` (Task 3 attaches it by that name).

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Kernel/TrendChartLibraryTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/TrendChartLibraryTest.php"
```
Expected: FAIL — `assertNotEmpty` fails (library undefined; `getLibraryByName` returns FALSE).

- [ ] **Step 3: Create the library and assets**

Create `web/modules/custom/accessguard/accessguard.libraries.yml`:

```yaml
trend_chart:
  version: 1.x
  css:
    theme:
      css/trend-chart.css: {}
  js:
    js/trend-chart.js: {}
  dependencies:
    - core/drupal
    - core/once
```

Create `web/modules/custom/accessguard/css/trend-chart.css`:

```css
/**
 * Styling for the AccessGuard Trends SVG chart and its hover tooltip.
 */
.ag-trend-wrapper {
  position: relative;
  max-width: 760px;
  margin: 0 0 1rem;
}

.ag-trend-chart {
  display: block;
  width: 100%;
  height: auto;
}

.ag-trend-point {
  cursor: pointer;
}

.ag-trend-tooltip {
  position: absolute;
  z-index: 10;
  padding: 3px 6px;
  border-radius: 3px;
  background: #0b0b0b;
  color: #fff;
  font-size: 12px;
  line-height: 1.3;
  white-space: nowrap;
  pointer-events: none;
  transform: translate(-50%, -130%);
}

.ag-trend-tooltip[hidden] {
  display: none;
}
```

Create `web/modules/custom/accessguard/js/trend-chart.js`:

```js
/**
 * @file
 * Pointer-hover tooltips for the AccessGuard Trends SVG chart.
 *
 * Enhancement only: the chart's role=img title/desc and the data table below
 * carry the same information for keyboard and assistive-tech users.
 */
(function (Drupal, once) {
  Drupal.behaviors.accessguardTrendChart = {
    attach: function (context) {
      once('ag-trend-chart', '.ag-trend-chart', context).forEach(function (svg) {
        var wrapper = svg.parentNode;
        var tip = document.createElement('div');
        tip.className = 'ag-trend-tooltip';
        tip.hidden = true;
        wrapper.appendChild(tip);

        svg.querySelectorAll('.ag-trend-point').forEach(function (point) {
          point.addEventListener('mouseenter', function () {
            tip.textContent =
              point.getAttribute('data-date') + ': ' +
              point.getAttribute('data-series') + ' ' +
              point.getAttribute('data-count');
            var mark = point.getBoundingClientRect();
            var box = wrapper.getBoundingClientRect();
            tip.style.left = (mark.left - box.left + mark.width / 2) + 'px';
            tip.style.top = (mark.top - box.top) + 'px';
            tip.hidden = false;
          });
          point.addEventListener('mouseleave', function () {
            tip.hidden = true;
          });
        });
      });
    }
  };
})(Drupal, once);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/TrendChartLibraryTest.php"
```
Expected: OK (1 test).

- [ ] **Step 5: phpcs (yml is linted; css/js are not)**

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard
```
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/accessguard.libraries.yml web/modules/custom/accessguard/css/trend-chart.css web/modules/custom/accessguard/js/trend-chart.js web/modules/custom/accessguard/tests/src/Kernel/TrendChartLibraryTest.php
git commit -m "feat(trends): trend_chart library with hover-tooltip behaviour

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Wire the chart into the Trends tab + docs + verification

**Files:**
- Modify: `web/modules/custom/accessguard/src/Controller/AnalyticsController.php` (constructor, `create()`, `trends()`)
- Modify: `web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php` (append tests)
- Modify: `README.md` (Trends bullet, line ~108)
- Test: full module suite + phpcs

**Interfaces:**
- Consumes: `accessguard.trend_chart_builder` → `render(array $series): string` (Task 1); library `accessguard/trend_chart` (Task 2).
- Produces: `trends()` render array gains a `chart` element (above `note`/`table`) and `#attached[library][]` with `accessguard/trend_chart`, only when the series is non-empty.

- [ ] **Step 1: Write the failing tests**

Append to `AnalyticsControllerTest` (before the final closing brace):

```php
  /**
   * Tests the trends tab renders the SVG chart above the table.
   */
  public function testTrendsTabRendersChart(): void {
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
    $node = Node::create(['type' => 'page', 'title' => 'T', 'status' => 1]);
    $node->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
      'created' => strtotime('2026-07-01 12:00:00 UTC'),
      'count_critical' => 2,
    ])->save();

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $build = AnalyticsController::create($this->container)->trends();

    $this->assertArrayHasKey('chart', $build);
    $this->assertContains('accessguard/trend_chart', $build['#attached']['library']);
    $rendered = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<svg', $rendered);
    // The chart precedes the table caption in the output.
    $this->assertLessThan(
      strpos($rendered, 'site state per scan-day'),
      strpos($rendered, '<svg'),
      'The SVG chart renders above the data table.'
    );
  }

  /**
   * Tests the trends tab omits the chart when there are no scans.
   */
  public function testTrendsTabHasNoChartWhenEmpty(): void {
    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $build = AnalyticsController::create($this->container)->trends();

    $this->assertArrayNotHasKey('chart', $build);
    $rendered = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('No scans recorded yet', $rendered);
    $this->assertStringNotContainsString('<svg', $rendered);
  }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php"
```
Expected: the two new tests FAIL (`chart` key absent / no `<svg>` in output); existing tests pass.

- [ ] **Step 3: Implement**

In `AnalyticsController.php`, add the import near the other `use` lines:

```php
use Drupal\accessguard\Service\TrendChartBuilder;
```

Extend the constructor and `create()`:

```php
  public function __construct(
    protected ViolationAnalytics $analytics,
    protected TrendBuilder $trendBuilder,
    protected TrendChartBuilder $trendChartBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('accessguard.violation_analytics'),
      $container->get('accessguard.trend_builder'),
      $container->get('accessguard.trend_chart_builder'),
    );
  }
```

Replace the body of `trends()` with (builds the series once, renders the chart above the unchanged note/table via inline_template, attaches the library only when there is a chart):

```php
  public function trends(): array {
    $series = $this->trendBuilder->dailySeries();
    $rows = [];
    foreach (array_reverse($series) as $day) {
      $rows[] = [
        $day['date'],
        $day['critical'],
        $day['serious'],
        $day['moderate'],
        $day['minor'],
        $day['needs_review'],
        $day['total'],
      ];
    }

    $build = [];
    // The chart plots the series oldest-first; the table stays newest-first.
    $svg = $this->trendChartBuilder->render($series);
    if ($svg !== '') {
      $build['chart'] = [
        // #markup would strip the SVG tags via Xss::filter(); render the
        // builder-produced (already-escaped) string raw instead.
        '#type' => 'inline_template',
        '#template' => '<div class="ag-trend-wrapper">{{ svg|raw }}</div>',
        '#context' => ['svg' => $svg],
      ];
      $build['#attached']['library'][] = 'accessguard/trend_chart';
    }

    // Historical waiver state is not reconstructable, so this series is
    // "as scanned", not "open now" — say so instead of quietly disagreeing
    // with the Overview's numbers.
    $build['note'] = [
      '#markup' => '<p><em>' . $this->t('Counts are as recorded at scan time; waivers are not applied retroactively, so numbers can differ from the Overview.') . '</em></p>',
    ];
    $build['table'] = [
      '#type' => 'table',
      '#caption' => $this->t('Violations over time (site state per scan-day)'),
      '#header' => [
        $this->t('Date'),
        $this->t('Critical'),
        $this->t('Serious'),
        $this->t('Moderate'),
        $this->t('Minor'),
        $this->t('Needs review'),
        $this->t('Total'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No scans recorded yet.'),
    ];
    // Any scan write moves the series; node deletions/access changes change
    // which scans are visible.
    $build['#cache'] = [
      'tags' => ['accessguard_scan_list', 'node_list'],
      'contexts' => ['user.node_grants:view', 'user.permissions'],
    ];
    return $build;
  }
```

- [ ] **Step 4: Run the AnalyticsController tests**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php"
```
Expected: OK — existing trends/rule/author tests plus the 2 new ones all pass. (`testTrendsTabRendersSeries` still passes: the table is unchanged.)

- [ ] **Step 5: Update the README Trends bullet**

In `README.md`, replace:

```markdown
  - a **Trends** tab charting the site's per-severity counts as a day-by-day state series from scan history (as-scanned numbers; waivers aren't applied retroactively)
```

with:

```markdown
  - a **Trends** tab charting the site's per-severity counts as a day-by-day state series from scan history — a dependency-free inline-SVG line chart (five severities, distinct marker shapes + a colourblind-checked palette, `role="img"` with a data-table text alternative) above the full table (as-scanned numbers; waivers aren't applied retroactively)
```

- [ ] **Step 6: Run the full module suite**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests"
```
Expected: OK — 134 existing + 8 (Task 1) + 1 (Task 2) + 2 (Task 3) = 145 tests, 0 failures. The 3 known vendor deprecation notices (Drush x1, core Twig x2) are expected. If any test fails, STOP and fix before committing.

- [ ] **Step 7: phpcs both modules**

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo
```
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add web/modules/custom/accessguard/src/Controller/AnalyticsController.php web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php README.md
git commit -m "feat(trends): render the SVG chart above the Trends table

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
