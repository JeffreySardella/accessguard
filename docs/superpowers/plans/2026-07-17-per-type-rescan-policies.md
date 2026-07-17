# Per-Content-Type Re-scan Policies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each content type inherit, override, or opt out of AccessGuard's re-scan cadence — with opted-out types also exempt from the publish gate.

**Architecture:** Policy lives as third-party settings (`rescan_mode`: inherit | custom | disabled, plus `rescan_interval`) on the `NodeType` config entity, edited on the content-type form. A new `RescanPolicy` service is the single interpreter; the save hook, cron, and `GateEvaluator` consult it. Spec: `docs/superpowers/specs/2026-07-17-per-type-rescan-policies-design.md`.

**Tech Stack:** Drupal 11 custom module (`web/modules/custom/accessguard`), PHPUnit kernel tests, phpcs (Drupal + DrupalPractice).

## Global Constraints

- All commands run from the repo root on the host; PHP runs inside DDEV via `ddev exec`.
- Kernel test runs need the env var prefix inside the container: `ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core <path>"`. Run these through the Bash tool — PowerShell mangles the quoting.
- Kernel tests validate config schema strictly: saving a `NodeType` with third-party settings fails with `SchemaIncompleteException` until the schema entry exists.
- phpcs must stay clean: `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo`
- Commit messages: `feat(rescan): ...` / `test(rescan): ...`, ending with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Comment style: comments state constraints/rationale, not narration; match the existing files' density.
- Do NOT touch the live DDEV site config; everything here is code + tests.

---

### Task 1: RescanPolicy service + config schema

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/RescanPolicy.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml` (append service)
- Modify: `web/modules/custom/accessguard/config/schema/accessguard.schema.yml` (append third-party schema)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/RescanPolicyTest.php`

**Interfaces:**
- Consumes: `accessguard.settings` config (`rescan_interval`), `node_type` storage.
- Produces: service `accessguard.rescan_policy`, class `Drupal\accessguard\Service\RescanPolicy` with:
  - `isExcluded(string $bundle): bool` — TRUE iff the type's `rescan_mode` third-party setting is `'disabled'`.
  - `intervalFor(string $bundle): int` — the type's own `rescan_interval` when mode is `'custom'` and the value ≥ 60; otherwise the global `rescan_interval` (fallback 86400). Unknown bundles behave as inherit.
- Produces: config schema `node.type.*.third_party.accessguard` with keys `rescan_mode` (string), `rescan_interval` (integer). Tasks 2–5 rely on all of these names exactly.

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Kernel/RescanPolicyTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests RescanPolicy's interpretation of per-type third-party settings.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class RescanPolicyTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'node', 'user', 'system', 'field', 'text', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig(['field', 'filter', 'node', 'accessguard']);
  }

  /**
   * Creates a node type carrying the given policy third-party settings.
   */
  private function createType(string $id, ?string $mode = NULL, ?int $interval = NULL): void {
    $type = NodeType::create(['type' => $id, 'name' => $id]);
    if ($mode !== NULL) {
      $type->setThirdPartySetting('accessguard', 'rescan_mode', $mode);
    }
    if ($interval !== NULL) {
      $type->setThirdPartySetting('accessguard', 'rescan_interval', $interval);
    }
    $type->save();
  }

  /**
   * Tests that a type without settings follows the global interval.
   */
  public function testInheritFollowsGlobalInterval(): void {
    $this->createType('page');
    \Drupal::configFactory()->getEditable('accessguard.settings')
      ->set('rescan_interval', 5000)->save();
    $policy = \Drupal::service('accessguard.rescan_policy');

    $this->assertFalse($policy->isExcluded('page'));
    $this->assertSame(5000, $policy->intervalFor('page'));
  }

  /**
   * Tests that custom mode uses the type's own interval.
   */
  public function testCustomModeUsesOwnInterval(): void {
    $this->createType('news', 'custom', 3600);

    $policy = \Drupal::service('accessguard.rescan_policy');
    $this->assertFalse($policy->isExcluded('news'));
    $this->assertSame(3600, $policy->intervalFor('news'));
  }

  /**
   * Tests that a hand-edited sub-60 custom interval falls back to global.
   *
   * The form prevents this state; config can still carry it. Falling back
   * beats re-scanning the type in a tight loop.
   */
  public function testInvalidCustomIntervalFallsBackToGlobal(): void {
    $this->createType('news', 'custom', 10);

    $this->assertSame(86400, \Drupal::service('accessguard.rescan_policy')->intervalFor('news'));
  }

  /**
   * Tests that disabled mode excludes the type.
   */
  public function testDisabledModeExcludes(): void {
    $this->createType('internal', 'disabled');

    $this->assertTrue(\Drupal::service('accessguard.rescan_policy')->isExcluded('internal'));
  }

  /**
   * Tests that an unknown bundle resolves as inherit.
   */
  public function testUnknownBundleInherits(): void {
    $policy = \Drupal::service('accessguard.rescan_policy');

    $this->assertFalse($policy->isExcluded('no-such-type'));
    $this->assertSame(86400, $policy->intervalFor('no-such-type'));
  }

}
```

- [ ] **Step 2: Run test to verify it fails**

Run (Bash tool):
```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/RescanPolicyTest.php"
```
Expected: FAIL/ERROR — `ServiceNotFoundException` for `accessguard.rescan_policy` (and `SchemaIncompleteException` on tests that save third-party settings).

- [ ] **Step 3: Write the implementation**

Create `web/modules/custom/accessguard/src/Service/RescanPolicy.php`:

```php
<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Interprets a content type's re-scan policy (third-party settings).
 *
 * One owner for the inherit/custom/disabled semantics so the save hook,
 * cron, and the gate can't drift apart — the same centralization move as
 * GateEvaluator. 'disabled' means AccessGuard ignores the type entirely:
 * no automatic scans and no gating.
 */
class RescanPolicy {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether AccessGuard ignores this bundle entirely.
   */
  public function isExcluded(string $bundle): bool {
    return $this->mode($bundle) === 'disabled';
  }

  /**
   * The re-scan staleness interval for this bundle, in seconds.
   *
   * Custom mode uses the type's own interval when it is sane (>= 60);
   * anything else — inherit, an unknown bundle, hand-edited nonsense —
   * falls back to the global rescan_interval.
   */
  public function intervalFor(string $bundle): int {
    $global = (int) ($this->configFactory->get('accessguard.settings')->get('rescan_interval') ?: 86400);
    if ($this->mode($bundle) !== 'custom') {
      return $global;
    }
    $type = $this->entityTypeManager->getStorage('node_type')->load($bundle);
    $interval = (int) $type->getThirdPartySetting('accessguard', 'rescan_interval', 0);
    return $interval >= 60 ? $interval : $global;
  }

  /**
   * The bundle's rescan_mode ('inherit' when unset or the type is unknown).
   */
  protected function mode(string $bundle): string {
    $type = $this->entityTypeManager->getStorage('node_type')->load($bundle);
    return $type ? (string) $type->getThirdPartySetting('accessguard', 'rescan_mode', 'inherit') : 'inherit';
  }

}
```

Append to `web/modules/custom/accessguard/accessguard.services.yml` (inside the existing `services:` block, after `accessguard.trend_builder`):

```yaml
  accessguard.rescan_policy:
    class: Drupal\accessguard\Service\RescanPolicy
    arguments: ['@entity_type.manager', '@config.factory']
```

Append to `web/modules/custom/accessguard/config/schema/accessguard.schema.yml` (top level, after the `accessguard.settings` mapping):

```yaml

node.type.*.third_party.accessguard:
  type: mapping
  label: 'AccessGuard re-scan policy'
  mapping:
    rescan_mode:
      type: string
      label: 'Re-scan mode (inherit | custom | disabled)'
    rescan_interval:
      type: integer
      label: 'Type-specific re-scan interval in seconds (custom mode only)'
```

- [ ] **Step 4: Run test to verify it passes**

Same command as Step 2. Expected: OK (5 tests).

- [ ] **Step 5: phpcs the new/changed files**

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard
```
Expected: no output (clean). Fix anything reported.

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/src/Service/RescanPolicy.php web/modules/custom/accessguard/accessguard.services.yml web/modules/custom/accessguard/config/schema/accessguard.schema.yml web/modules/custom/accessguard/tests/src/Kernel/RescanPolicyTest.php
git commit -m "feat(rescan): RescanPolicy service interprets per-type third-party settings

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Save hook skips excluded types

**Files:**
- Modify: `web/modules/custom/accessguard/accessguard.module` (`_accessguard_enqueue_scan()`, lines ~47-59)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php` (append test)

**Interfaces:**
- Consumes: `accessguard.rescan_policy` → `isExcluded(string $bundle): bool` (Task 1).
- Produces: saving a node whose bundle is excluded enqueues nothing and records no `accessguard.cron_enqueued` marker. Cron/gate behavior unchanged by this task.

- [ ] **Step 1: Write the failing test**

Append to `CronRescanTest` (before the final closing brace):

```php
  /**
   * Tests that saving a node of an excluded type enqueues nothing.
   */
  public function testSaveDoesNotEnqueueExcludedType(): void {
    NodeType::create(['type' => 'internal', 'name' => 'Internal'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')
      ->save();

    Node::create(['type' => 'internal', 'title' => 'excluded', 'status' => 1])->save();

    $this->assertSame(0, \Drupal::queue('accessguard_scan_queue')->numberOfItems());
    // No cron dedup marker either: nothing was enqueued to deduplicate.
    $this->assertSame([], \Drupal::state()->get('accessguard.cron_enqueued', []));
  }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php --filter testSaveDoesNotEnqueueExcludedType"
```
Expected: FAIL — queue has 1 item (the save hook enqueued it).

- [ ] **Step 3: Implement**

In `accessguard.module`, `_accessguard_enqueue_scan()`, insert after the existing `rescan_enabled` early return:

```php
  // A type excluded by its re-scan policy gets no automatic scans at all.
  if (\Drupal::service('accessguard.rescan_policy')->isExcluded($node->bundle())) {
    return;
  }
```

- [ ] **Step 4: Run the full CronRescanTest to verify it passes (and nothing regressed)**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php"
```
Expected: OK (8 tests).

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/accessguard/accessguard.module web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php
git commit -m "feat(rescan): save hook skips content types excluded by policy

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Cron applies per-type intervals and exclusions

**Files:**
- Modify: `web/modules/custom/accessguard/accessguard.module` (`accessguard_cron()`, lines ~141-196)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php` (helper change + two tests)

**Interfaces:**
- Consumes: `accessguard.rescan_policy` → `isExcluded()`, `intervalFor()` (Task 1).
- Produces: cron compares each published node against `now − intervalFor(bundle)` and never enqueues excluded bundles. Existing dedup-marker semantics are preserved per node.

- [ ] **Step 1: Extend the quiet-node helper to take a bundle**

In `CronRescanTest`, change the signature and `Node::create` line of `createPublishedNodeQuietly` (existing callers keep working via the default):

```php
  private function createPublishedNodeQuietly(string $title, string $type = 'page'): Node {
    $config = \Drupal::configFactory()->getEditable('accessguard.settings');
    $config->set('rescan_enabled', FALSE)->save();
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1]);
    $node->save();
    $config->set('rescan_enabled', TRUE)->save();
    return $node;
  }
```
(Docblock stays as is.)

- [ ] **Step 2: Write the failing tests**

Append to `CronRescanTest`:

```php
  /**
   * Tests that each type is compared against its own staleness cutoff.
   *
   * Both nodes were scanned two hours ago: stale for the news type's custom
   * one-hour interval, fresh for the page type's inherited 24-hour default.
   */
  public function testCronUsesPerTypeIntervals(): void {
    NodeType::create(['type' => 'news', 'name' => 'News'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'custom')
      ->setThirdPartySetting('accessguard', 'rescan_interval', 3600)
      ->save();
    $page = $this->createPublishedNodeQuietly('page node');
    $news = $this->createPublishedNodeQuietly('news node', 'news');
    $twoHoursAgo = \Drupal::time()->getRequestTime() - 7200;
    foreach ([$page, $news] as $node) {
      \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
        'target_entity_type' => 'node',
        'target_entity_id' => $node->id(),
        'status' => 'complete',
        'created' => $twoHoursAgo,
      ])->save();
    }
    $queue = \Drupal::queue('accessguard_scan_queue');

    accessguard_cron();

    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame((int) $news->id(), $item->data['nid']);
  }

  /**
   * Tests that an excluded type is never enqueued, however stale.
   */
  public function testCronSkipsExcludedType(): void {
    NodeType::create(['type' => 'internal', 'name' => 'Internal'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')
      ->save();
    $this->createPublishedNodeQuietly('never scanned, still skipped', 'internal');

    accessguard_cron();

    $this->assertSame(0, \Drupal::queue('accessguard_scan_queue')->numberOfItems());
  }
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php --filter 'testCronUsesPerTypeIntervals|testCronSkipsExcludedType'"
```
Expected: both FAIL — cron uses the single global interval (news not enqueued; internal enqueued).

- [ ] **Step 4: Implement**

Replace the body of `accessguard_cron()` from `$interval = (int) (...)` through the final `$state->set(...)` with:

```php
  $batch = (int) ($config->get('rescan_batch') ?: 25);
  $policy = \Drupal::service('accessguard.rescan_policy');

  // Published node ids with their bundle: each type has its own staleness
  // cutoff, and excluded types get no cron scans at all.
  $rows = \Drupal::entityTypeManager()->getStorage('node')->getAggregateQuery()
    ->accessCheck(FALSE)
    ->condition('status', 1)
    ->groupBy('nid')
    ->groupBy('type')
    ->execute();
  $bundleByNid = [];
  foreach ($rows as $row) {
    if (!$policy->isExcluded($row['type'])) {
      $bundleByNid[(int) $row['nid']] = $row['type'];
    }
  }
  if (!$bundleByNid) {
    return;
  }

  // Latest scan timestamp per node (aggregate query, no entity hydration).
  $latest = \Drupal::service('accessguard.scan_repository')->latestScanCreatedByNode();

  // Timestamps of items cron already enqueued, so frequent cron runs don't
  // pile up duplicate queue items for the same stale node while its scan is
  // still waiting in the queue. A marker expires after one interval, so a
  // lost queue item can't shelve a node forever.
  $state = \Drupal::state();
  $pending = $state->get('accessguard.cron_enqueued', []);
  // Drop markers for nodes that are gone, unpublished, or excluded.
  $pending = array_intersect_key($pending, $bundleByNid);

  $queue = \Drupal::queue('accessguard_scan_queue');
  $now = \Drupal::time()->getRequestTime();
  $enqueued = 0;
  foreach ($bundleByNid as $nid => $bundle) {
    $cutoff = $now - $policy->intervalFor($bundle);
    $last = $latest[$nid] ?? 0;
    if ($last >= $cutoff) {
      continue;
    }
    $marker = $pending[$nid] ?? 0;
    if ($marker > $last && $marker >= $cutoff) {
      // Already enqueued since its last completed scan, within the interval.
      continue;
    }
    $queue->createItem(['nid' => $nid, 'trigger' => 'cron']);
    $pending[$nid] = $now;
    if (++$enqueued >= $batch) {
      break;
    }
  }
  $state->set('accessguard.cron_enqueued', $pending);
```

Also delete the now-unused `$entity_type_manager = \Drupal::entityTypeManager();` line and the old `$nids` query block, and update the hook docblock's second line to: `Enqueues published nodes that are unscanned or stale per their type's policy.`

- [ ] **Step 5: Run the full CronRescanTest**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php"
```
Expected: OK (10 tests) — the 8 existing tests pin that global behavior is unchanged.

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/accessguard.module web/modules/custom/accessguard/tests/src/Kernel/CronRescanTest.php
git commit -m "feat(rescan): cron applies per-type intervals and skips excluded types

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Gate exempts excluded types

**Files:**
- Modify: `web/modules/custom/accessguard/src/Service/GateEvaluator.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml` (`accessguard.gate_evaluator` arguments)
- Modify: `web/modules/custom/accessguard/src/Drush/Commands/AccessguardCommands.php` (NULL-branch note wording, lines ~118-123)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/GateEvaluatorTest.php`, `PublishGateTest.php`, `AccessguardCommandsTest.php` (append one test each)

**Interfaces:**
- Consumes: `accessguard.rescan_policy` → `isExcluded(string $bundle): bool` (Task 1).
- Produces: `GateEvaluator::blockingCount(int $nid): ?int` now returns NULL when the node's bundle is excluded (in addition to never-scanned). Constructor gains a fifth argument `RescanPolicy $rescanPolicy`. Both gate consumers inherit the exemption; only the drush command's note text changes.

- [ ] **Step 1: Write the failing tests**

Append to `GateEvaluatorTest`:

```php
  /**
   * Tests an excluded content type yields NULL regardless of scan data.
   */
  public function testExcludedTypeYieldsNull(): void {
    NodeType::load('page')->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')->save();
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img']]);

    $this->assertNull(\Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }
```

Append to `PublishGateTest`:

```php
  /**
   * Tests a node of an excluded type publishes despite a failing scan.
   *
   * Excluded means AccessGuard ignores the type; without the exemption a
   * type excluded after a failing scan could never be published again
   * (re-scans are off, so the stale scan would gate forever).
   */
  public function testExcludedTypeBypassesGate(): void {
    NodeType::load('page')->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')->save();
    $node = Node::create(['type' => 'page', 'title' => 'excluded', 'status' => 0]);
    $node->save();
    $this->makeScan((int) $node->id(), 1);
    $node->setPublished();
    $this->assertSame(0, $this->countGateViolations($node));
  }
```

Append to `AccessguardCommandsTest`:

```php
  /**
   * Tests the gate command exempts nodes of excluded content types.
   */
  public function testGateSkipsExcludedType(): void {
    NodeType::create(['type' => 'internal', 'name' => 'Internal'])
      ->setThirdPartySetting('accessguard', 'rescan_mode', 'disabled')
      ->save();
    $node = Node::create(['type' => 'internal', 'title' => 'excluded dirty', 'status' => 1]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
    ]);
    $scan->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'image-alt',
      'impact' => 'critical',
      'selector' => 'img',
    ])->save();

    $result = $this->createCommand()->gate();

    $this->assertSame(0, $result->getExitCode());
    $this->assertCount(0, $result->getOutputData()->getArrayCopy());
  }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/GateEvaluatorTest.php web/modules/custom/accessguard/tests/src/Kernel/PublishGateTest.php web/modules/custom/accessguard/tests/src/Kernel/AccessguardCommandsTest.php"
```
Expected: the three new tests FAIL (blocking count 1 / gate violation raised / exit code 1); all others pass.

Note: phpunit accepts multiple file paths; if the local runner balks, run the three files separately with the same prefix.

- [ ] **Step 3: Implement**

`GateEvaluator.php` — add the constructor argument and the exemption check:

```php
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected WaiverMatcher $waiverMatcher,
    protected ScanRepository $scanRepository,
    protected RescanPolicy $rescanPolicy,
  ) {}
```

In `blockingCount()`, insert before the `latestScanIdForNode` call:

```php
    // An excluded type is exempt from the gate entirely — otherwise a type
    // excluded after a failing scan could never be published again
    // (automatic re-scans are off, so the stale scan would gate forever).
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if ($node && $this->rescanPolicy->isExcluded($node->bundle())) {
      return NULL;
    }
```

Update the method docblock's `@return` first line to:
`NULL when the node has never been scanned or its content type is excluded by its re-scan policy (nothing to gate on);`

`accessguard.services.yml` — extend the gate evaluator's arguments:

```yaml
  accessguard.gate_evaluator:
    class: Drupal\accessguard\Service\GateEvaluator
    arguments: ['@entity_type.manager', '@config.factory', '@accessguard.waiver_matcher', '@accessguard.scan_repository', '@accessguard.rescan_policy']
```

`AccessguardCommands.php` — the NULL branch's comment claimed the all-nodes path can't reach it; exclusion breaks that invariant (an excluded node WITH scans returns NULL). Replace the comment + note:

```php
      if ($blocking === NULL) {
        // Nothing to gate on: never scanned, or the node's content type is
        // excluded by its re-scan policy.
        $this->io()->note("Node $id has nothing to gate on (never scanned, or its content type is excluded).");
        continue;
      }
```

- [ ] **Step 4: Run tests to verify they pass**

Same command as Step 2. Expected: OK (all three files green).

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/accessguard/src/Service/GateEvaluator.php web/modules/custom/accessguard/accessguard.services.yml web/modules/custom/accessguard/src/Drush/Commands/AccessguardCommands.php web/modules/custom/accessguard/tests/src/Kernel/GateEvaluatorTest.php web/modules/custom/accessguard/tests/src/Kernel/PublishGateTest.php web/modules/custom/accessguard/tests/src/Kernel/AccessguardCommandsTest.php
git commit -m "feat(rescan): excluded content types are exempt from the publish gate

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Node-type form UI

**Files:**
- Modify: `web/modules/custom/accessguard/accessguard.module` (two new functions + `use` statements)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/RescanPolicyTest.php` (append two tests + one `use`)

**Interfaces:**
- Consumes: third-party setting names from Task 1 (`rescan_mode`, `rescan_interval`).
- Produces: `accessguard_form_node_type_form_alter(array &$form, FormStateInterface $form_state): void` (fires for both node-type add and edit forms via the base form id) and entity builder `accessguard_form_node_type_form_builder(string $entity_type, NodeTypeInterface $type, array &$form, FormStateInterface $form_state): void`. Values arrive nested under the `accessguard` tree key.

- [ ] **Step 1: Write the failing tests**

In `RescanPolicyTest.php`, add to the `use` block:

```php
use Drupal\Core\Form\FormState;
```

Append two tests:

```php
  /**
   * Tests the node-type form alter exposes the stored policy.
   */
  public function testFormAlterExposesStoredPolicy(): void {
    $this->createType('page', 'custom', 3600);
    $form_object = \Drupal::entityTypeManager()->getFormObject('node_type', 'edit');
    $form_object->setEntity(NodeType::load('page'));
    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    $form = [];

    accessguard_form_node_type_form_alter($form, $form_state);

    $this->assertSame('custom', $form['accessguard']['rescan_mode']['#default_value']);
    $this->assertSame(3600, $form['accessguard']['rescan_interval']['#default_value']);
  }

  /**
   * Tests the entity builder round-trips settings and inherit clears them.
   *
   * Saving also runs the strict config-schema checker over the third-party
   * settings, so this doubles as schema coverage for values written by the
   * form rather than by hand.
   */
  public function testFormBuilderRoundTrip(): void {
    $this->createType('page');
    $type = NodeType::load('page');
    $form = [];

    $form_state = new FormState();
    $form_state->setValue('accessguard', ['rescan_mode' => 'custom', 'rescan_interval' => 3600]);
    accessguard_form_node_type_form_builder('node_type', $type, $form, $form_state);
    $type->save();

    $reloaded = NodeType::load('page');
    $this->assertSame('custom', $reloaded->getThirdPartySetting('accessguard', 'rescan_mode'));
    $this->assertSame(3600, $reloaded->getThirdPartySetting('accessguard', 'rescan_interval'));
    $this->assertSame(3600, \Drupal::service('accessguard.rescan_policy')->intervalFor('page'));

    // Switching back to inherit clears the stored settings entirely.
    $form_state = new FormState();
    $form_state->setValue('accessguard', ['rescan_mode' => 'inherit', 'rescan_interval' => 3600]);
    accessguard_form_node_type_form_builder('node_type', $reloaded, $form, $form_state);
    $reloaded->save();

    $cleared = NodeType::load('page');
    $this->assertNull($cleared->getThirdPartySetting('accessguard', 'rescan_mode'));
    $this->assertNull($cleared->getThirdPartySetting('accessguard', 'rescan_interval'));
  }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/RescanPolicyTest.php"
```
Expected: the two new tests ERROR with `Call to undefined function accessguard_form_node_type_form_alter()` / `..._builder()`.

- [ ] **Step 3: Implement**

In `accessguard.module`, extend the `use` block at the top:

```php
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeTypeInterface;
```

Add after `_accessguard_enqueue_scan()`:

```php
/**
 * Implements hook_form_BASE_FORM_ID_alter() for the node type form.
 *
 * Adds the per-type re-scan policy to the content type's add/edit form:
 * inherit the global cadence, set a type-specific interval, or exclude the
 * type from AccessGuard (no automatic scans, exempt from the publish gate).
 */
function accessguard_form_node_type_form_alter(array &$form, FormStateInterface $form_state): void {
  /** @var \Drupal\node\NodeTypeInterface $type */
  $type = $form_state->getFormObject()->getEntity();
  $form['accessguard'] = [
    '#type' => 'details',
    '#title' => t('AccessGuard'),
    '#group' => 'additional_settings',
    '#tree' => TRUE,
  ];
  $form['accessguard']['rescan_mode'] = [
    '#type' => 'select',
    '#title' => t('Re-scan policy'),
    '#options' => [
      'inherit' => t('Site default'),
      'custom' => t('Custom interval'),
      'disabled' => t('Disabled — never scan or gate this content type'),
    ],
    '#default_value' => $type->getThirdPartySetting('accessguard', 'rescan_mode', 'inherit'),
    '#description' => t('Disabled means AccessGuard ignores this type entirely: no automatic scans, and the publish gate does not apply. Existing scan data remains visible as history.'),
  ];
  $form['accessguard']['rescan_interval'] = [
    '#type' => 'number',
    '#title' => t('Re-scan interval (seconds)'),
    '#min' => 60,
    '#default_value' => $type->getThirdPartySetting('accessguard', 'rescan_interval', 86400),
    '#states' => [
      'visible' => [':input[name="accessguard[rescan_mode]"]' => ['value' => 'custom']],
      'required' => [':input[name="accessguard[rescan_mode]"]' => ['value' => 'custom']],
    ],
  ];
  $form['#entity_builders'][] = 'accessguard_form_node_type_form_builder';
}

/**
 * Entity builder: maps the form's policy values to third-party settings.
 *
 * Inherit stores nothing at all, so an exported node type only carries
 * AccessGuard keys when its policy actually deviates from the site default.
 */
function accessguard_form_node_type_form_builder(string $entity_type, NodeTypeInterface $type, array &$form, FormStateInterface $form_state): void {
  $values = $form_state->getValue('accessguard') ?? [];
  $mode = $values['rescan_mode'] ?? 'inherit';
  if ($mode === 'inherit') {
    $type->unsetThirdPartySetting('accessguard', 'rescan_mode');
    $type->unsetThirdPartySetting('accessguard', 'rescan_interval');
    return;
  }
  $type->setThirdPartySetting('accessguard', 'rescan_mode', $mode);
  if ($mode === 'custom') {
    // #states-required is client-side only; clamp so a bypassed browser
    // can't store a tight-loop interval.
    $type->setThirdPartySetting('accessguard', 'rescan_interval', max(60, (int) ($values['rescan_interval'] ?? 0)));
  }
  else {
    $type->unsetThirdPartySetting('accessguard', 'rescan_interval');
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Same command as Step 2. Expected: OK (7 tests).

- [ ] **Step 5: phpcs**

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard
```
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/accessguard.module web/modules/custom/accessguard/tests/src/Kernel/RescanPolicyTest.php
git commit -m "feat(rescan): per-type policy UI on the content type form

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Docs, settings-form note, full verification

**Files:**
- Modify: `web/modules/custom/accessguard/src/Form/SettingsForm.php` (`rescan_interval` element, lines ~92-97)
- Modify: `README.md` (line ~103)

**Interfaces:**
- Consumes: everything above; no new interfaces produced.

- [ ] **Step 1: Add the override note to the settings form**

In `SettingsForm::buildForm()`, add a `#description` to the existing `rescan_interval` element:

```php
    $form['rescan_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Re-scan interval (seconds)'),
      '#description' => $this->t('The default staleness window for cron re-scans. Content types can override it — or opt out of scanning and gating entirely — in the AccessGuard section of their edit forms.'),
      '#default_value' => $config->get('rescan_interval') ?: 86400,
      '#min' => 60,
    ];
```

- [ ] **Step 2: Update the README feature list**

In `README.md`, replace the line:

```markdown
  - **cron site-wide re-scanning** of stale/unscanned published nodes
```

with:

```markdown
  - **cron site-wide re-scanning** of stale/unscanned published nodes, with **per-content-type policies**: each type can inherit the global interval, set its own, or opt out of scanning and gating entirely (configured on the content type's edit form)
```

- [ ] **Step 3: Run the full module suite**

```bash
ddev exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests"
```
Expected: OK — 119 existing + 13 new = 132 tests, 0 failures. (Pre-existing vendor deprecation notices — Drush x1, core Twig x2 — are known and fine.)

- [ ] **Step 4: phpcs both modules**

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo
```
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/accessguard/src/Form/SettingsForm.php README.md
git commit -m "docs(rescan): document per-type policies in README and settings form

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
