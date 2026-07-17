# Trends inline-SVG chart — design

Date: 2026-07-17. Approved by Jeff (chart data, chart/table relationship,
build location, interactivity, and overall design each confirmed via
clickable questions).

## Purpose

Give the Trends tab a visual read of the daily severity series it currently
renders only as a table, so an editor can see at a glance whether
accessibility violations are trending up or down and which severity is
driving the change.

## The problem it solves

`AnalyticsController::trends()` renders `TrendBuilder::dailySeries()` as a
plain table (date + five severity counts + total, newest-first). Reading a
trend out of a column of numbers is hard; a line chart makes direction and
composition obvious. Nothing visual exists today, and the module ships no
chart assets.

## Design

### 1. `TrendChartBuilder` service (new)

`Drupal\accessguard\Service\TrendChartBuilder`
(`accessguard.trend_chart_builder`) turns the series into SVG markup — the
single owner of the chart geometry, keeping the controller thin (the same
factoring as `TrendBuilder` and `GateEvaluator`).

- `render(array $series): string` — takes the `dailySeries()` output (rows
  oldest-first: `date`, `critical`, `serious`, `moderate`, `minor`,
  `needs_review`, `total`) and returns a complete `<svg>…</svg>` string, or
  `''` for an empty series.

Dependencies: `StringTranslationInterface` (for the `<title>`/`<desc>`/legend
text). No entity or config access — it is a pure series→markup transform, so
it unit-tests in isolation.

### 2. The SVG

One `<svg role="img" viewBox="0 0 W H" preserveAspectRatio="xMidYMid meet">`,
CSS-sized to `width:100%` with a fixed aspect ratio so it scales
responsively. Fixed internal coordinate space (e.g. `W=720 H=320`) with a
left/bottom margin for axis labels.

- `<title>` and `<desc>` as the SVG's first children, referenced by
  `aria-labelledby`/`aria-describedby`: title e.g. "Accessibility violations
  over time"; desc e.g. "Per-severity daily counts across N scan-days,
  <first-date> to <last-date>. Full figures in the table below."
- **Five `<polyline>` series** — critical, serious, moderate, minor,
  needs-review — plotted oldest→newest left-to-right, each with small marker
  glyphs at every data point.
- **Y axis**: scaled to a "nice" rounded maximum at or above the largest
  count across all five series; 4–5 horizontal gridlines with integer
  labels. When every count is 0, the axis still renders a 0…1 range and the
  lines sit on the baseline.
- **X axis**: one tick per day, but text labels thinned to at most ~6 evenly
  spaced dates to avoid collision; label format `M-D`.
- **Legend**: a visible list mapping each severity to its colour **and a
  distinct marker shape** (circle, square, triangle, diamond, cross), so the
  five series are distinguishable without relying on colour alone
  (WCAG 1.4.1 — non-negotiable for this tool's own UI).

### 3. Colour palette (validated)

A fixed severity palette defined once as a class constant in the builder.
Validated with the `dataviz` skill's palette validator against a light chart
surface (the Claro admin theme; this is a light-only admin surface, so no
dark variant is built). The ordering was chosen so adjacent severities stay
apart under colour-vision deficiency — critical stays the conventional red,
and no severity uses green (green reads as "good/pass" in an accessibility
tool):

| Severity | Hue | Hex |
|----------|-----|-----|
| critical | red | `#e34948` |
| serious | violet | `#4a3aa7` |
| moderate | orange | `#eb6834` |
| minor | blue | `#2a78d6` |
| needs-review | teal | `#1baf7a` |

Validator result (light, surface `#fcfcfb`): lightness band, chroma floor,
CVD separation, and normal-vision floor all **PASS** (worst adjacent
normal-vision ΔE 22.7). Teal is the one colour below 3:1 contrast (2.74:1);
the validator's **relief rule** is satisfied because the chart ships both a
visible text legend and the full data table beneath it. Colour is a
secondary cue regardless — the distinct marker shapes in §2 are the primary
differentiator (each severity keeps its shape even where two colours are
close under CVD).

### 4. Assets: `accessguard/trend_chart` library

The module's first `accessguard.libraries.yml` defines a `trend_chart`
library with one CSS file (chart sizing, legend layout, tooltip box styling)
and one JS file. The controller attaches it via `#attached[library][]` on
the trends render array.

### 5. JS tooltip behaviour (progressive enhancement)

`js/trend-chart.js` — a vanilla-JS `Drupal.behaviors` entry, no third-party
library. It is a **pointer-hover enhancement only**: on `mouseenter`/
`mouseleave` of a data-point marker it shows/hides a styled tooltip box
reading "<date>: <severity> <count>", positioned near the marker. Marker
coordinates and text come from `data-*` attributes the builder writes on
each marker.

Accessibility is handled by the SVG's own `role="img"` + `<title>`/`<desc>`
summary and by the full data table beneath the chart — **not** by making
individual points focusable. Focusable children inside a `role="img"`
element contradict its single-image semantics, so the chart deliberately
has none; keyboard and assistive-tech users read the summary and the table,
which carry every number the hover tooltip would. Each marker still carries
a native SVG `<title>` child, so even with JS disabled a mouse hover shows
the browser's built-in tooltip. The chart degrades, it never breaks.

### 6. Controller wiring

`AnalyticsController::trends()` builds the series once, passes it to
`TrendChartBuilder::render()`, and places the returned SVG **above** the
existing note and table.

Rendering mechanism (pinned): a plain `#markup` string will not work —
Drupal runs `#markup` through `Xss::filter()`, which strips `<svg>`,
`<polyline>`, `<title>`, etc. Instead the controller renders the SVG via
`'#type' => 'inline_template'` with `'#template' => '{{ svg|raw }}'` and
`'#context' => ['svg' => $svg]`. The `|raw` is safe because the string is
built entirely by `TrendChartBuilder` from integer-cast and escaped values
(§Error handling) — no user-controlled markup passes through. (Equivalently
the builder could return `\Drupal\Core\Render\Markup::create($svg)`; the
plan picks one and stays consistent.)

The note and table are unchanged; the table remains the complete text
alternative for the chart. The `#attached` library is added; existing cache
tags/contexts are untouched.

### 7. Explicitly out of scope (YAGNI)

- Series toggling / hiding individual lines (the table already gives exact
  numbers; five static lines are legible with distinct markers).
- Date-range selection or zoom.
- Theming the chart via a Twig template override (the builder emits final
  markup; revisit only if a real override need appears).
- Charts on any other tab (Overview/By rule/By author) — this is
  Trends-only.
- Stacked/area rendering or a separate "total" line (the five severity
  lines plus the table's Total column cover it).

## Error handling / edge cases

- Empty series → `render()` returns `''`; the controller renders no chart,
  and the table's existing "No scans recorded yet" empty state stands.
- Single scan-day → markers render with no connecting line needed (a
  polyline of one point is valid and shows the marker).
- All-zero counts → flat lines on the baseline over a 0…1 axis.
- Very large day counts → X labels thin to ~6; markers still drawn per day
  (marker density is acceptable; only label collisions are managed).
- Security: every plotted value is an integer cast by the builder and every
  date is escaped; the assembled SVG string contains no unescaped
  user-controlled text, so it is safe to emit as raw markup.

## Testing

Unit/kernel tests on `TrendChartBuilder::render()`:

- Point count: a 3-day series yields 3 markers per severity line.
- Y-scaling: the largest count maps at/below the axis top; a "nice" max is
  chosen.
- Accessibility scaffolding present: `role="img"`, `<title>`, `<desc>`,
  `aria-labelledby`/`aria-describedby`, and a legend entry for all five
  severities.
- Non-colour differentiation: each series' legend/markers carry a distinct
  shape identifier, not only a colour.
- Empty series → `''`; single-day series → markers present, no crash.

Controller/kernel test on `trends()`:

- The SVG markup appears in the render array above the table.
- The `accessguard/trend_chart` library is attached.
- Empty-data path renders the table empty state and no SVG.
