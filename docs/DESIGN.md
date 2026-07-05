# AccessGuard — Design

## Problem

Organizations under WCAG 2.2 / Section 508 must keep their websites accessible, but content authors continually introduce violations, and typical tooling only reports what is broken on a single page at a single moment. There is no lightweight, open way to make accessibility an enforced, tracked, auditable part of the content lifecycle inside the CMS.

## Goals

- Detect accessibility violations on rendered Drupal pages using the standard engine (axe-core), not a bespoke reimplementation.
- Store violations as first-class data so they can be tracked and reported over time.
- Block publishing content that introduces violations above a configurable severity threshold.
- Attribute violations to the content author responsible.
- Produce a site-wide compliance dashboard and an exportable audit report.

## Non-goals

- Reimplementing or improving axe-core's detection rules.
- Auto-remediating violations (the tool flags; humans fix).
- Scanning non-Drupal or arbitrary external sites.

## Architecture

A thin Node microservice does detection; all governance lives in Drupal/PHP.

```
Author saves / publishes a node
        │
        ▼
Drupal (AccessGuard module, PHP)
  • enqueues a scan job (Queue API) on save/cron
  • QueueWorker calls the scanner
        │  HTTP POST { url }
        ▼
Node scanner microservice (Puppeteer + axe-core)
  • headless Chromium loads the rendered URL
  • runs axe-core (WCAG 2.2 AA ruleset)
  • returns JSON violations
        │  JSON
        ▼
Drupal stores results as entities → dashboard, gate, audit export
```

## Data model

- **`accessguard_scan`** — one scan run: target node, URL, timestamp, triggering user, content author (attribution), status, and per-severity violation counts.
- **`accessguard_violation`** — one finding: parent scan, axe rule id, impact, WCAG criterion, CSS selector, offending HTML, help URL.

Because scans are retained, regression tracking (new / fixed / persisting) is a diff of a node's two most recent scans.

## Components

| Component | Responsibility |
|---|---|
| `scanner/` (Node) | axe-core in headless Chromium behind `POST /scan`, with an SSRF guard |
| `ScanRunner` | Calls the scanner over HTTP, validates the response shape |
| `ScanRecorder` | Persists a scan result as scan + violation entities |
| `RegressionService` | Diffs a node's two latest scans |
| `AccessguardScanWorker` | Queue worker that runs a scan and records it |
| `AccessguardGate` constraint | Blocks publishing a node whose latest scan exceeds the threshold |
| `DashboardController` | Compliance overview, per-node detail, CSV audit export |
| `accessguard_cron` | Enqueues stale / unscanned published nodes |

## Security model

The scanner loads URLs in a real browser, an SSRF surface. It allows only `http`/`https` and blocks private, loopback, link-local, CGNAT, and reserved IP ranges by post-resolution IP check (defeating hex/octal encodings). Scanning internal hosts requires an explicit `SCANNER_ALLOW_PRIVATE` opt-in — secure by default. The CSV export neutralizes formula injection. Known limitation: the resolved IP is not pinned into Puppeteer, so redirect-based and DNS-rebinding vectors are not fully closed (roadmap).

## Testing

- Scanner: Jest unit tests for detection and the SSRF guard.
- Module: PHPUnit unit test for `ScanRunner`; kernel tests for `ScanRecorder`, the publish gate, cron enqueueing, and the regression service.
- `benchmark/` compares detection against pa11y (and optionally Lighthouse) on fixtures with known planted violations.
