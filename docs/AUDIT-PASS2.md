# AccessGuard — Second-Pass Audit (2026-07-11)

A deeper follow-up to `AUDIT.md`, run after the first-pass remediation merged (PRs #1, #4). Six independent investigators covered ground the first pass didn't: the WCAG-ruleset claim, the tool's own UI accessibility, detection completeness, governance continuity across engine change, a security re-review of the remediation code itself, and supply-chain. Every finding below was verified against the actual code / the installed axe-core 4.12.1.

> **Remediation status (2026-07-11):** Phases 1–2 fixed and merged (PR #5): H-P2-1 (token export leak), M-P2-2 (latest-scan ordering), M-P2-3 (field overflow), L-P2-1 (waiver lock), L-P2-3 (engines.node), and A-P2-1…5 (self-UI accessibility). H-P2-4 (record axe engine version per scan) is now also fixed — the foundational enabler for the cross-version continuity warnings. Still open (need product decisions, tracked): H-P2-2 (record `incomplete`/needs-review results — decide whether they gate), H-P2-3 (scan iframes), H-P2-5 + M-P2-5 (rule-level waivers + cross-version diff warnings), M-P2-1 (iframe/shadow selector delimiter — pairs with H-P2-3), M-P2-4 (SPA settle + viewport policy), and the doc/i18n lows.

Two premises from the investigation briefs were **corrected** during the work and are noted here for honesty:
- The Puppeteer 23→25 bump does **not** change axe-core — axe-core is a *direct* dependency (`^4.10.0`, currently floated to 4.12.1 via the caret). The engine-drift risk is real but its trigger is the caret float, not the Puppeteer bump.
- `RegressionService` diffs by `rule_id` **alone**, not `(rule_id, selector)`.

## Verified NON-findings (reassuring results)

- **The "full WCAG 2.2 AA ruleset" claim is accurate.** The five-tag array in `scanner/src/scan.js` is axe's complete WCAG 2.2 A+AA automated selection. Empirically confirmed the flagship 2.2 rule `target-size` (SC 2.5.8) *does* fire despite its `enabled:false` default (explicit tag inclusion overrides that), and the "missing" `wcag22a` tag drops nothing (axe defines no such tag; WCAG 2.2's new Level-A criteria have no automated axe rules). Deprecated Level-A rules tied to the removed SC 4.1.1 are correctly excluded.
- **Supply-chain is clean.** `npm audit` (prod): 0 vulnerabilities. Drupal 11.4.1 is current/security-supported. Licenses compatible (axe-core MPL-2.0 and Puppeteer Apache-2.0 both fine under the project's GPL-2.0-*or-later* grant). No committed `node_modules`/`vendor`. Lockfile in sync.
- **ScanRecorder transactions and config schema are correct.** Append-only writes, atomic scan+violation rollback, and schema/install/reads all consistent (incl. the new `scan_base_url`/`retention_days`).
- **Retention purge, env-token override, and the HMAC token compare are sound.** (See the security section for the one lock weakness that is *not* clean.)
- **`html_snippet` cannot overflow** (axe caps `node.html` at 300 chars; the column is TEXT). The 255-char string fields (`rule_id`, `impact`, `wcag_criterion`) all receive short, bounded inputs.
- **Open shadow DOM is scanned** (native to `axe.run`); only iframes are the gap.

## Findings

### HIGH

**H-P2-1 — Scan-access token leaks into the stored URL and audit exports.**
`ScanAccessToken` appends a signed token as a `?accessguard-scan-token=` query param for unpublished-node scans; the scanner echoes the URL back, and `ScanRecorder::record()` (`src/Service/ScanRecorder.php:43`) stores it verbatim into the scan's `url` field, from where it is rendered into the CSV export (`DashboardController.php`) and the PDF report (`ReportHtmlBuilder.php:128`). The token is a bearer credential valid up to 1 hour, so any recipient of an exported audit CSV/PDF can replay `…/node/N?accessguard-scan-token=…` anonymously and view the unpublished node within the window. *This is a regression introduced by the first-pass remediation.* **Fix:** strip the token query arg before persisting the URL (kills the CSV/PDF leak and every downstream surface at once); optionally shorten `LIFETIME` and move the token to a request header long-term. Related MEDIUM vectors from the same design: leak to third-party hosts via `Referer` if a draft sets a permissive referrer policy (`scan.js`/`pinnedFetch.js` forward the browser `Referer`), and the token landing in the target's web-server access logs.

**H-P2-2 — axe `incomplete` ("needs review") results are silently discarded.**
`scanner/src/scan.js` records only `results.violations` and drops `results.incomplete`. Two axe mechanisms populate that bucket with *real* potential failures: `reviewOnFail` rules (e.g. `button-name` named only via `title`) and `color-contrast` "can't tell" over background images/gradients. So white text on a dark hero image with sub-4.5:1 contrast — a genuine WCAG 1.4.3 failure — reports as **clean and can clear the publish gate**. **Fix:** capture `incomplete` and persist as a distinct `needs_review` status. *Policy decision:* whether needs-review items block the gate or are surface-only.

**H-P2-3 — iframe content is never scanned, with no warning.**
axe is injected only into the main frame (`scan.js`), so same- and cross-origin `<iframe>`s are skipped: axe pings the frame, times out, and `resolve(null)`s silently. The one rule that would flag it (`frame-tested`) is filtered out by the WCAG-only tag set. An embedded consent form or app view with unlabeled fields is invisible to the scan. **Fix:** inject axe into every `page.frames()` before running (or adopt `@axe-core/puppeteer`).

**H-P2-4 — No axe engine version is recorded per scan.**
`AccessguardScan` stores no `engine_version`, and `scan.js` never reports `window.axe.version`. This makes the system structurally unable to detect or warn when the engine drifts (the `^4.10.0` caret has already floated 4.10→4.12.1), which is the root enabler of the waiver/regression continuity hazards below. **Fix (foundational, cheap):** capture `window.axe.version`, thread it through `ScanRecorder`, add an `engine_version` field, and warn when a diff/waiver spans two engine versions.

**H-P2-5 — Waiver fingerprints are brittle exact `(rule_id, selector)` matches.**
`WaiverMatcher::fingerprint()` is `json_encode([ruleId, selector])` with no normalization or fallback. A selector shift (an `:nth-child` index change from a DOM edit, or an axe-version change to the selector algorithm) makes a human-accepted waiver silently stop matching — the violation reappears as "open" and re-blocks the publish gate on an already-signed-off page, while the waiver row becomes a dangling no-op. **Fix (design decision):** offer a rule-level (selector-optional) waiver so accepted risks survive DOM/engine churn, and flag stale waivers whose selector no longer appears in the latest scan.

### MEDIUM

**M-P2-1 — `node.target.join(' ')` collapses iframe/shadow boundaries → fingerprint collisions.**
`scan.js` flattens axe's frame-aware `target` array with a single space. Two distinct elements (e.g. a `button` inside `<iframe id="promo">` and a real descendant of a same-document `#promo`) can flatten to the same `"#promo button"` string; with the same rule id they share one fingerprint, so a single waiver over-suppresses both and an unreviewed violation escapes the gate. **Fix:** preserve frame structure with a distinguishable delimiter and/or disambiguate the fingerprint with the stored `html_snippet`.

**M-P2-2 — "Latest scan" is defined two different ways.**
`ScanRepository` uses `MAX(id)`; the gate, `RegressionService`, and the detail page's violations/history use `created DESC, id DESC`. `created` = request time, `id` = commit order — these agree only incidentally. Under concurrent scanning of one node (a save-scan racing a cron/drush worker) or a backward clock step, the dashboard/CSV/PDF/analytics can name a different "latest" scan than the gate enforces against, and the detail page can show author attribution from one scan and violations from another. **Fix:** give the repository one ordering that matches the gate (`created DESC, id DESC`), via a window function or correlated subquery (not a naive `MAX(created)` self-join, which reintroduces a tie bug).

**M-P2-3 — `selector`/`url`/`help_url` overflow can abort the whole scan.**
`ScanRecorder` calls `->save()` without `->validate()` or truncation. axe selectors for deeply-nested DOM grow ~12 chars/level; ~170 levels crosses the 2048-char `selector` column (measured 1216 chars at 100 levels). On MySQL-strict/Postgres this throws `SQLSTATE 22001`, and because the write is transactional the **entire scan (row + all violations) fails to persist** over one long selector; on MySQL non-strict it silently truncates to a corrupt element pointer. **Fix:** `mb_substr`-cap `selector`/`url`/`help_url` to their column widths before `create()`.

**M-P2-4 — SPA hydration timing and default viewport.**
The scan waits for `load` + a 5s-capped network-idle window, which can fire before a client-rendered app hydrates — axe then scans an empty shell and under-reports (or scans a spinner and false-positives). Separately, no viewport is set, so scans run at Puppeteer's default 800×600 — a blind spot for viewport-dependent WCAG 2.2 rules (2.5.8 target-size, 1.4.10 reflow), missing mobile-only issues and mis-measuring geometry. **Fix (behavior decision):** add a render-settle gate; set an explicit viewport, ideally a desktop + mobile pass.

**M-P2-5 — Regression diff churns on rule-id renames across engine versions.**
`RegressionService` diffs by `rule_id`; an axe upgrade that renames/splits/merges rule ids produces a flood of false "new"/"fixed" entries for the same underlying defect. Reporting-surface only (doesn't gate). **Fix:** with H-P2-4's engine version, annotate diffs that span an upgrade.

### Self-UI accessibility (the tool failing its own standard)

**A-P2-1 (High/Level-A) — the PDF audit report `<html>` has no `lang`** (`ReportHtmlBuilder.php:38`) — WCAG 3.1.1. Especially notable: this is the artifact an auditor reads.
**A-P2-2 (Medium/Level-A) — the PDF report has no `<title>`** — WCAG 2.4.2.
**A-P2-3 (Medium) — the overview and both analytics tables lack a `#caption`** (`DashboardController.php`, `AnalyticsController.php`) — WCAG 1.3.1; the detail page's tables already set one.
**A-P2-4 (Medium) — the PDF report `<th>` cells lack `scope="col"`** (`ReportHtmlBuilder.php`) — WCAG 1.3.1 (H63).
**A-P2-5 (Low) — the overview page skips a heading level** (h1 route title → h3 item_list, no h2) — WCAG 1.3.1.
Verified non-issues: `#666`-on-white text computes to 5.74:1 (passes AA); waived status is not color-only; all forms have proper labels.

### LOW

- **L-P2-1 — waiver lock proceeds without the lock after a failed re-acquire** (`WaiverMatcher::createWaiver`, the second `acquire()` after `wait(5)` is unchecked) — under >5s contention the check-then-insert runs unserialized, the exact race the lock was added to close. *Regression from the first-pass remediation.* **Fix:** check the re-acquire result and bail (the dedup existence-check still prevents most duplicates).
- **L-P2-2 — token `LIFETIME` of 1 hour is generous** for a query-string credential (compare itself is constant-time and sound). Shorten to shrink every leak window.
- **L-P2-3 — `scanner/package.json` has no `engines.node`** despite CI pinning Node 22.
- **L-P2-4 — the PDF report is English-only** (`ReportHtmlBuilder` builds raw HTML, no `t()`); acceptable for a US/508 context but a real limitation for multilingual sites. (Logger/CLI strings correctly untranslated.)
- **L-P2-5 — retention purge is scoped to `MAX(id)` of node-type scans only**; harmless today (all scans are node scans) but would not protect a future non-node scan type's latest row.

## Suggested phasing

1. **Security + data integrity (unambiguous):** strip token from stored URL (H-P2-1), cap selector/url lengths (M-P2-3), fix the waiver-lock re-acquire (L-P2-1), align `ScanRepository` ordering with the gate (M-P2-2), disambiguate the iframe/shadow selector fingerprint (M-P2-1), add `engines.node` (L-P2-3).
2. **Self-UI accessibility (unambiguous, mechanical):** PDF `lang`/`<title>`/`scope`, table captions, heading fix (A-P2-1…5).
3. **Detection completeness (needs product decisions):** capture axe version per scan (H-P2-4) + record `incomplete` as needs-review (H-P2-2) — decide whether needs-review gates; inject axe into all frames (H-P2-3); render-settle + viewport policy (M-P2-4).
4. **Governance continuity (features):** rule-level waivers + stale-waiver flagging (H-P2-5), cross-version diff warnings (M-P2-5).
