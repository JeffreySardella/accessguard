# CI gate command (`drush accessguard:gate`) — design

Date: 2026-07-16. Approved by Jeff (option "Yes, build it").

## Purpose

Give build pipelines a single command that answers "does any live content
currently violate the accessibility gate policy?" with a process exit code,
so a deploy or nightly job can fail on regressions the same way the publish
gate blocks an editor.

## The problem it solves

The gate policy (which violations block, at what severity, respecting
waivers) exists only inside `AccessguardGateConstraintValidator`, reachable
solely by saving a node in a published state. CI has no way to evaluate it.

## Design

### 1. `GateEvaluator` service (new)

The gate policy moves out of the constraint validator into
`Drupal\accessguard\Service\GateEvaluator`, the single owner of "how many
violations of a node's latest scan block at the configured threshold":

- `blockingCount(int $nid): ?int` — NULL when the node has never been
  scanned (nothing to gate on); otherwise the count of violations in the
  node's latest scan whose `Severity::rank()` is at or above the configured
  `gate_threshold` rank, excluding waived fingerprints, and excluding
  `needs_review` results unless `gate_includes_needs_review` is set.

The constraint validator keeps its own concerns (published-transition
detection, bypass permission, `gate_enabled` check) and delegates the
counting to `GateEvaluator`. One policy, two consumers — the same
centralization move as `Severity`.

### 2. `accessguard:gate` command

`drush accessguard:gate [nid]` in the existing `AccessguardCommands` class:

- **No argument**: evaluates every *published* node that has at least one
  scan (published content is what CI cares about; drafts are already held
  by the interactive gate). Latest scan ids come from
  `ScanRepository::latestScanIdByNode()`.
- **With `nid`**: evaluates just that node (published or not — an explicit
  ask is an explicit ask). Unknown nid → `\InvalidArgumentException`.
- **No node-access filtering**: CLI is a trusted operator, matching the
  validator's `accessCheck(FALSE)` posture.
- **Ignores `gate_enabled`**: invoking the command is the opt-in; that flag
  governs the interactive publish gate only. (Documented in the command
  help.)
- **Output**: `RowsOfFields` (nid, title, blocking count) so Drush's
  `--format=table|json|csv` work natively; a summary line via `io()`.
- **Exit code**: 0 when no evaluated node has a blocking count > 0;
  1 otherwise (via `CommandResult::dataWithExitCode()`). Never-scanned
  nodes don't appear and don't fail the gate.

### 3. Explicitly out of scope (YAGNI)

- A `--threshold` override (config is the policy; CI shouldn't fork it).
- Including unscanned nodes as failures (a separate "coverage" concern).
- Entity types beyond nodes (nothing else is scanned today).

## Error handling

- Unknown nid: `\InvalidArgumentException` (Drush exits non-zero, message
  states the nid) — consistent with `accessguard:scan`.
- No scanned published nodes at all: exit 0 with an explicit "nothing to
  evaluate" notice — an empty site is not a failing site.

## Testing

Kernel tests (same harness as `PublishGateTest`):

- `GateEvaluator`: threshold rank comparison (critical vs serious
  boundaries), waived fingerprint exclusion, needs-review excluded by
  default / included when configured, unknown impact ranking alongside
  moderate, NULL for never-scanned.
- Command: exit code 1 with a blocking violation on a published node;
  exit 0 when the violation is waived; single-nid evaluation; rows content.
- `PublishGateTest` (existing) pins the validator's behavior across the
  refactor.
