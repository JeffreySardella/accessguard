# Severity trends dashboard tab — design

Date: 2026-07-16. Approved by Jeff (option "Yes, build it").

## Purpose

Show how the site's accessibility posture has evolved: the scan history
already stores per-severity counts on every scan, but nothing surfaces
change over time — only latest-scan state (Overview, analytics tabs) and
per-node history (detail page).

## Design

### Semantics: a state series, not a scans-per-day series

One row per calendar day (site timezone) that had at least one scan. The
row for day D is "site state as of end of D": for every node with any scan
on or before D, take its latest scan up to D and sum the stored
`count_critical/serious/moderate/minor/needs_review` fields. A day with
many scans therefore does not inflate the numbers; a node's newer scan
replaces its older one in the fold.

Counts are **as recorded at scan time**. Waivers are not applied
retroactively — historical waiver state is not reconstructable — so this
tab can legitimately differ from the Overview's open-violation numbers.
The tab renders this note.

### Components

1. **`ScanRepository::allScanMeta(): array`** — one query returning every
   scan's `nid`, `created`, and the five count fields, ordered by
   `(created, id)` ascending. No entity loads.

2. **`TrendBuilder` service** (`Drupal\accessguard\Service\TrendBuilder`,
   `accessguard.trend_builder`) — `dailySeries(): array` returns rows
   `[date: 'Y-m-d', critical: int, serious: int, moderate: int,
   minor: int, needs_review: int, total: int]`, oldest first (the
   controller reverses for display). Node-access filtered against the
   current user, matching `ViolationAnalytics`'s posture: scans of nodes
   the user cannot view (or whose node is gone) are excluded entirely.
   `total` = critical + serious + moderate + minor (needs-review is
   uncertain by definition and stays out of the total, consistent with the
   gate and summary treatment).

3. **`AnalyticsController::trends()`** — renders the note plus a table
   (Date / Critical / Serious / Moderate / Minor / Needs review / Total),
   newest first, with the same cache tags
   (`accessguard_scan_list`, `node_list`) and contexts
   (`user.node_grants:view`, `user.permissions`) as the other tabs.
   Empty state: "No scans recorded yet."
   Route `accessguard.analytics_trends` at
   `/admin/reports/accessguard/trends`, permission
   `view accessguard reports`; local task "Trends", weight 30.

### Out of scope (YAGNI)

- Charts or any JS/SVG visualization.
- Date-range filtering or pagination (one row per scan-day; small).
- Per-node trends (the node detail page owns that).
- Retroactive waiver application (impossible honestly).

## Error handling

No user input beyond the route; an empty scan table yields the empty
state, not an error. Deleted nodes' scans are skipped by the access
filter (a NULL node cannot be access-checked).

## Testing

Kernel tests:

- `TrendBuilder`: two nodes scanned across three days — day rows reflect
  latest-scan-per-node-as-of-day sums (a re-scan replaces the node's
  earlier counts in later days); same-day double scan of one node uses
  only the later scan; inaccessible (unpublished) node's scans excluded;
  needs_review excluded from total.
- Controller route: renders for a user with `view accessguard reports`,
  contains the waiver note and a data row; tab appears.
