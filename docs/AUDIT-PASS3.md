# AccessGuard — Third-Pass Audit & Research (2026-07-11)

A deep, ten-track investigation commissioned after the first two audit passes (PRs #1, #4, #5, #6) had merged. Where passes 1–2 hunted correctness/security bugs, this pass covers **external landscape** (competitive, legal/standards, WCAG coverage) and **deeper internal analysis** (adversarial red-team, scale modeling, Drupal contrib-readiness, scanner ops-readiness, remaining-code correctness, test-suite quality, architecture/data-model). All findings verified against the code / the installed axe-core 4.12.1 / cited web sources.

## The headline: an honesty problem, triangulated by three independent tracks

The competitive, legal, and WCAG-coverage investigations independently reached the **same** conclusion: the project's central claims overstate what it delivers.

- **axe covers 23 of the 55 WCAG 2.2 A/AA success criteria (~42%)**, only ~5–6 comprehensively; 58% are manual-only (captions, focus order/visible, reflow, non-text contrast, error handling, and 6 of the 7 new WCAG 2.2 criteria). Automated testing catches ~30–57% of *issues* and definitively evaluates ~a third of *criteria*. **A page with zero axe violations can still fail ~70% of WCAG AA criteria.**
- So "runs the full WCAG 2.2 AA ruleset," the "Compliance" dashboard, and "prove ongoing accessibility compliance / audit-ready reports" invite the exact false inference (clean scan == compliant) that regulators criticize in overlay marketing.
- **Legal currency:** the DOJ's April 2024 ADA Title II rule deadlines were **extended one year** by an April 2026 interim final rule — population ≥50k now **April 26, 2027**, <50k/special districts **April 26, 2028** — and the binding standard is **WCAG 2.1 AA** (not 2.2, which the scanner hard-codes). California's **AB 434 (Gov Code §11546.7)** requires a biennial certification **personally signed by the agency director and CIO** — an attestation AccessGuard produces nothing toward.
- **The export is VPAT/ACR *input*, not a VPAT:** it's a rule-centric defect list; a real Accessibility Conformance Report is *per-success-criterion* (Supports / Partially / Does Not / Not Applicable) with an evaluation-methods statement.

**The fix is honesty, not retreat.** AccessGuard's real, defensible wedge (validated by the competitive research) is genuinely strong: it is the **only open-source, CMS-native, enforce-at-publish** accessibility governance tool — every commercial competitor (Siteimprove, Level Access, Deque axe Monitor, Monsido, Silktide) monitors from *outside* the CMS at $15k–$150k+/yr and structurally cannot block a publish inside Drupal's save workflow. Lead with that; stop claiming full-WCAG conformance.

## Findings by track (severity-ranked)

### Security — adversarial red-team

- **HIGH — scanner is an author-triggered DDoS amplifier/reflector.** Scans run with JavaScript **enabled** and the interceptor fulfills *every* outbound request with **no cap on request count or total bytes**. An author embeds `<script>for(i…)fetch('https://victim/'+i)</script>` in a draft → the scan runs it → floods a third party from the scanner's IP for the ~60–80 s scan lifetime. Fix: cap intercepted requests + total bytes per scan; optionally block page scripts (axe needs only the post-load DOM).
- **MEDIUM — publish gate bypassable by scan-timing race** (save clean → records clean scan → edit to add violations + publish → gate reads the stale clean scan), compounded by new-nodes-at-creation never gated and content never re-gated after first publish.
- **MEDIUM — queue flooding:** the save path has no dedup (only cron does), so mass-saving floods the scan queue/scanner.
- **LOW** — Referer token leak (known); audit trail erasable via node delete.
- **Cleared as safe** (negative results): request smuggling, response splitting, queue-payload injection, record back-dating/forgery, token-grant scope, cross-author gate poisoning.

### Scanner operational-readiness

- **CRITICAL — `--disable-dev-shm-usage` missing** from `scan.js` and `pdf.js` launch args. Containers default to 64 MB `/dev/shm`; Chromium exhausts it on non-trivial pages and the renderer crashes (`SIGBUS`/"Target closed") — the most common headless-Chromium-in-Docker failure, surfacing as intermittent unexplained 500s.
- **HIGH — 503 shed burns the Drupal retry budget.** On a 503 `scanner_busy`, the worker's `isHealthy()` probes `/health` (always 200), misclassifies the shed as an item-specific failure, and consumes a retry attempt — a transiently-saturated scanner silently, permanently drops valid scans. Fix: a `/ready` endpoint reflecting saturation; don't burn attempts on 503.
- **HIGH — liveness/readiness conflated** (`/health` passes while Chromium can't launch) and **no structured logging/metrics/correlation-id**.
- **MEDIUM** — no container memory limit; hardcoded timeouts (nav 20 + axe 60 > the Drupal 60 s Guzzle timeout — a slow-but-valid scan exceeds the client deadline); horizontal scaling needs an L7 LB. Keep fresh-browser-per-request (leak-safe) over the roadmap's pool.

### Scale & performance

- **HIGH — dashboard/analytics OOM at scale.** `ViolationAnalytics::buildScanContext()` hydrates every latest scan + node + violation with no pagination: >10 GB at 100k nodes, ~1 GB and still OOM at 10k. Fix: push open/waived counts to SQL `GROUP BY`, paginate, load violation entities only for nodes with waivers.
- **HIGH — throughput ceiling:** a single sequential cron queue runner caps at ~10k scans/day; 100k-daily is ~10× short, degrading "daily re-scan" to ~10-day cadence.
- **MEDIUM/HIGH — missing composite index** `(target_entity_id, created, id)` for the `NOT EXISTS` latest-scan query and every gate/detail/regression lookup.
- **MEDIUM — the cron-dedup state array is a per-save hot path:** every node save (de)serializes the up-to-2.8 MB `cron_enqueued` blob (regression introduced by pass-1). Should be a dedicated table.
- **MEDIUM** — report caches invalidated continuously under scanning (near-useless); `retention_days: 0` unbounded-growth footgun default.

### Drupal contrib-readiness

- Code quality is **excellent** (exemplary DI, zero `\Drupal::` in classes, no deprecated D11 APIs, complete config schema, no committed secrets) — passes Drupal.org's deprecation/CI-required checks today.
- **Blockers (metadata/packaging):** `accessguard.info.yml` declares **no `dependencies:`** despite hard-requiring `node` + the Views `EntityViewsData` handler (**B1**); no module `composer.json` (B2); no module `README`/`hook_help` (B3); demo module sits as a top-level sibling (B4).
- **Best practice:** `hook_requirements('runtime')` using the existing `ScanRunner::isHealthy()`; `configure:` link; `.cspell` word list.
- **Robustness smell:** `AccessguardScanWorker::processItem($data)` reads `$data['nid']` unguarded but defends with `is_array()` on the failure path — asymmetric.
- **Feature gap:** content **translations are never scanned** (canonical-only; no `target_langcode` field) — material for the multilingual gov audience.

### Remaining-code correctness

- **MEDIUM — `scanner_endpoint` validated trimmed but saved untrimmed** (`SettingsForm`): a pasted leading space passes validation, is stored, and breaks every scan (`ScanRunner` only `rtrim`s the trailing slash). `scan_base_url` on the adjacent line *is* trimmed — the asymmetry is unintentional.
- **LOW/MED** — detail-page history counts (raw `count_*`) disagree with the violation list for unknown-impact scans (no `count_unknown`); waive/unwaive gated on `node.view` not `node.update`.
- **LOW** — `RegressionService` labels a first-ever scan's findings "new"; demo's unfiltered text format survives uninstall (latent stored-XSS surface); empty rule/selector waiver (theoretical).
- **CsvSafe verified correct and bypass-proof** (leading-whitespace, unicode-first-byte, post-quoting all checked).

### Test-suite quality (mutation-testing lens)

- Suite is **unusually strong** (tight exact-count assertions, mutation-resistant SSRF-range tests, process-isolated, minimal smells).
- **HIGH gaps (security controls, completely dark):** token **expiry** is never tested with a valid-signature-but-past-timestamp ("delete the expiry line, suite stays green" → leaked scan URL valid forever); the scanner's **per-request SSRF re-validation** of subresources/redirects is untested ("remove it, every test still passes").
- **MEDIUM** — `hook_node_access` array-param guard + cacheability metadata; `byPage()`/`summary()` unknown bucketing; `ScanRecorder` impact normalization; a fingerprint **test smell** (expected value computed by the function under test → case-insensitivity regression invisible).

### Architecture / data-model / DX

- **Swap hand-rolled axe injection for `@axe-core/puppeteer`** (keeping the custom SSRF/pinning layer) — retires the iframe, dropped-`incomplete`, selector-flattening, and engine-version findings *by construction*. Highest architectural leverage.
- **Severity taxonomy duplicated across 3 files** (ScanRecorder / gate / ViolationAnalytics) — root cause of the pass-2 M1 unknown-impact drift. Extract a `Severity` value object. Same for latest-scan ordering (re-implemented 3×).
- **No extension surface** — add `PublishBlockedEvent`/`ScanRecordedEvent`/`RegressionDetectedEvent`; the gate-block and regression moments are entirely unhookable, and that's the core governance value-add.
- **Half-polymorphic `(target_entity_type, target_entity_id)` key** pays for polymorphism it never uses → manual integrity, node-id-reuse hazard; a real `entity_reference` to node retires the custom index + most delete-cleanup.
- **Denormalized `count_*` "look authoritative and lie"** (can't reflect waivers/unknown); stop hard-deleting waivers (audit trail vanishes on unwaive); a clean additive `result_type`/`count_needs_review` schema is designed for the deferred needs-review feature.

## Verified NON-findings / strengths (reassuring)

- The SSRF/DNS-pinning transport is genuinely sound and worth owning (no library replaces it).
- Supply-chain clean (0 npm prod advisories; Drupal 11.4.1 supported; axe-core MPL-2.0 / Puppeteer Apache-2.0 compatible under GPL-2.0-or-later).
- The "full WCAG 2.2 AA **ruleset**" claim is technically accurate *about axe's automated ruleset* (target-size fires; nothing dropped) — the problem is only the leap from "ruleset" to "conformance."
- DI, transactions, cache metadata discipline, and the test suite are all above-average.

## Remediation plan

**Phase A — no-decision fixes (do now):**
1. `--disable-dev-shm-usage` (+ `shm_size`) — ops CRITICAL.
2. DDoS-amplifier cap (request count + total bytes per scan) — red-team HIGH.
3. Honesty reframe (README/DESIGN language) + automated-coverage disclaimer on exports/dashboard + updated ADA deadlines — triangulated.
4. Composite index `(target_entity_id, created, id)`.
5. 503 retry-budget fix + `/ready` endpoint.
6. `scanner_endpoint` trim-on-save.
7. `info.yml` dependencies (B1); `hook_requirements('runtime')`; QueueWorker `$data` guard; native return/param types.
8. Security test gaps: token expiry, subresource-SSRF re-validation.
9. `Severity` value object extraction; DESIGN.md refresh.

> **Remediation status (2026-07-11):** Phase A fixes merged (PRs #7, #8), including the `Severity` value-object extraction. **Detection completeness — the `incomplete`/needs-review half — is now done too:** the scanner captures axe's `incomplete` bucket, it's stored as `needs_review`-typed violations with a `count_needs_review`, surfaced on the dashboard and PDF, and gated only when the new `gate_includes_needs_review` config flag is on (default off — surface-only, no silent gate-tightening). The remaining detection item (iframe scanning via `@axe-core/puppeteer`) and the rest of Phase B are still open.

**Phase B — product decisions (need the owner's call):**
- Detection completeness: the `incomplete`/needs-review piece is done (see remediation note above). Still open: **iframe scanning** via `@axe-core/puppeteer` (a larger change that re-integrates with the SSRF interception layer).
- Dashboard scale rework (SQL counts + pagination); parallel queue consumers + concurrency; retention default; state-array → table.
- VPAT/ACR-shaped export + manual-attestation workflow (the biggest product bet; the competitive/legal tracks both rank it the top category gap).
- Domain events; `entity_reference` target migration; waiver audit-trail durability; multilingual/translation scanning; ticketing hook.
- Contrib packaging (B2–B4) if Drupal.org publication is intended.
