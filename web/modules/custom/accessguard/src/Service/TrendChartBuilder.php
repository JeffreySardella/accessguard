<?php

namespace Drupal\accessguard\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
    // Escape every non-literal value: the whole SVG is emitted via
    // {{ svg|raw }}, so this string is the only escaping boundary.
    $svg .= '<title id="ag-trend-title">' . Html::escape($title) . '</title>';
    $svg .= '<desc id="ag-trend-desc">' . Html::escape($desc) . '</desc>';
    $svg .= $this->axes($series, $max);
    foreach (self::SERIES as $key => [, $colour, $shape]) {
      $svg .= $this->line($series, $key, $colour, $max);
      $svg .= $this->markers($series, $key, $colour, $shape, (string) $this->label($key), $max);
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
    foreach (self::SERIES as $key => [, $colour, $shape]) {
      $lx = self::PLOT_LEFT + $i * 132;
      $ly = 290;
      $out .= '<g fill="' . $colour . '">';
      $out .= $this->glyph($shape, $lx + 4, $ly - 4);
      $out .= sprintf(
        '<text x="%d" y="%d" font-size="12" fill="#52514e">%s</text>',
        $lx + 14,
        $ly,
        Html::escape((string) $this->label($key))
      );
      $out .= '</g>';
      $i++;
    }
    return $out;
  }

  /**
   * The translated label for a severity key.
   *
   * Literal t() calls per key (not $this->t($variable)) so Drupal's string
   * extraction can find every label for translation.
   */
  private function label(string $key): TranslatableMarkup {
    return match ($key) {
      'critical' => $this->t('Critical'),
      'serious' => $this->t('Serious'),
      'moderate' => $this->t('Moderate'),
      'minor' => $this->t('Minor'),
      'needs_review' => $this->t('Needs review'),
    };
  }

}
