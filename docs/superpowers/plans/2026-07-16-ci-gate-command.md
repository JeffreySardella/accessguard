# CI Gate Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `drush accessguard:gate` evaluates the publish-gate policy across published content and exits 0/1 for CI, with the policy extracted into a `GateEvaluator` service shared with the publish-gate constraint validator.

**Architecture:** A new `GateEvaluator` service owns "how many violations of a node's latest scan block at the configured threshold" (threshold rank via `Severity`, waived fingerprints excluded, needs-review excluded unless configured). The constraint validator delegates its counting to it; a new Drush command iterates published scanned nodes and returns `CommandResult` rows with an exit code.

**Tech Stack:** Drupal 11 (PHP 8.4), Drush 13 attribute commands, PHPUnit kernel tests.

## Global Constraints

- Run kernel tests inside DDEV: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter <name> web/modules/custom/accessguard/tests"`.
- phpcs must stay clean: `ddev exec "vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo"`.
- Spec: `docs/superpowers/specs/2026-07-16-ci-gate-command-design.md`. YAGNI rules from it apply (no `--threshold` override, nodes only, unscanned nodes never fail the gate).
- Commit messages end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: GateEvaluator service

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/GateEvaluator.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml` (add service)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/GateEvaluatorTest.php`

**Interfaces:**
- Consumes: `Severity::rank()`, `WaiverMatcher::waivedFingerprints(int $nid)` / `WaiverMatcher::fingerprint(string $rule, string $selector)`, `ScanRepository::latestScanIdForNode(int $nid)` (returns the latest scan id or a falsy value when never scanned), config keys `gate_threshold` (string severity name) and `gate_includes_needs_review` (bool) in `accessguard.settings`.
- Produces: service `accessguard.gate_evaluator`, class `Drupal\accessguard\Service\GateEvaluator` with `public function blockingCount(int $nid): ?int` — NULL when the node has never been scanned; otherwise the number of blocking violations in its latest scan. Tasks 2 and 3 depend on exactly this signature.

- [ ] **Step 1: Write the failing kernel test**

Create `web/modules/custom/accessguard/tests/src/Kernel/GateEvaluatorTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests GateEvaluator's blocking-violation policy.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class GateEvaluatorTest extends KernelTestBase {

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
    $this->installEntitySchema('accessguard_scan');
    $this->installEntitySchema('accessguard_violation');
    $this->installEntitySchema('accessguard_waiver');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'filter', 'node', 'accessguard']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Creates a published node and returns its id.
   */
  private function makeNode(): int {
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Creates a completed scan carrying the given violations.
   *
   * @param int $nid
   *   Target node id.
   * @param array $violations
   *   Items of [impact, selector, result_type] — result_type optional.
   */
  private function makeScan(int $nid, array $violations): int {
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
    ]);
    $scan->save();
    foreach ($violations as $v) {
      \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
        'scan_id' => $scan->id(),
        'rule_id' => 'image-alt',
        'impact' => $v[0],
        'selector' => $v[1],
        'result_type' => $v[2] ?? 'violation',
      ])->save();
    }
    return (int) $scan->id();
  }

  /**
   * Tests only violations at or above the threshold rank block.
   */
  public function testThresholdRankFiltersBlocking(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img'], ['serious', 'input'], ['minor', 'p']]);
    \Drupal::configFactory()->getEditable('accessguard.settings')
      ->set('gate_threshold', 'serious')->save();

    $this->assertSame(2, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

  /**
   * Tests waived fingerprints do not block.
   */
  public function testWaivedViolationDoesNotBlock(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img'], ['critical', 'div']]);
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver($nid, 'image-alt', 'img', 'false_positive', 'decorative', 1);

    $this->assertSame(1, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

  /**
   * Tests needs-review results are excluded unless configured in.
   */
  public function testNeedsReviewRespectsConfig(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['critical', 'img'], ['critical', 'div', 'needs_review']]);
    $evaluator = \Drupal::service('accessguard.gate_evaluator');

    $this->assertSame(1, $evaluator->blockingCount($nid));

    \Drupal::configFactory()->getEditable('accessguard.settings')
      ->set('gate_includes_needs_review', TRUE)->save();
    $this->assertSame(2, $evaluator->blockingCount($nid));
  }

  /**
   * Tests unknown impact ranks alongside moderate (gateable, not invisible).
   */
  public function testUnknownImpactBlocksAtModerateThreshold(): void {
    $nid = $this->makeNode();
    $this->makeScan($nid, [['weird-impact', 'div']]);
    $config = \Drupal::configFactory()->getEditable('accessguard.settings');

    $config->set('gate_threshold', 'moderate')->save();
    $this->assertSame(1, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));

    $config->set('gate_threshold', 'serious')->save();
    $this->assertSame(0, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

  /**
   * Tests only the LATEST scan is evaluated, and never-scanned yields NULL.
   */
  public function testLatestScanOnlyAndNullWhenUnscanned(): void {
    $nid = $this->makeNode();
    $this->assertNull(\Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));

    $this->makeScan($nid, [['critical', 'img']]);
    $this->makeScan($nid, []);
    $this->assertSame(0, \Drupal::service('accessguard.gate_evaluator')->blockingCount($nid));
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter GateEvaluatorTest web/modules/custom/accessguard/tests"`
Expected: FAIL — `ServiceNotFoundException: accessguard.gate_evaluator` (all 5 tests error).

- [ ] **Step 3: Implement the service**

Create `web/modules/custom/accessguard/src/Service/GateEvaluator.php`:

```php
<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Severity;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The gate policy: how many latest-scan violations block a node.
 *
 * Owned here so the publish-gate constraint validator and the
 * accessguard:gate CI command apply one definition of "blocking" —
 * threshold rank from Severity, waived fingerprints excluded, needs-review
 * results excluded unless gate_includes_needs_review opts them in.
 */
class GateEvaluator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected WaiverMatcher $waiverMatcher,
    protected ScanRepository $scanRepository,
  ) {}

  /**
   * Counts the blocking violations in a node's latest scan.
   *
   * @return int|null
   *   NULL when the node has never been scanned (nothing to gate on);
   *   otherwise the number of violations at or above the configured
   *   gate_threshold rank that are not waived (and not needs-review,
   *   unless gate_includes_needs_review is set).
   */
  public function blockingCount(int $nid): ?int {
    $scanId = $this->scanRepository->latestScanIdForNode($nid);
    if (!$scanId) {
      return NULL;
    }

    $config = $this->configFactory->get('accessguard.settings');
    $threshold = Severity::rank($config->get('gate_threshold') ?: 'critical') ?: 4;
    $includeNeedsReview = (bool) $config->get('gate_includes_needs_review');

    $waived = $this->waiverMatcher->waivedFingerprints($nid);
    $violations = $this->entityTypeManager->getStorage('accessguard_violation')
      ->loadByProperties(['scan_id' => $scanId]);

    $blocking = 0;
    foreach ($violations as $v) {
      if (!$includeNeedsReview && $v->get('result_type')->value === 'needs_review') {
        continue;
      }
      // Normalize before ranking: ScanRecorder stores normalized impacts,
      // but rows predating that (or written by hand) may carry raw values,
      // and an unrecognized impact must rank as UNKNOWN (gateable), not 0
      // (invisible to every gate).
      if (Severity::rank(Severity::normalize($v->get('impact')->value)) < $threshold) {
        continue;
      }
      $fp = WaiverMatcher::fingerprint(
        $v->get('rule_id')->value,
        (string) $v->get('selector')->value
      );
      if (!isset($waived[$fp])) {
        $blocking++;
      }
    }
    return $blocking;
  }

}
```

Register it in `web/modules/custom/accessguard/accessguard.services.yml` (append alongside the other services, matching indentation):

```yaml
  accessguard.gate_evaluator:
    class: Drupal\accessguard\Service\GateEvaluator
    arguments: ['@entity_type.manager', '@config.factory', '@accessguard.waiver_matcher', '@accessguard.scan_repository']
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter GateEvaluatorTest web/modules/custom/accessguard/tests"`
Expected: OK (5 tests).

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/accessguard/src/Service/GateEvaluator.php web/modules/custom/accessguard/accessguard.services.yml web/modules/custom/accessguard/tests/src/Kernel/GateEvaluatorTest.php
git commit -m "feat(gate): extract the gate policy into a GateEvaluator service

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Constraint validator delegates to GateEvaluator

**Files:**
- Modify: `web/modules/custom/accessguard/src/Plugin/Validation/Constraint/AccessguardGateConstraintValidator.php`
- Test: existing `web/modules/custom/accessguard/tests/src/Kernel/PublishGateTest.php` (no changes — it pins behavior across the refactor)

**Interfaces:**
- Consumes: `GateEvaluator::blockingCount(int $nid): ?int` from Task 1.
- Produces: no new interfaces; the validator's observable behavior is unchanged.

- [ ] **Step 1: Run PublishGateTest to establish green baseline**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter PublishGateTest web/modules/custom/accessguard/tests"`
Expected: OK. (Pure refactor: baseline first, stay green after.)

- [ ] **Step 2: Refactor the validator**

Replace the counting body of `AccessguardGateConstraintValidator` with a delegation. The full new file:

```php
<?php

namespace Drupal\accessguard\Plugin\Validation\Constraint;

use Drupal\accessguard\Service\GateEvaluator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the AccessguardGate constraint.
 */
class AccessguardGateConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected GateEvaluator $gateEvaluator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('accessguard.gate_evaluator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    if (!$entity || $entity->isNew() || !$entity->isPublished()) {
      return;
    }
    if ($this->currentUser->hasPermission('bypass accessguard gating')) {
      return;
    }
    $config = $this->configFactory->get('accessguard.settings');
    if (!$config->get('gate_enabled')) {
      return;
    }

    // Only gate the transition INTO a published state. If the node is already
    // published in storage, allow the save — otherwise an editor could never
    // save a fix, because the pre-fix scan would keep blocking it. Edits are
    // re-scanned via the save hook, and cron keeps published content reviewed.
    $stored = $this->entityTypeManager->getStorage('node')->loadUnchanged($entity->id());
    if ($stored && $stored->isPublished()) {
      return;
    }

    // The counting policy (threshold ranks, waivers, needs-review handling)
    // lives in GateEvaluator, shared with the accessguard:gate CI command.
    // NULL means never scanned: nothing to gate on.
    $blocking = $this->gateEvaluator->blockingCount((int) $entity->id());
    if ($blocking !== NULL && $blocking > 0) {
      $this->context->addViolation($constraint->message, [
        '@count' => $blocking,
        '@threshold' => $config->get('gate_threshold') ?: 'critical',
      ]);
    }
  }

}
```

(The `WaiverMatcher` and `Severity` imports and the inline query/count logic are gone; `GateEvaluator` replaces them.)

- [ ] **Step 3: Run PublishGateTest — must stay green**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter PublishGateTest web/modules/custom/accessguard/tests"`
Expected: OK, identical to Step 1.

- [ ] **Step 4: Commit**

```bash
git add web/modules/custom/accessguard/src/Plugin/Validation/Constraint/AccessguardGateConstraintValidator.php
git commit -m "refactor(gate): publish-gate validator delegates counting to GateEvaluator

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: The accessguard:gate command

**Files:**
- Modify: `web/modules/custom/accessguard/src/Drush/Commands/AccessguardCommands.php`
- Test: `web/modules/custom/accessguard/tests/src/Kernel/AccessguardCommandsTest.php` (add tests)

**Interfaces:**
- Consumes: `GateEvaluator::blockingCount(int $nid): ?int` (Task 1); `ScanRepository::latestScanIdByNode(): array` (existing; returns `nid => scan_id`).
- Produces: `drush accessguard:gate [nid]` returning `Consolidation\AnnotatedCommand\CommandResult` whose data is `Consolidation\OutputFormatters\StructuredData\RowsOfFields` (fields `nid`, `title`, `blocking`) and whose exit code is 0 (pass) or 1 (blocking violations found).

- [ ] **Step 1: Write the failing tests**

Add to `web/modules/custom/accessguard/tests/src/Kernel/AccessguardCommandsTest.php`. No new imports are needed (`Node`, `ArrayInput`, `NullOutput` are already imported). Add `'accessguard_waiver'` entity schema installation to `setUp()` right after the existing `accessguard_violation` line:

```php
    $this->installEntitySchema('accessguard_waiver');
```

New test methods:

```php
  /**
   * Creates a published node with a completed scan carrying violations.
   *
   * @return int
   *   The node id.
   */
  private function makeScannedNode(string $title, array $impacts, bool $published = TRUE): int {
    $node = Node::create(['type' => 'page', 'title' => $title, 'status' => $published ? 1 : 0]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
    ]);
    $scan->save();
    foreach ($impacts as $i => $impact) {
      \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
        'scan_id' => $scan->id(),
        'rule_id' => 'image-alt',
        'impact' => $impact,
        'selector' => 'img.v' . $i,
      ])->save();
    }
    return (int) $node->id();
  }

  /**
   * Tests the gate fails (exit 1) when a published node has a blocker.
   */
  public function testGateFailsOnBlockingViolation(): void {
    $clean = $this->makeScannedNode('clean', []);
    $dirty = $this->makeScannedNode('dirty', ['critical']);

    $result = $this->createCommand()->gate();

    $this->assertSame(1, $result->getExitCode());
    $rows = $result->getOutputData()->getArrayCopy();
    $byNid = array_column($rows, NULL, 'nid');
    $this->assertSame(0, $byNid[$clean]['blocking']);
    $this->assertSame(1, $byNid[$dirty]['blocking']);
  }

  /**
   * Tests waived violations pass the gate (exit 0).
   */
  public function testGatePassesWhenViolationWaived(): void {
    $nid = $this->makeScannedNode('waived', ['critical']);
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver($nid, 'image-alt', 'img.v0', 'false_positive', 'decorative', 1);

    $result = $this->createCommand()->gate();

    $this->assertSame(0, $result->getExitCode());
  }

  /**
   * Tests unpublished nodes are excluded from the all-nodes evaluation.
   */
  public function testGateIgnoresUnpublishedNodes(): void {
    $this->makeScannedNode('draft', ['critical'], FALSE);

    $result = $this->createCommand()->gate();

    $this->assertSame(0, $result->getExitCode());
    $this->assertCount(0, $result->getOutputData()->getArrayCopy());
  }

  /**
   * Tests a single-nid evaluation gates that node only, published or not.
   */
  public function testGateSingleNode(): void {
    $this->makeScannedNode('other-dirty', ['critical']);
    $draft = $this->makeScannedNode('draft-dirty', ['critical'], FALSE);

    $result = $this->createCommand()->gate($draft);

    $this->assertSame(1, $result->getExitCode());
    $rows = $result->getOutputData()->getArrayCopy();
    $this->assertCount(1, $rows);
    $this->assertSame($draft, $rows[0]['nid']);
  }

  /**
   * Tests a single-nid evaluation of an unknown node fails loudly.
   */
  public function testGateRejectsMissingNode(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->createCommand()->gate(999);
  }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter 'testGate' web/modules/custom/accessguard/tests"`
Expected: FAIL — `Error: Call to undefined method ...AccessguardCommands::gate()`.

- [ ] **Step 3: Implement the command**

In `web/modules/custom/accessguard/src/Drush/Commands/AccessguardCommands.php`:

Add imports:

```php
use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Service\GateEvaluator;
```

Extend the constructor and `create()` (two new promoted properties at the end, keeping the existing five):

```php
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly ScanRunner $scanRunner,
    private readonly ScanRecorder $scanRecorder,
    private readonly ScanAccessToken $scanAccessToken,
    private readonly GateEvaluator $gateEvaluator,
    private readonly ScanRepository $scanRepository,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('queue'),
      $container->get('accessguard.scan_runner'),
      $container->get('accessguard.scan_recorder'),
      $container->get('accessguard.scan_access_token'),
      $container->get('accessguard.gate_evaluator'),
      $container->get('accessguard.scan_repository'),
    );
  }
```

Add the command method after `scan()`:

```php
  /**
   * Evaluate the accessibility gate for CI: exit 1 if anything blocks.
   *
   * Applies the same policy as the publish gate (gate_threshold rank,
   * waivers honored, needs-review per gate_includes_needs_review) to every
   * published node that has a scan — or to one node when a nid is given.
   * Runs regardless of gate_enabled: invoking the command is the opt-in;
   * that flag governs the interactive publish gate only. Never-scanned
   * nodes are not failures (coverage is a different question).
   */
  #[CLI\Command(name: 'accessguard:gate')]
  #[CLI\Argument(name: 'nid', description: 'Evaluate one node id instead of all published scanned nodes.')]
  #[CLI\FieldLabels(labels: ['nid' => 'Node', 'title' => 'Title', 'blocking' => 'Blocking'])]
  #[CLI\DefaultTableFields(fields: ['nid', 'title', 'blocking'])]
  public function gate(?int $nid = NULL): CommandResult {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    if ($nid !== NULL) {
      $node = $nodeStorage->load($nid);
      if (!$node) {
        throw new \InvalidArgumentException("Node $nid not found.");
      }
      $nids = [$nid];
    }
    else {
      // Published content is what CI cares about; drafts are already held
      // back by the interactive publish gate.
      $nids = [];
      foreach (array_keys($this->scanRepository->latestScanIdByNode()) as $candidate) {
        $node = $nodeStorage->load($candidate);
        if ($node && $node->isPublished()) {
          $nids[] = (int) $candidate;
        }
      }
    }

    $rows = [];
    $failing = 0;
    foreach ($nids as $id) {
      $blocking = $this->gateEvaluator->blockingCount($id);
      if ($blocking === NULL) {
        // Never scanned: nothing to gate on (single-nid path only; the
        // all-nodes list is built from scans, so it can't get here).
        $this->io()->note("Node $id has never been scanned; nothing to gate on.");
        continue;
      }
      if ($blocking > 0) {
        $failing++;
      }
      $rows[] = [
        'nid' => $id,
        'title' => (string) $nodeStorage->load($id)->label(),
        'blocking' => $blocking,
      ];
    }

    if (!$rows) {
      $this->io()->note('No scanned published content to evaluate.');
    }
    elseif ($failing === 0) {
      $this->io()->success(sprintf('Gate passed: %d node(s) evaluated, none blocking.', count($rows)));
    }
    else {
      $this->io()->error(sprintf('Gate FAILED: %d of %d node(s) have blocking violations.', $failing, count($rows)));
    }

    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $failing > 0 ? 1 : 0);
  }
```

- [ ] **Step 4: Run the new tests to verify they pass**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter 'testGate' web/modules/custom/accessguard/tests"`
Expected: OK (5 tests).

- [ ] **Step 5: Run the full module suite and phpcs**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests"`
Expected: OK (109+ tests; 1 pre-existing deprecation in AccessguardCommandsTest is known and acceptable).

Run: `ddev exec "vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo"`
Expected: no output (clean).

- [ ] **Step 6: Verify live and update README**

```bash
ddev drush cr
ddev drush accessguard:gate; echo "exit: $?"
ddev drush accessguard:gate 9; echo "exit: $?"
```

Expected: the all-nodes run prints a table and an exit code (1 if the demo content has unwaived critical violations — node 9 is the known blocking demo node; node 14's violations are waived). The single-node run for node 9 exits 1.

Add a short subsection to `README.md` under the existing Drush/usage documentation (match the surrounding style):

```markdown
### CI gate

`drush accessguard:gate` evaluates the publish-gate policy (threshold,
waivers, needs-review setting) against every published node that has a
scan and exits non-zero if anything blocks — wire it into CI to fail a
build on accessibility regressions. Pass a node id to check one node;
use `--format=json` for machine-readable output. The command runs even
when the interactive gate is disabled.
```

- [ ] **Step 7: Commit**

```bash
git add web/modules/custom/accessguard/src/Drush/Commands/AccessguardCommands.php web/modules/custom/accessguard/tests/src/Kernel/AccessguardCommandsTest.php README.md
git commit -m "feat(gate): drush accessguard:gate CI command with exit-code contract

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
