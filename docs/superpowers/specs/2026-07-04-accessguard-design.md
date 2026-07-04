# AccessGuard — Drupal Section 508 / WCAG Compliance Governance Module

_Design spec. Written 2026-07-04._

## 1. Summary

AccessGuard is a custom Drupal module that helps an organization stay legally accountable for web accessibility (WCAG 2.2 / Section 508). It does not reinvent accessibility detection. It integrates **axe-core**, the industry-standard open-source accessibility engine, and builds the governance layer that existing free tools lack: continuous scanning, publish-gating, author attribution, historical regression tracking, and audit-ready reporting.

One-line pitch: "axe-core tells you what is broken. AccessGuard makes an organization actually stay accountable for fixing it."

## 2. Why this project

- The developer's strongest active job lead is a Drupal/PHP developer role at a California state agency. Every California state agency is legally required to meet Section 508 / WCAG. This project is directly relevant to that role and to the recurring pool of state Drupal jobs.
- It turns an existing resume credential (WCAG 2.2) into demonstrated, shipped work.
- Integrating a proven engine instead of rebuilding detection is a deliberate maturity signal.

## 3. Goals and non-goals

**Goals**
- Detect accessibility violations on rendered Drupal pages using axe-core.
- Store violations as first-class Drupal data so they can be tracked over time.
- Block publishing of content that introduces violations above a configurable severity threshold.
- Attribute violations to the content author responsible.
- Produce a site-wide compliance dashboard and an exportable audit report.
- Ship with fixtures and automated tests so a reviewer can clone and run it.

**Non-goals**
- Rebuilding or improving axe-core's detection rules.
- Automatically fixing violations (we flag, we do not auto-remediate).
- Scanning non-Drupal or external sites.
- Public cloud hosting. Local ddev/Docker plus a documented README is the delivery target.

## 4. Users

- **Content author** — sees accessibility problems on their own content and is blocked from publishing seriously broken pages.
- **Compliance manager / admin** — views the site-wide dashboard, per-author accountability, and exports audit reports.
- **Developer / reviewer (hiring manager)** — clones the repo, runs `ddev start`, seeds fixtures, and sees the pipeline work end to end.

## 5. Architecture

```
Author saves / publishes a node
        |
        v
Drupal (AccessGuard module, PHP)
  - enqueues a scan job (Queue API)
  - QueueWorker pulls the job (immediately and/or on cron)
        |   HTTP POST { url }
        v
Node scanner microservice (Puppeteer + axe-core), same Docker network
  - headless Chromium loads the real rendered URL
  - runs axe-core with the WCAG 2.2 AA ruleset
  - returns JSON violations
        |   JSON
        v
Drupal stores results as entities -> dashboards, publish-gating, audit reports
```

The Node service is intentionally minimal: URL in, violations out. All governance logic (storage, gating, attribution, reporting) lives in the PHP/Drupal module, which is where the engineering value and the resume story sit.

## 6. Components

| Component | Purpose | Drupal concept demonstrated |
|---|---|---|
| Scan queue + `AccessGuardScanWorker` | Process scan jobs asynchronously | Queue API, QueueWorker plugin |
| `ScanRunner` service | Call the Node scanner over HTTP, normalize the response | Services, dependency injection, HTTP client |
| `accessguard_scan` entity | One scan run per target | Entity API, storage, schema |
| `accessguard_violation` entity | One finding per violation | Entity API, entity references |
| Publish-gating validator | Block moderation transition when severity exceeds threshold | Content moderation / entity validation |
| Dashboard controllers | Site overview, per-node, per-author views | Routing, controllers, render arrays |
| Audit export | CSV / printable HTML compliance report | Controller + response |
| `hook_cron` enqueue | Periodically re-scan stale/unscanned published content | Cron, batching |
| Settings form | Threshold, scanned content types, scanner endpoint, axe ruleset, cron cadence | Config forms, config schema |
| Permissions | `administer accessguard`, `view accessguard reports`, `bypass accessguard gating` | Access system |
| Fixtures pack | Seed nodes with known violations | Default content / drush script |
| Test suite | Kernel and functional tests over fixtures | PHPUnit |

## 7. Data model

**`accessguard_scan`**
- `id`
- `target_entity_type`, `target_entity_id` (the scanned node)
- `url` (URL that was scanned)
- `created` (timestamp)
- `triggered_by` (uid of who caused the scan, or "cron")
- `content_author` (uid of the node's author at scan time — attribution)
- `status` (queued, running, complete, failed)
- `count_critical`, `count_serious`, `count_moderate`, `count_minor` (severity rollups)

**`accessguard_violation`**
- `id`
- `scan_id` (reference to parent scan)
- `rule_id` (axe rule, e.g. `image-alt`)
- `impact` (critical / serious / moderate / minor)
- `wcag_criterion` (e.g. 1.1.1)
- `selector` (CSS selector of the offending element)
- `html_snippet` (the offending markup)
- `help_url` (axe help link)

Because every scan is stored, regression tracking is a comparison between a node's newest scan and its previous scan: new, fixed, and still-present violations.

## 8. Key workflows

**Scan lifecycle**
1. A trigger (node save, manual action, or cron) enqueues a scan job for a node.
2. `AccessGuardScanWorker` dequeues it, resolves the node's public URL, and calls `ScanRunner`.
3. `ScanRunner` POSTs the URL to the Node scanner and receives JSON violations.
4. The worker creates one `accessguard_scan` and its child `accessguard_violation` records, with severity rollups and author attribution.

**Publish-gating**
- On a moderation transition to a published state, the validator checks the latest scan for that node.
- If any violation at or above the configured threshold exists, the transition is blocked with a clear validation message listing the blocking violations.
- Users with `bypass accessguard gating` can override.

**Cron site-wide scanning**
- `hook_cron` enqueues published nodes that have never been scanned or whose last scan is older than a configurable interval.

**Regression tracking**
- For a given node, compare newest scan to previous scan and label each violation new / fixed / persisting.

**Reporting**
- Site overview: total violations, severity breakdown, trend over time, worst rules, worst content types.
- Per-author accountability: violations grouped by `content_author`.
- Audit export: a point-in-time compliance report as CSV and printable HTML.

## 9. Test fixtures and testing strategy

A fixtures pack installs seed nodes, each with a known, textbook violation:
- image with no alt text (`image-alt`)
- skipped heading level, h1 to h4 (`heading-order`)
- non-descriptive link text, "click here" (`link-name`)
- low-contrast inline-styled text (`color-contrast`)
- form input with no label (`label`)
- data table with no header cells (`th-has-data-cells` / `td-headers-attr`)

Because axe-core's rule set is fixed and documented, each fixture has an expected violation. The fixtures serve two purposes:
1. Manual verification: seed, scan, watch the dashboard populate.
2. Automated tests: Kernel/functional tests assert that scanning a given fixture yields the expected rule id and severity.

Installed via a drush command or default-content export so a reviewer can reproduce results in one step.

## 10. Tech stack, environment, cost

- **Drupal 10 or 11** (match the version already installed in the developer's `drupal-practice` composer project).
- **PHP** for the module.
- **Node.js + Puppeteer + axe-core** for the scanner microservice.
- **ddev + Docker** for the local environment.
- **Cost: $0.** Every dependency is free and open source. No API keys, no cloud account, no subscription. Bare minimum to run: a machine with Docker Desktop.

## 11. Build phases

1. **Detection pipeline end to end** — module skeleton, both entities, settings form, Node scanner microservice, `ScanRunner` service, and a manual "scan this node" action that stores real results. Proves the hardest integration first.
2. **Dashboards and per-node history** — site overview and per-node detail views.
3. **Publish-gating and permissions** — moderation validator, threshold config, bypass permission.
4. **Cron site-wide scanning, regression diff, author accountability, audit export.**
5. **Fixtures pack, test suite, and README** with screenshots and a demo GIF.

## 12. Success criteria

**Functional**
- A reviewer can clone the repo, run `ddev start`, seed fixtures, trigger a scan, and see violations appear in the dashboard.
- Publishing a node that introduces a critical violation is blocked.
- An audit report exports with real data.
- Tests pass.

**Portfolio**
- Clean public GitHub repo with a README that explains the problem, the architecture, and the "why axe-core plus governance" decision.
- A short demo GIF showing a bad page being caught and blocked.
- One or two interview-ready sentences the developer can say truthfully about the work.

## 13. Risks and open questions

- **Headless browser reliability in Docker** — Puppeteer/Chromium in a container can be finicky. Mitigation: pin a known-good Chromium image and keep the scanner service minimal.
- **Resolving a node's public URL for scanning** — needs care for unpublished/preview content. Decision: gate on the latest publishable render; for drafts, scan the preview URL.
- **Drupal version** — confirm 10 vs 11 from the existing composer project before scaffolding.
- **Scope creep** — attribution and per-author reporting are valuable but land in phase 4. Keep phases 1 to 3 strictly to a working, gated pipeline first.
