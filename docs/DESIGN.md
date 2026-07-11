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
- **Certifying WCAG conformance.** Automated testing covers ~23 of the 55 WCAG 2.2 A/AA criteria; a clean scan is necessary but not sufficient for conformance. AccessGuard governs the automatable layer and produces evidence to feed a manual audit — it does not replace one, and its reports carry a disclaimer to that effect.

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
| `scanner/` (Node) | axe-core in headless Chromium behind `POST /scan`, plus `POST /pdf` for report rendering, both behind an SSRF guard and optional token auth |
| `ScanRunner` | Calls the scanner over HTTP, validates the response shape, probes `/health` |
| `ScanRecorder` | Persists a scan result as scan + violation entities (transactionally) |
| `ScanAccessToken` | Mints/validates the signed token that lets the scanner view unpublished nodes, and builds the scan URL |
| `RegressionService` | Diffs a node's two latest scans |
| `WaiverMatcher` | Records/looks up waivers by rule+selector fingerprint |
| `ViolationAnalytics` | Shared, batch-loaded, access-filtered latest-scan aggregation (by rule, by author, by page, summary) used by the dashboards and the PDF |
| `ReportHtmlBuilder` / `PdfClient` | Build the self-contained audit HTML and render it to PDF via the scanner |
| `AccessguardScanWorker` | Queue worker that runs a scan and records it; suspends on scanner outage, bounded-retries per-item failures |
| `AccessguardGate` constraint | Blocks publishing a node whose latest scan exceeds the threshold |
| `DashboardController` / `AnalyticsController` | Compliance overview, per-node detail, by-rule / by-author tabs, CSV + PDF export |
| `accessguard_cron` | Enqueues stale / unscanned published nodes and purges scans past the retention window |

## Security model

The scanner loads URLs in a real browser, an SSRF surface. It allows only `http`/`https` and blocks private, loopback, link-local, CGNAT, and reserved IP ranges by post-resolution IP check (defeating hex/octal encodings). Scanning internal hosts requires an explicit `SCANNER_ALLOW_PRIVATE` opt-in — secure by default. Every request the page makes (navigation, redirects, subresources) is intercepted: its hostname is resolved and validated once, then the request is fulfilled by a Node-side fetch that connects to that exact vetted IP (`pinnedFetch.js`, with SNI/certificate checks intact for https), so Chromium never re-resolves a name and DNS rebinding has no window on any request. Redirects are returned to the browser un-followed, so each hop is re-validated and re-pinned. The `/scan` and `/pdf` endpoints can additionally require a shared-secret `X-Scanner-Token` header by setting `SCANNER_AUTH_TOKEN` (matched on the Drupal side by the settings form, or by the `ACCESSGUARD_SCANNER_TOKEN` environment variable so the secret can stay out of exported config). To scan *unpublished* content the anonymous scanner would otherwise receive a 403 page, so the queue worker appends a short-lived, HMAC-signed, node-bound access token to the URL; `hook_node_access()` grants view for exactly that node and window and nothing more. The CSV export neutralizes formula injection, and the report/triage routes enforce node-level view access.

## Testing

- Scanner: Jest tests for detection, the SSRF guard (including DNS-rebind pinning, IPv6, and decompression-bomb defense), token auth, the concurrency cap, and the PDF sandbox.
- Module: PHPUnit unit tests for `ScanRunner`, `PdfClient`, and `CsvSafe`; kernel tests across the scan lifecycle — recorder, queue worker (outage vs. per-item retry), scan-access tokens, publish gate (including threshold ranking and the unpublish→fix→republish path), waivers, cron enqueue/retention, dashboard/analytics access filtering, regression diff, settings form, the Drush command, and node-delete cleanup.
- `benchmark/` runs axe (WCAG 2.2 AA) against pa11y (and optionally Lighthouse) on fixtures with known planted violations; note the fixtures all target rules inside Lighthouse's axe subset, so the harness shows detection parity on common rules rather than the full-ruleset coverage advantage.
