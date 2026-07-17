# Per-content-type re-scan policies — design

Date: 2026-07-17. Approved by Jeff (scope, storage, gate behavior, and
approach each confirmed via clickable questions).

## Purpose

Let each content type define its own re-scan policy instead of the single
global setting: news can re-scan daily while rarely-changing policy pages
re-scan weekly, and types that should never be scanned or gated (e.g. content
that never renders as a public page) can opt out of AccessGuard entirely.

## The problem it solves

Re-scanning today is all-or-nothing: one `rescan_enabled` switch and one
`rescan_interval` for every node on the site. Sites with mixed content either
over-scan static types (wasted scanner load, noisy history) or under-scan
fast-moving ones. There is also no way to tell AccessGuard to ignore a
content type.

## Design

### 1. Storage: third-party settings on the node type

Each `NodeType` config entity carries third-party settings under the
`accessguard` module:

- `rescan_mode`: `inherit` (default) | `custom` | `disabled`
- `rescan_interval`: integer seconds, meaningful only when mode is `custom`

Config schema added for `node.type.*.third_party.accessguard`. The policy
travels with the type in config export and dies with the type on delete —
no orphan handling needed.

Semantics:

- `inherit` — the type follows the global settings, exactly as today.
- `custom` — cron uses the type's own interval instead of the global one.
- `disabled` — AccessGuard ignores the type: no cron re-scans, no
  save-triggered scans, and the publish gate / CI gate exempt it (see §3).

The global `rescan_enabled` stays the master kill-switch for automatic
scanning; `disabled` is per-type and stronger (applies even when the global
switch is on). The global `rescan_batch` cap is unchanged and global.

### 2. `RescanPolicy` service (new)

`Drupal\accessguard\Service\RescanPolicy` (`accessguard.rescan_policy`) owns
the interpretation of the three modes — the same centralization move as
`GateEvaluator` and `Severity`:

- `isExcluded(string $bundle): bool` — TRUE when the type's mode is
  `disabled`.
- `intervalFor(string $bundle): int` — the type's own interval when mode is
  `custom` and the stored value is ≥ 60; otherwise the global
  `rescan_interval` (falling back to 86400). Unknown bundles (deleted type,
  non-node callers) resolve as `inherit`.

Dependencies: entity type manager (node_type storage) and config factory.

### 3. Enforcement points

- **Save trigger** — `_accessguard_enqueue_scan()` returns early for
  excluded bundles: no queue item, no cron dedup marker.
- **Cron** — `accessguard_cron()`'s published-node query becomes an
  aggregate entity query returning `nid` + `type`, so each node is compared
  against its own cutoff `now − intervalFor(bundle)`. Excluded bundles are
  skipped entirely. The dedup-marker logic is unchanged except that the
  marker-freshness comparison uses the per-node cutoff.
- **Gate** — `GateEvaluator::blockingCount()` returns NULL ("nothing to
  gate on") when the node's bundle is excluded. Both consumers — the
  publish-gate constraint validator and `drush accessguard:gate` — inherit
  the exemption with no changes of their own. This closes the stale-scan
  deadlock: without it, a type excluded after a failing scan could never be
  published again (re-scans are off, so the old scan would gate forever).
- **Manual scans** — `drush accessguard:scan` still enqueues excluded
  types: an explicit ask is an explicit ask (matching the gate command's
  posture on unpublished nids). The queue worker needs no change because
  policy checks live at enqueue time.

### 4. UI: node-type form

A form alter on the node-type add/edit form adds an **AccessGuard** group in
the type form's vertical tabs:

- Re-scan policy select: *Site default* / *Custom interval* / *Disabled —
  never scan or gate this content type*.
- Interval number field (seconds, `#min` 60), visible only for *Custom*
  via `#states`, required when visible.

Saved through an entity builder into the third-party settings. `inherit`
stores no interval. The main AccessGuard settings form is untouched except
the global interval's description gains a note that content types can
override it.

### 5. Explicitly out of scope (YAGNI)

- Filtering excluded types out of dashboards, analytics, or the Trends tab:
  existing scan data remains visible as history — consistent with the
  Trends tab's "as-scanned" stance.
- Per-type gate thresholds (the gate policy stays global).
- Policies for entity types other than nodes (nothing else is scanned).
- Purging an excluded type's existing scans (retention handles aging).

## Error handling

- `custom` mode with a missing or sub-60 stored interval (hand-edited
  config): `intervalFor()` falls back to the global interval rather than
  scanning in a tight loop. The form prevents this state from the UI.
- Deleted node type mid-queue: the queue item scans normally (the node
  still exists; policy checks were at enqueue time) — no special handling.

## Testing

Kernel tests, TDD:

- `RescanPolicyTest`: resolution matrix — inherit/custom/disabled, custom
  with invalid interval falls back to global, unknown bundle inherits.
- `CronRescanTest` additions: two types with different custom intervals get
  different staleness cutoffs in one cron run; a `disabled` type is never
  enqueued no matter how stale; dedup marker still respected per-type.
- Save-hook: saving a node of a `disabled` type enqueues nothing.
- `PublishGateTest` addition: a node of a `disabled` type with blocking
  violations in its latest scan saves in a published state (gate exempts
  it); `AccessguardCommandsTest` addition: the gate command skips it and
  exits 0.
- Node-type form entity builder round-trip (also exercises the config
  schema, which kernel tests validate strictly).
