# AccessGuard: Analytics tabs + PDF audit export — design

Date: 2026-07-07
Status: approved (brainstormed with Jeff)

## Goal

Two roadmap features:

1. **Per-rule / per-author analytics** — help a site owner decide what class of
   accessibility problem to fix first, and which authors need coaching.
2. **PDF audit export** — a formal, self-contained audit report for
   management, clients, or external auditors (the existing CSV already serves
   internal triage).

## Out of scope (YAGNI)

Trend charts over time, scheduled/emailed reports, per-author drill-down
pages, PDF theming/branding options.

## Part 1: Analytics

### Data layer

New service `accessguard.analytics_repository`
(`src/Repository/AnalyticsRepository.php`), following the existing
`ScanRepository` pattern (raw `Connection` queries, no entity loads).

One method, `latestViolationRows(): array`, runs a single SQL join:
violations whose `scan_id` is the latest scan per node (reusing the
`MAX(id) GROUP BY target_entity_id` subquery shape from
`ScanRepository::latestScanIdByNode()`), joined to `accessguard_scan` for
`target_entity_id` and `content_author`. Returns rows of
`(nid, author_uid, rule_id, impact, wcag_criterion, selector)`.

Aggregation happens in PHP, in one shared service
`accessguard.violation_analytics` (`src/Service/ViolationAnalytics.php`)
consumed by both the analytics controller and the PDF report builder —
methods `byRule()` and `byAuthor()` return plain arrays ready for table
rows. It aggregates in PHP because two filters don't belong in SQL:

- **Node access** — only nodes the current user can `view` are counted,
  exactly like the existing overview and CSV export (no leaking counts of
  inaccessible content).
- **Waivers** — `WaiverMatcher::waivedFingerprints($nid)` decides per row
  whether a violation is open or waived (fingerprints are rule+selector JSON,
  per node — not expressible as a simple SQL join).

### UI

New `src/Controller/AnalyticsController.php` (keeps the ~300-line
`DashboardController` focused). Two routes in `accessguard.routing.yml`,
both under the existing `view accessguard reports` permission:

- `accessguard.analytics_rules` — `/admin/reports/accessguard/rules`
- `accessguard.analytics_authors` — `/admin/reports/accessguard/authors`

New `accessguard.links.task.yml` defines three local tasks so the dashboard
gets tabs: **Overview | By rule | By author** (Overview is the default tab on
`accessguard.dashboard`).

**By rule** table, sorted by open count descending:

| Rule | Impact | WCAG | Pages affected | Open | Waived |

**By author** table, sorted by total open descending. Author = the scan's
`content_author`; falls back to "Unknown" when the user is missing:

| Author | Pages | Critical | Serious | Moderate | Minor | Waived |

(The four severity columns count **open** violations only.)

Both pages get an empty state ("No scans yet…") mirroring the overview, and
`#cache => ['max-age' => 0]` like the other report pages.

## Part 2: PDF audit export

### Generation strategy (decided)

The existing Node scanner renders the PDF with Puppeteer. Rationale: reuses
infrastructure we already run, authenticate, and test (Chromium, the
`X-Scanner-Token` shared secret, Jest); perfect CSS fidelity; zero new PHP
dependencies. Trade-off accepted: PDF export requires the scanner to be up —
it degrades gracefully and CSV still works.

Alternatives rejected: dompdf (new PHP dependency, weak CSS support),
print-optimized HTML page (manual step, not a deliverable).

### Scanner side (`scanner/`)

New `POST /pdf` endpoint in `src/server.js`, rendering logic in a new
`src/pdf.js` (mirrors the `server.js` / `scan.js` split):

- Body: `{ "html": "<string>" }`. Response: `application/pdf` bytes.
- **Auth**: same `isAuthorized()` check as `/scan` (401 when
  `SCANNER_AUTH_TOKEN` is set and the header doesn't match).
- **Body size**: a route-specific JSON limit of **5mb** (the global
  `express.json({ limit: '1mb' })` stays as-is for `/scan`; large sites
  produce large report HTML).
- **Rendering**: `page.setContent(html, { waitUntil: 'load' })` then
  `page.pdf({ format: 'A4', printBackground: true })`.
- **Hardening**: request interception aborts **every** outbound request from
  the render page — the report HTML must be fully self-contained (inline
  CSS, no images or fonts fetched). Consistent with the scanner's existing
  SSRF posture. Validation errors → 400 `{ error: 'invalid_html' }` (missing
  or non-string `html`); render failure → 500 `{ error: 'pdf_failed' }`.

### Drupal side

Two new services in `accessguard.services.yml`:

- `accessguard.report_html_builder` (`src/Service/ReportHtmlBuilder.php`) —
  returns one self-contained HTML string (inline `<style>`, no external
  assets). Sections:
  1. Cover: site name, "Accessibility audit report", generation date,
     "Prepared by" (current user display name).
  2. Compliance summary: pages scanned, total open violations, severity
     breakdown (same numbers as the overview).
  3. Violations by rule (the Part 1 per-rule table).
  4. Violations by author (the Part 1 per-author table).
  5. Per-page findings: for each scanned, accessible node — title, URL, last
     scan date, then its violations (rule, impact, WCAG, selector, status).
     **Waived items include the waiver reason and status** (the audit
     justification trail).
  All dynamic text passes through `Html::escape()`; the same node-access and
  waiver logic as the CSV export applies (shared via `ViolationAnalytics`).
- `accessguard.pdf_client` (`src/Service/PdfClient.php`) — mirrors
  `ScanRunner`'s HTTP usage: Guzzle `ClientInterface`, `scanner_endpoint`
  config for the base URL, `SCANNER_AUTH_TOKEN` env /`scanner_auth_token`
  config for the `X-Scanner-Token` header, POST to `/pdf`, sensible timeout
  (60s — big reports take longer than scans), returns raw PDF bytes or
  throws.

New route `accessguard.audit_export_pdf` —
`/admin/reports/accessguard/export/pdf`, permission
`view accessguard reports`, handled by `DashboardController::exportPdf()`
(thin: build HTML → client → stream response). Response headers:
`application/pdf`, `Content-Disposition: attachment;
filename="accessguard-audit-YYYY-MM-DD.pdf"`.

Overview page gets an **Export audit PDF** button next to the existing CSV
button.

### Error handling

`PdfClient` failure (connection refused, non-200, timeout) → catch in the
controller, `messenger()->addError()` ("PDF export requires the scanner
service to be running. CSV export is still available."), redirect back to
the dashboard. No fatal, no partial file.

## Testing

**Jest (scanner):**
- `/pdf` returns bytes starting with `%PDF` for valid HTML.
- 401 when `SCANNER_AUTH_TOKEN` is set and header missing/wrong.
- 400 on missing/non-string `html`.
- Outbound network requests from the render are blocked (fixture HTML with
  `<img src="http://127.0.0.1:1/x.png">` still renders; no request escapes).

**PHPUnit kernel:**
- `AnalyticsRepository::latestViolationRows()` returns only latest-scan
  violations per node.
- Per-rule and per-author aggregation: waived violations move from Open to
  Waived columns; nodes the user cannot view are excluded entirely.
- `ReportHtmlBuilder` output contains the expected sections and escapes
  markup in titles/selectors.
- `exportPdf` route: with a mocked failing client, responds with a redirect
  and an error message (no exception).
- Analytics routes respect the `view accessguard reports` permission.

phpcs clean; CI workflow unchanged (already runs Jest + PHPUnit + phpcs).
