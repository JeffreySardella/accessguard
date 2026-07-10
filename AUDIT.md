# AccessGuard — Full Project Audit

**Date:** 2026-07-09
**Scope:** `web/modules/custom/accessguard` + `accessguard_demo` (all PHP/YAML), `scanner/` (all JS, Dockerfile, tests), `benchmark/`, `.ddev/`, `.github/workflows/ci.yml`, composer/npm dependencies, and documentation.
**Method:** Four independent audit passes (security, Drupal module correctness, scanner service, tests/CI/project health), with every high-severity finding re-verified against the source before inclusion. The scanner Jest suite was run during the audit: 22/22 pass.

---

## Executive summary

This is a healthy, unusually well-engineered project for its size. The SSRF defense in the scanner is genuinely sound (DNS pinning, per-hop redirect re-validation, timing-safe auth — all covered by adversarial tests), the Drupal side has no SQL injection, no XSS, no CSRF gaps, and disciplined node-access filtering on every reporting surface, and CI actually runs everything that exists (Jest + PHPUnit Unit *and* Kernel + phpcs).

The most important problems are **not** in the areas the project already defends well. They cluster in three places:

1. **Scan-lifecycle correctness** — a publish-gate deadlock for unpublished content, a queue that one bad item can suspend forever, and scans of unpublished nodes that record the 403 page as the node's compliance state (which can wrongly *open* the gate).
2. **Scanner resilience** — unbounded Chromium concurrency, an unhandled-rejection crash path combined with `restart: "no"` and no healthcheck, and a decompression bomb in response handling.
3. **Aging browser engine** — the scanner renders untrusted page content on Puppeteer/Chromium ~18 months behind current, missing that window of Chromium security patches.

None of these are hard to fix, and the existing test infrastructure makes them safe to fix.

> **Remediation status (2026-07-10):** All six high-severity findings (H1–H6) fixed on this branch, plus mediums M1, M2, M3, M4, M5, M6, M7, M8, M9, M11–M16, M17, M18, M19, M20, M21, and lows L1, L2, L3, L4, L5, L7, L8, L15. Notes: M8 — the token now resolves from the `ACCESSGUARD_SCANNER_TOKEN` environment variable (overriding config) so the secret can stay out of exported config, and the form uses a no-echo password field; a hard Key-module dependency was deliberately not added. M10 — the dev ddev compose intentionally still runs open + private-allowed for the local demo (expose-only, not host-published); the residual copy-paste-to-prod risk is now loudly documented in that file rather than changed, since forcing a token would break the quick-start. L2 — a per-(node,fingerprint) lock now serializes the waiver check-then-insert. Remaining open items are cosmetic/low (e.g. L6 analytics semantics notes, L9–L14 scanner niceties like Set-Cookie handling and graceful shutdown, L16 assorted extra test coverage) — see individual findings above.

---

## High-severity findings

### H1. Publish-gate deadlock: an unpublished node with a failing scan can never be legitimately republished
`web/modules/custom/accessguard/src/Plugin/Validation/Constraint/AccessguardGateConstraintValidator.php:60-67`, `accessguard.module:44-46`

The gate blocks the unpublished→published transition using the node's *latest recorded scan*, but scans are only ever enqueued for **published** nodes: the save hook checks `isPublished()` (`accessguard.module:44`) and cron queries `status = 1`. So the failing scan that blocks republishing can never be refreshed through the normal pipeline.

*Scenario:* editor publishes a page, a scan records a critical violation, editor unpublishes it, fixes the markup, saves (no re-scan — the node is unpublished), tries to publish → the gate re-reads the stale scan and blocks. Forever. The only escapes are the bypass permission, waiving every violation (defeating the gate), or `drush accessguard:scan --now` — which hits H3 below. The validator's own comment ("Edits are re-scanned via the save hook") is only true for published nodes, and `PublishGateTest` does not cover the unpublish→fix→republish path.

*Fix direction:* scan unpublished nodes via an authenticated/preview context, or clear/ignore the gate when the blocking scan predates the last node revision, or enqueue a scan on unpublished saves too and gate on its result.

### H2. Queue poisoning: any per-item failure suspends the whole scan queue, with no retry limit
`web/modules/custom/accessguard/src/Plugin/QueueWorker/AccessguardScanWorker.php:58-69`

Every `\RuntimeException` from `ScanRunner` — including permanent, item-specific failures — is converted to `SuspendQueueException`, which releases the item back to the head of the FIFO queue with no retry counter and no delay.

*Scenario:* the scanner is up, but node 42's page consistently times out (or returns a wrong-shape body for that URL). Item 42 is claimed first every cron run, throws, suspends the queue — and every item behind it is never processed, indefinitely. `SuspendQueueException` is appropriate only for provably queue-wide outages (e.g. connect exceptions); item-level failures need a bounded-retry/discard path, ideally recording a `failed` scan (see M7). The worker has no test coverage at all.

### H3. Unpublished/inaccessible nodes get scanned anyway — a "clean" 403 page opens the publish gate
`AccessguardScanWorker.php:50-56`, `src/Drush/Commands/AccessguardCommands.php:52-60`

`processItem()` checks only that the node loads, not that it is still published. If a node is unpublished between enqueue and processing, the worker scans its canonical URL as an anonymous client and records the site's **403 page** as that node's scan. If the 403 page is clean, the node now has a zero-violation latest scan — so the gate (H1) lets it be republished with its real violations never fixed, and dashboards report it compliant. Same for `drush accessguard:scan --now` on an unpublished nid.

*Fix direction:* re-check `isPublished()` (and anonymous view access) in `processItem()` and skip/record-failed otherwise.

### H4. Scanner: unbounded concurrency — a full Chromium per request, no cap, no backpressure
`scanner/src/scan.js:33`, `scanner/src/pdf.js:18`, `scanner/src/server.js:31-51`

Every `/scan` and `/pdf` request launches a fresh Chromium (~200–500 MB RSS, 1–2 s cold start) with no semaphore, queue, or 429/503 response. Ten concurrent requests OOM-kill the container in typical memory limits; nothing stops requests 11–N from piling on. This is both an operational failure mode (cron bursts) and a trivial DoS for anyone who can reach the service (unauthenticated by default — see M2). The README lists browser pooling as roadmap, but even a cheap in-process concurrency cap with a "busy" response would remove the failure mode today.

### H5. Scanner: unhandled `req.continue()` rejection can crash the whole process — and nothing restarts it
`scanner/src/scan.js:53`, `scanner/src/pdf.js:45` (unguarded), vs. the abort paths which are correctly guarded (`scan.js:73`, `pdf.js:48`); compounded by `.ddev/docker-compose.scanner.yaml:11` (`restart: "no"`) and no `HEALTHCHECK` in the Dockerfile.

`req.continue()` is called without `await`/`.catch()`. When navigation times out and `finally` closes the browser while a request event is still in flight, `continue()` rejects ("Target closed" / "Request is already handled") → unhandled promise rejection → Node terminates the process, killing every in-flight scan. With `restart: "no"` and no healthcheck, the container then stays dead until someone notices. Combined with H2 on the Drupal side, a single crash silently halts all scanning.

*Fix direction:* `.catch(() => {})` on both `continue()` calls (matching the abort paths), plus `restart: unless-stopped` and a `HEALTHCHECK` against the existing `/health` endpoint.

### H6. Scanner runs an ~18-month-old Chromium against untrusted page content
`scanner/Dockerfile:1` (`FROM ghcr.io/puppeteer/puppeteer:23.0.0`), `scanner/package.json` (`puppeteer ^23.0.0`, lock resolves 23.11.1, ~Dec 2024)

The scanner's entire job is rendering arbitrary web content in a real browser. Running a browser engine ~1.5 years behind current forfeits that window of Chromium security patches on a hostile-input surface. The SSRF guard is excellent but does not protect against browser-engine exploits in scanned page content. Upgrade Puppeteer (24+) and re-pin, and adopt a cadence for keeping it current.

---

## Medium-severity findings

### Module correctness

**M1. Violations with `impact: null` are invisible to the gate and all severity counts.** `ScanRecorder.php:30-37` normalizes missing impact to `'unknown'`; the gate ranks unknown as 0 (`AccessguardGateConstraintValidator.php`, `($rank[$impact] ?? 0) < $threshold`), and `unknown` appears in no `count_*` column and no severity bucket in analytics. axe-core legitimately returns `impact: null` for some findings — a scan can carry 5 real violations, show 0/0/0/0 on the dashboard, and pass the gate at any threshold. Decide a policy (treat unknown as at least `moderate`, or surface it as its own bucket) and apply it consistently.

**M2. Dashboard overview totals include waived violations; the gate, analytics, and PDF summary exclude them.** `DashboardController.php:70-90` reads raw stored counts; `ViolationAnalytics::summary()` subtracts waived. A compliance officer sees "Critical: 3" on the overview and "0 open critical" in the exported PDF for the same page. Pick one semantics (or show both "open / waived" columns).

**M3. Scan status lifecycle is vestigial — failures leave no visible trace.** `AccessguardScan.php` defaults status to `'queued'` but the only value ever written is `'complete'` (`ScanRecorder.php:47`). A scanner outage produces only watchdog warnings; the dashboard shows stale "last scan" dates with no failed/pending indicator. Record failed scans (this also gives H2 its discard path a paper trail).

**M4. N+1 queries and unbounded growth in every reporting path.** `ViolationAnalytics.php:169-186` loads nodes, violations, and waivers per scan in a loop; `DashboardController.php` and `ReportHtmlBuilder.php` repeat the pattern; the CSV export buffers the whole file in a string. Meanwhile nothing purges old scans (default daily re-scan ≈ 365 scans/node/year) and `target_entity_type`/`target_entity_id` on scan and waiver entities carry no index (`AccessguardScan.php:36-42`, `AccessguardWaiver.php:39-44`) despite being filtered on in every gate validation and dashboard request. On a 10k-node site after a year this is millions of rows scanned per node save. Batch-load with `loadMultiple`/`IN` queries, add field indexes, and add a retention/purge setting.

**M5. Scan URLs are wrong under CLI: `http://default/node/N`.** `AccessguardScanWorker.php:55`, `AccessguardCommands.php:58`. `toUrl(..., ['absolute' => TRUE])` depends on request context; `drush queue:run` / CLI cron without a configured base URL posts `http://default/...` to the scanner — every scan fails, and H2 then suspends the queue. There is also no setting for a scanner-visible base URL distinct from the public one (a common container-networking need). Add a configurable base URL for scan targets.

**M6. Duplicate enqueues between save hook and cron.** `accessguard.module:96-145` — the cron dedup marker is written only by cron, so a node saved just before a cron run is enqueued twice (two scans, two records). Also the published-nid entity query is unbounded and the `accessguard.cron_enqueued` state array grows to node-count size, (de)serialized every cron run. Write the marker on save-triggered enqueues too; chunk the cron query.

**M7. Render caching disabled wholesale (`max-age: 0`) on the most expensive pages.** `DashboardController.php:120,340`, `AnalyticsController.php:52,86`. Correct today (no staleness, no cross-user leaks) but forfeits all caching exactly where M4 hurts; the render arrays also carry no cacheability metadata from the config/nodes consulted, so naively removing `max-age 0` later would introduce leaks. Move to custom list cache tags + `user.node_grants:view` context when addressing M4.

### Security / configuration

**M8. Scanner shared secret stored in plaintext config and echoed in a plain textfield.** `SettingsForm.php:40-46`, `config/install/accessguard.settings.yml`. The token is exported by `drush cex` (commonly committed to git) and rendered back to anyone with `administer accessguard`. Use the Key module or a settings.php/env override excluded from config export, and don't re-render the stored value.

**M9. Decompression bomb + event-loop blocking in `pinnedFetch`.** `scanner/src/pinnedFetch.js:14-28,67`. The 10 MB body cap counts *compressed* bytes; `gunzipSync`/`brotliDecompressSync` then expand with no `maxOutputLength` — a ~10 MB brotli bomb served by any subresource host on a scanned page expands to GBs and OOMs the service. The sync zlib calls also stall every other in-flight scan's interception handlers. Pass `maxOutputLength` and consider async/streaming decode.

**M10. Shipped ddev compose runs the scanner unauthenticated with private targets allowed.** `.ddev/docker-compose.scanner.yaml:15-19` (`SCANNER_ALLOW_PRIVATE=1`, no token). Mitigated: the service uses `expose:` (not published to host) and the file says dev-only — but a copy-paste to production is silently open to SSRF against internal networks. Require a token even in the dev compose.

### Scanner behavior

**M11. All scan failures collapse to `500 {error: "scan_failed"}`.** `server.js:47-50`, `scan.js:77-85`. Target-404, navigation timeout, axe injection failure, and Chromium crash are indistinguishable to Drupal — which needs to route "author's page is broken" differently from "scanner is broken" (this is also what H2's fix needs). The code already builds a structured `Target returned HTTP ${status}` error, then discards it. Also, a nonexistent hostname surfaces as `400 url_not_allowed`, implying a policy block rather than a typo. Return distinct error codes.

**M12. `waitUntil: 'networkidle0'` makes legitimately busy pages unscannable.** `scan.js:77`. Long-polling, websockets, or analytics beacons prevent networkidle0; `goto` rejects at 20 s and the scan fails even though the DOM was scannable. Fall back to `load`/`networkidle2` or proceed on timeout if the DOM is ready.

**M13. No timeout on axe run or `page.pdf()` beyond the 180 s protocol default.** `scan.js:85-89`, `pdf.js:51-55`. A pathological DOM holds a full Chromium for 3 minutes while the Drupal client has long since timed out. Add a per-request deadline.

**M14. No CSP bypass — security-conscious targets fail axe injection.** `scan.js:85`. Sites whose `script-src` lacks `unsafe-eval` throw on `page.evaluate(axeSource)` and fail with the generic `scan_failed`. Since the scanner fulfills all responses itself, `page.setBypassCSP(true)` is safe and standard here.

**M15. No content-type check — scanning a JSON/image/PDF URL records bogus violations.** `scan.js:82-84` checks `response.ok()` only; axe runs against Chromium's synthesized viewer document and the results are stored as the node's compliance state.

**M16. Dockerfile ignores the lockfile.** `scanner/Dockerfile:3-4` copies only `package.json` and runs `npm install --omit=dev` despite a committed lock and caret ranges — every image build resolves fresh versions and can pull a different Chrome than the base image bakes in. Use `COPY package*.json` + `npm ci --omit=dev`.

**M17. IPv6 is broken end-to-end.** Public IPv6 literal URLs are always rejected (`urlGuard.js:64` — `dns.lookup` on the bracketed hostname throws before `isBlockedIp` runs), and `scan.js:29` emits unbracketed v6 in `host-resolver-rules` (harmless today since interception fulfills everything). Related defense-in-depth gap: `isBlockedIp` misses hex-form IPv4-mapped IPv6 (`::ffff:a9fe:a9fe` = 169.254.169.254 returns *allowed*, `urlGuard.js:8-9,28-33`) — verified not currently reachable (DNS answers render in dotted form, literals never get through), but fragile. Normalize mapped addresses to IPv4 before range checks.

### CI / tests

**M18. GitHub Actions pinned to mutable tags, not SHAs.** `.github/workflows/ci.yml:14,15,27,28,43,44` (`actions/checkout@v5`, `setup-node@v5`, `shivammathur/setup-php@v2`). A compromised tag executes arbitrary code with repo credentials. SHA-pin.

**M19. The core scan pipeline is the least-tested part of the module.** Zero coverage for: the queue worker (including the suspend-on-failure behavior at the heart of H2), the Drush command the README's quick-start depends on, the on-save enqueue hook (the primary trigger in the architecture diagram), and the gate's severity-threshold *ranking* (all `PublishGateTest` cases use the default `critical` threshold — nothing verifies `serious` blocks serious+critical but passes moderate). A regression in the ranking arithmetic would silently weaken the flagship enforcement feature.

**M20. The benchmark doesn't support the README's comparison claims.** `benchmark/run.js`, `benchmark/RESULTS.md`, `README.md:43-60`. All six fixtures target rules *inside* Lighthouse's axe subset, so the harness cannot demonstrate the claimed "full ruleset vs subset" coverage advantage; the committed RESULTS.md shows Lighthouse as "n/a" on every row (never actually run); and pa11y's column is a raw error count in a different taxonomy. Either add fixtures for AA rules outside the Lighthouse subset and run all three tools, or soften the README claim.

**M21. Settings form has no validation.** `src/Form/SettingsForm.php` — `scanner_endpoint` accepts any non-empty string (e.g. `not a url`), which then breaks every scan at runtime with only queue-log warnings (and, via H2, suspends the queue). Validate the URL.

---

## Low-severity findings

- **L1. No `hook_uninstall`** — `accessguard.cron_enqueued` state and queued items survive uninstall. Add `accessguard.install` with state delete + `deleteQueue()`.
- **L2. Waiver check-then-insert race** (`WaiverMatcher.php:55-58`) — concurrent submissions create duplicate waivers (no unique constraint); self-heals on unwaive. Also SQL `=` matching in `deleteWaivers()` is collation-dependent (case-insensitive on MySQL) while the JSON fingerprint is case-sensitive.
- **L3. `PdfClient` returns any 2xx body as "PDF"** (`PdfClient.php:36-42`) — a misconfigured endpoint yields a corrupt download instead of the friendly error path. Check `Content-Type` or the `%PDF` magic.
- **L4. Scan-history pager lacks an id tie-breaker** (`DashboardController.php:243`) — same-second scans can duplicate/skip across pager pages. `RegressionService` and the gate do this correctly.
- **L5. `admin_permission: 'view accessguard reports'` grants full entity CRUD** (`AccessguardScan.php:22`, `AccessguardViolation.php:22`) — harmless today, but if JSON:API/REST is enabled, any report viewer can delete the audit trail. Use a dedicated admin permission.
- **L6. `byAuthor()`/severity buckets drop `unknown`-impact violations entirely; `byRule()` "pages affected" counts fully-waived pages** (`ViolationAnalytics.php:47-64`) — undocumented semantics.
- **L7. Demo fixtures hard-code `full_html`** (`accessguard_demo.install:88`) — on a site without that format the fixtures render empty and trigger none of their advertised violations.
- **L8. `pinnedFetch` drops binary/multipart POST bodies while forwarding the original `content-length`** (`scan.js:66-70`, `pinnedFetch.js:39,44,93-96`) — upstream stalls waiting for bytes that never come.
- **L9. Multiple `Set-Cookie` headers joined with `', '`** (`pinnedFetch.js:78`) — corrupts cookies whose `Expires` contains commas.
- **L10. Socket timeout is idle-based, not a total deadline** (`pinnedFetch.js:91`) — a server dripping 1 byte/19 s holds a scan far beyond 20 s.
- **L11. No graceful shutdown; node is PID 1 without an init** (`server.js:71-74`, `Dockerfile:8`) — no SIGTERM drain; zombie Chromium processes are never reaped after a crash.
- **L12. Express error paths return HTML, not JSON** (`server.js`) — malformed JSON/oversized bodies hit the default handler; also `POST /pdf/` (trailing slash) routes to the pdf handler but through the 1 MB parser, silently shrinking the documented 5 MB limit.
- **L13. Scanner test hygiene** — `scan.test.js:83-117` closes servers before assertions outside `finally` (a failing fetch can hang Jest); tests mutate shared `process.env` without always restoring.
- **L14. phpcs in CI skips `accessguard_demo`; no JS lint; no phpstan; no dependabot/renovate; no CI caching; Kernel tests run on SQLite only** (`ci.yml`) — each individually minor.
- **L15. Stale docs** — `docs/DESIGN.md` components/testing sections lag the analytics/PDF/waiver work; the superpowers *spec* describes an `AnalyticsRepository` that was never built (the plan doc matches reality); `WaiverMatcher.php:29` / `ReportHtmlBuilder.php:173` docblocks claim `"rule|selector"` keys but the code (correctly) uses JSON fingerprints.
- **L16. Untested-but-implemented behaviors** — CSV waived-status column (README claims it, no test), ReportHtmlBuilder escaping beyond titles, RegressionService with 0–1 scans, unwaive form submission, `/scan` non-2xx-target branch, scanner concurrency, pinnedFetch HTTPS/IPv6/POST paths.
- **L17. A few special-use IPv4 ranges not blocked** (`urlGuard.js:11-25`) — documentation ranges (192.0.2.0/24 etc.) and 6to4 relay; not internal networks, minimal impact.

---

## What is done well

Verified strengths, not boilerplate praise:

- **SSRF defense is genuinely sound.** DNS resolved once, every request (navigation, redirects, subresources) re-intercepted and fulfilled over a connection pinned to the vetted IP with SNI/cert checks intact; redirects returned unfollowed so each hop is re-validated; `/pdf` disables JS, maps DNS to NOTFOUND, and aborts sub-frame document loads — the iframe-exfiltration vector is proven blocked by a network-canary test. Private-target scanning is off by default.
- **No injection anywhere.** All SQL uses bound placeholders or entity queries; every dynamic value in the hand-built PDF HTML is `Html::escape`d; dashboard tables use Twig-escaped plain values; the raw `html_snippet` from axe is stored but never rendered; CSV formula injection is neutralized with the correct OWASP trigger set and tested.
- **Access control is disciplined.** Route-level permissions + `_entity_access`, and per-node `$node->access('view')` re-applied inside every aggregation loop (dashboard, CSV, PDF, analytics), with leakage tests replicated across all of them. Waive/unwaive are Form API forms (CSRF-safe) behind a `restrict access` permission. Token comparison is hash-then-`timingSafeEqual` on both endpoints.
- **Subtle integrity cases are handled and tested**: transactional scan+violation writes with rollback; ScanRunner rejects well-formed-but-wrong-shape responses so `null` can't be recorded as a clean scan; node-delete cleanup removes waivers even for never-scanned nodes (node-id reuse); JSON waiver fingerprints prevent delimiter collisions — each with a test proving it.
- **Tests assert adversarial behavior, not trivia**: DNS-rebinding pin verified via an unresolvable `.invalid` hostname, PDF exfiltration canary, cron dedup marker expiry, the publish-gate editor-deadlock regression case, fingerprint collision.
- **CI runs the whole suite** (Jest, PHPUnit Unit + Kernel via SQLite, phpcs Drupal+DrupalPractice). The README's "22 tests" claim is exactly accurate — verified by running them (22/22 pass).
- **Hygiene is clean**: no committed vendor/node_modules/settings/DB files; lockfiles committed; GPL-2.0 license consistent; scanner base image runs as non-root `pptruser`; `browser.close()` in `finally` on every request path; no shared mutable state across scans.
- **Docs are mostly truthful** — routes, permissions, and paths in the README match the YAML; the Lighthouse comparison honestly discloses the shared axe engine (the benchmark gap in M20 notwithstanding).

---

## Test coverage matrix

| Feature | Coverage |
|---|---|
| Scan HTTP client (token, errors, response shape) | Unit — good |
| Scan persistence + severity counts | Kernel |
| Queue worker (incl. suspend-on-failure) | **None** |
| Drush `accessguard:scan` | **None** |
| On-save enqueue hook | **None** (incidental only) |
| Cron rescan (staleness, dedup, marker expiry) | Kernel — excellent |
| Publish gate (transition, bypass, disable, waiver) | Kernel — good |
| Publish gate threshold *ranking* (non-critical thresholds) | **None** |
| Waivers (fingerprint, dedup, delete, collision) | Kernel — good |
| Waive form validation | Kernel |
| Unwaive form submission | **None** (route access only) |
| Dashboard access / node-access filtering | Kernel — excellent |
| Dashboard detail (empty states, pagination) | Kernel |
| Regression diff | Kernel — happy path only |
| Analytics (by-rule, by-author, access, waiver split) | Kernel |
| CSV export (access, formula injection) | Kernel + Unit |
| CSV waived-status column | **None** |
| PDF export (fallback, streaming, HTML report) | Unit + Kernel |
| Settings form save / validation | Kernel / **none exists** |
| Node-delete cleanup | Kernel |
| Scanner: detection, SSRF, rebind pinning, auth, PDF sandbox | Jest — excellent |
| Scanner: error paths, concurrency, non-2xx targets, IPv6 | **None** |

---

## Recommended priorities

1. **Fix the scan-lifecycle trio (H1–H3) together** — re-check `isPublished()` in the worker, replace blanket `SuspendQueueException` with connect-failure-only suspension plus bounded retry/failed-scan recording (needs M11's distinguishable errors), and give unpublished content a legitimate re-scan path.
2. **Make the scanner survivable (H4, H5, M9)** — catch `req.continue()`, cap concurrency, bound decompression output, `restart: unless-stopped` + healthcheck.
3. **Upgrade Puppeteer/Chromium (H6) and fix the Dockerfile lockfile bypass (M16).**
4. **Close the correctness gaps a compliance product can't afford (M1, M2, M3)** — unknown-impact policy, consistent waived semantics, visible scan failures.
5. **Test the untested core (M19)** — queue worker, gate threshold ranking, save hook, Drush command.
6. **Plan for scale (M4, M7)** — batch loading, field indexes, retention policy, then real render caching.
