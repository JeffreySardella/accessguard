# Severity Trends Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Trends" dashboard tab showing the site's per-severity violation counts as a day-by-day state series computed from stored scan history.

**Architecture:** `ScanRepository::allScanMeta()` fetches every scan's nid/created/count columns in one query; a new `TrendBuilder` service node-access-filters and folds them into "site state as of end of each scan-day" rows; `AnalyticsController::trends()` renders the table plus the retroactivity note as a fourth local task tab.

**Tech Stack:** Drupal 11 (PHP 8.4), PHPUnit kernel tests.

## Global Constraints

- Run kernel tests inside DDEV: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter <name> web/modules/custom/accessguard/tests"`.
- phpcs must stay clean: `ddev exec "vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo"`.
- Spec: `docs/superpowers/specs/2026-07-16-severity-trends-design.md`. YAGNI: no charts, no filters, no pagination.
- `total` = critical + serious + moderate + minor (needs-review stays out).
- Commit messages end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: allScanMeta() + TrendBuilder service

**Files:**
- Modify: `web/modules/custom/accessguard/src/Repository/ScanRepository.php` (add method)
- Create: `web/modules/custom/accessguard/src/Service/TrendBuilder.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml` (register service)
- Test: `web/modules/custom/accessguard/tests/src/Kernel/TrendBuilderTest.php`

**Interfaces:**
- Consumes: `accessguard_scan` base table columns (`id`, `target_entity_id`, `created`, `count_critical`, `count_serious`, `count_moderate`, `count_minor`, `count_needs_review`).
- Produces: `ScanRepository::allScanMeta(): array` (rows `[nid, created, count_critical, count_serious, count_moderate, count_minor, count_needs_review]` ordered by `(created, id)` ascending) and service `accessguard.trend_builder` with `TrendBuilder::dailySeries(): array` returning rows `[date: 'Y-m-d', critical: int, serious: int, moderate: int, minor: int, needs_review: int, total: int]` oldest first. Task 2 depends on `dailySeries()` exactly.

- [ ] **Step 1: Write the failing kernel test**

Create `web/modules/custom/accessguard/tests/src/Kernel/TrendBuilderTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TrendBuilder's daily state-series fold.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class TrendBuilderTest extends KernelTestBase {

  use UserCreationTrait;

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
    $this->installConfig(['system', 'field', 'filter', 'node', 'accessguard']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->createUser([]);
  }

  /**
   * Creates a node, returns its id.
   */
  private function makeNode(bool $published = TRUE): int {
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => $published ? 1 : 0]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Records a scan with explicit counts at an explicit time.
   */
  private function makeScan(int $nid, string $when, array $counts): void {
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
      'created' => strtotime($when),
      'count_critical' => $counts['critical'] ?? 0,
      'count_serious' => $counts['serious'] ?? 0,
      'count_moderate' => $counts['moderate'] ?? 0,
      'count_minor' => $counts['minor'] ?? 0,
      'count_needs_review' => $counts['needs_review'] ?? 0,
    ])->save();
  }

  /**
   * Tests the fold: latest scan per node as of each day, summed.
   */
  public function testDailySeriesFoldsLatestScanPerNode(): void {
    $a = $this->makeNode();
    $b = $this->makeNode();
    // Day 1: only A, 3 critical.
    $this->makeScan($a, '2026-07-01 12:00:00 UTC', ['critical' => 3]);
    // Day 2: B arrives with 2 serious; A's state persists.
    $this->makeScan($b, '2026-07-02 12:00:00 UTC', ['serious' => 2]);
    // Day 3: A re-scanned down to 1 critical — replaces its day-1 counts.
    $this->makeScan($a, '2026-07-03 12:00:00 UTC', ['critical' => 1]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertCount(3, $series);
    $this->assertSame(['2026-07-01', 3, 0], [$series[0]['date'], $series[0]['critical'], $series[0]['serious']]);
    $this->assertSame(['2026-07-02', 3, 2], [$series[1]['date'], $series[1]['critical'], $series[1]['serious']]);
    $this->assertSame(['2026-07-03', 1, 2], [$series[2]['date'], $series[2]['critical'], $series[2]['serious']]);
    $this->assertSame(5, $series[1]['total']);
    $this->assertSame(3, $series[2]['total']);
  }

  /**
   * Tests a same-day re-scan uses only the later scan's counts.
   */
  public function testSameDayRescanUsesLatest(): void {
    $a = $this->makeNode();
    $this->makeScan($a, '2026-07-01 09:00:00 UTC', ['critical' => 5]);
    $this->makeScan($a, '2026-07-01 15:00:00 UTC', ['critical' => 2]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertCount(1, $series);
    $this->assertSame(2, $series[0]['critical']);
  }

  /**
   * Tests scans of inaccessible nodes are excluded.
   */
  public function testExcludesInaccessibleNodes(): void {
    $secret = $this->makeNode(FALSE);
    $open = $this->makeNode();
    $this->makeScan($secret, '2026-07-01 12:00:00 UTC', ['critical' => 9]);
    $this->makeScan($open, '2026-07-01 12:00:00 UTC', ['minor' => 1]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertCount(1, $series);
    $this->assertSame(0, $series[0]['critical']);
    $this->assertSame(1, $series[0]['minor']);
  }

  /**
   * Tests needs-review is reported but excluded from the total.
   */
  public function testNeedsReviewExcludedFromTotal(): void {
    $a = $this->makeNode();
    $this->makeScan($a, '2026-07-01 12:00:00 UTC', ['critical' => 1, 'needs_review' => 4]);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $series = \Drupal::service('accessguard.trend_builder')->dailySeries();

    $this->assertSame(4, $series[0]['needs_review']);
    $this->assertSame(1, $series[0]['total']);
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter TrendBuilderTest web/modules/custom/accessguard/tests"`
Expected: FAIL — `ServiceNotFoundException: accessguard.trend_builder` (all 4 tests error).

- [ ] **Step 3: Implement**

Add to `web/modules/custom/accessguard/src/Repository/ScanRepository.php` (after the existing methods, inside the class):

```php
  /**
   * Every scan's metadata in one query, ordered by (created, id) ascending.
   *
   * @return array<int, array{nid: string, created: string, count_critical: string, count_serious: string, count_moderate: string, count_minor: string, count_needs_review: string}>
   *   Raw rows (numeric strings, as PDO returns them); callers cast.
   */
  public function allScanMeta(): array {
    $query = $this->database->select('accessguard_scan', 's');
    $query->addField('s', 'target_entity_id', 'nid');
    $query->fields('s', [
      'created',
      'count_critical',
      'count_serious',
      'count_moderate',
      'count_minor',
      'count_needs_review',
    ]);
    $query->orderBy('s.created');
    $query->orderBy('s.id');
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }
```

Create `web/modules/custom/accessguard/src/Service/TrendBuilder.php`:

```php
<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Folds scan history into a per-day site-state severity series.
 *
 * Each row is "site state as of end of that day": for every node scanned
 * on or before day D, its latest scan up to D contributes its stored
 * per-severity counts. Counts are as recorded at scan time — waivers are
 * not applied retroactively (historical waiver state is not
 * reconstructable), so this series can differ from the Overview's
 * open-violation numbers by design.
 */
class TrendBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRepository $scanRepository,
    protected AccountProxyInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The daily state series, oldest day first.
   *
   * @return array<int, array{date: string, critical: int, serious: int, moderate: int, minor: int, needs_review: int, total: int}>
   *   One row per day that had at least one scan of an accessible node.
   */
  public function dailySeries(): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $allowed = [];
    $byDay = [];
    foreach ($this->scanRepository->allScanMeta() as $row) {
      $nid = (int) $row['nid'];
      // Access-check each node once; scans of deleted or restricted nodes
      // are excluded entirely (same posture as ViolationAnalytics).
      if (!isset($allowed[$nid])) {
        $node = $nodeStorage->load($nid);
        $allowed[$nid] = $node && $node->access('view', $this->currentUser);
      }
      if (!$allowed[$nid]) {
        continue;
      }
      $day = $this->dateFormatter->format((int) $row['created'], 'custom', 'Y-m-d');
      $byDay[$day][] = $row;
    }
    ksort($byDay);

    $series = [];
    $state = [];
    foreach ($byDay as $day => $scans) {
      // Rows arrive (created, id)-ascending, so within a day the last write
      // per node wins — a same-day re-scan replaces the earlier counts.
      foreach ($scans as $row) {
        $state[(int) $row['nid']] = $row;
      }
      $sum = [
        'date' => $day,
        'critical' => 0,
        'serious' => 0,
        'moderate' => 0,
        'minor' => 0,
        'needs_review' => 0,
      ];
      foreach ($state as $row) {
        foreach (['critical', 'serious', 'moderate', 'minor', 'needs_review'] as $key) {
          $sum[$key] += (int) $row['count_' . $key];
        }
      }
      // Needs-review findings are uncertain by definition and stay out of
      // the total, consistent with the gate and the compliance summary.
      $sum['total'] = $sum['critical'] + $sum['serious'] + $sum['moderate'] + $sum['minor'];
      $series[] = $sum;
    }
    return $series;
  }

}
```

Register in `web/modules/custom/accessguard/accessguard.services.yml` (append after `accessguard.gate_evaluator`):

```yaml
  accessguard.trend_builder:
    class: Drupal\accessguard\Service\TrendBuilder
    arguments: ['@entity_type.manager', '@accessguard.scan_repository', '@current_user', '@date.formatter']
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter TrendBuilderTest web/modules/custom/accessguard/tests"`
Expected: OK (4 tests).

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/accessguard/src/Repository/ScanRepository.php web/modules/custom/accessguard/src/Service/TrendBuilder.php web/modules/custom/accessguard/accessguard.services.yml web/modules/custom/accessguard/tests/src/Kernel/TrendBuilderTest.php
git commit -m "feat(trends): TrendBuilder folds scan history into a daily state series

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Trends tab (route, controller, local task)

**Files:**
- Modify: `web/modules/custom/accessguard/src/Controller/AnalyticsController.php`
- Modify: `web/modules/custom/accessguard/accessguard.routing.yml`
- Modify: `web/modules/custom/accessguard/accessguard.links.task.yml`
- Test: `web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php` (add test)

**Interfaces:**
- Consumes: `TrendBuilder::dailySeries(): array` from Task 1 (rows `date/critical/serious/moderate/minor/needs_review/total`, oldest first).
- Produces: route `accessguard.analytics_trends` (`/admin/reports/accessguard/trends`, permission `view accessguard reports`), controller method `AnalyticsController::trends(): array`, local task `accessguard.trends_tab` (weight 30).

- [ ] **Step 1: Write the failing test**

Add to `web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php` (inspect its existing setUp/helpers first and reuse them for node+scan creation; the scan needs explicit `count_critical` and `created` values, e.g. via the storage `create([...])` call shown below if no helper fits):

```php
  /**
   * Tests the trends tab renders the state series newest-first with a note.
   */
  public function testTrendsTabRendersSeries(): void {
    $node = Node::create(['type' => 'page', 'title' => 'T', 'status' => 1]);
    $node->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
      'created' => strtotime('2026-07-01 12:00:00 UTC'),
      'count_critical' => 2,
    ])->save();

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $build = AnalyticsController::create($this->container)->trends();

    $this->assertSame('2026-07-01', $build['table']['#rows'][0][0]);
    $this->assertSame(2, $build['table']['#rows'][0][1]);
    $rendered = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('not applied retroactively', $rendered);
  }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter testTrendsTabRendersSeries web/modules/custom/accessguard/tests"`
Expected: FAIL — `Error: Call to undefined method ...AnalyticsController::trends()`.

- [ ] **Step 3: Implement**

In `AnalyticsController`:

Add `use Drupal\accessguard\Service\TrendBuilder;` to the imports, extend the constructor and `create()`:

```php
  public function __construct(
    protected ViolationAnalytics $analytics,
    protected TrendBuilder $trendBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('accessguard.violation_analytics'),
      $container->get('accessguard.trend_builder'),
    );
  }
```

Add the method after `byAuthor()`:

```php
  /**
   * Daily severity trend: site state as of end of each scan-day.
   */
  public function trends(): array {
    $rows = [];
    foreach (array_reverse($this->trendBuilder->dailySeries()) as $day) {
      $rows[] = [
        $day['date'],
        $day['critical'],
        $day['serious'],
        $day['moderate'],
        $day['minor'],
        $day['needs_review'],
        $day['total'],
      ];
    }
    return [
      // Historical waiver state is not reconstructable, so this series is
      // "as scanned", not "open now" — say so instead of quietly disagreeing
      // with the Overview's numbers.
      'note' => [
        '#markup' => '<p><em>' . $this->t('Counts are as recorded at scan time; waivers are not applied retroactively, so numbers can differ from the Overview.') . '</em></p>',
      ],
      'table' => [
        '#type' => 'table',
        '#caption' => $this->t('Violations over time (site state per scan-day)'),
        '#header' => [
          $this->t('Date'),
          $this->t('Critical'),
          $this->t('Serious'),
          $this->t('Moderate'),
          $this->t('Minor'),
          $this->t('Needs review'),
          $this->t('Total'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No scans recorded yet.'),
      ],
      '#cache' => [
        // Any scan write moves the series; node deletions/access changes
        // change which scans are visible.
        'tags' => ['accessguard_scan_list', 'node_list'],
        'contexts' => ['user.node_grants:view', 'user.permissions'],
      ],
    ];
  }
```

Append to `accessguard.routing.yml` (match surrounding style):

```yaml
accessguard.analytics_trends:
  path: '/admin/reports/accessguard/trends'
  defaults:
    _controller: 'Drupal\accessguard\Controller\AnalyticsController::trends'
    _title: 'AccessGuard: trends'
  requirements:
    _permission: 'view accessguard reports'
```

Append to `accessguard.links.task.yml`:

```yaml
accessguard.trends_tab:
  title: 'Trends'
  route_name: accessguard.analytics_trends
  base_route: accessguard.dashboard
  weight: 30
```

- [ ] **Step 4: Run the new test, then the full suite and phpcs**

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core --filter testTrendsTabRendersSeries web/modules/custom/accessguard/tests"`
Expected: OK (1 test).

Run: `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests"`
Expected: OK (119 tests; the 1 pre-existing Drush deprecation is known).

Run: `ddev exec "vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard web/modules/custom/accessguard_demo"`
Expected: no output (clean).

- [ ] **Step 5: Verify live and update README**

```bash
ddev drush cr
```

Then fetch `/admin/reports/accessguard/trends` as an admin (uli + cookies, port from `ddev describe`) and confirm: a 200, the note text, a Trends tab in the tab bar, and rows spanning 2026-07-04 through 2026-07-16 (the demo scan history).

Add to `README.md`'s "What's built" module bullet list, after the per-rule/per-author analytics line:

```markdown
  - a **Trends** tab charting the site's per-severity counts as a day-by-day state series from scan history (as-scanned numbers; waivers aren't applied retroactively)
```

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/src/Controller/AnalyticsController.php web/modules/custom/accessguard/accessguard.routing.yml web/modules/custom/accessguard/accessguard.links.task.yml web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php README.md
git commit -m "feat(trends): Trends dashboard tab with the daily severity series

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
