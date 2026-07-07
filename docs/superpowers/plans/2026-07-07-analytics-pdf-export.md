# Analytics Tabs + PDF Audit Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-rule / per-author accessibility analytics as tabs on the AccessGuard dashboard, plus a formal PDF audit report rendered by the existing Node scanner.

**Architecture:** A single `ViolationAnalytics` service aggregates the latest-scan violations (applying node-access and waiver filters in PHP) and feeds both the new analytics controller and a `ReportHtmlBuilder` that produces one self-contained HTML document. A `PdfClient` posts that HTML to a new `POST /pdf` endpoint on the scanner, which renders it with Puppeteer and streams back PDF bytes. The dashboard gains three local-task tabs (Overview / By rule / By author) and a second export button.

**Tech Stack:** Drupal 11 (PHP 8.3+), Guzzle HTTP client, PHPUnit kernel/unit tests; Node.js + Express + Puppeteer scanner with Jest tests.

## Global Constraints

- PHP floor: 8.3+ (CI runs 8.4). Use constructor property promotion and PHP attributes, matching existing module code.
- Scanner runs on Node 22 (CI). ES modules (`import`), matching existing `scanner/src/*.js`.
- All new report/analytics pages require the existing `view accessguard reports` permission.
- Node-access rule: never count or display a node the current user cannot `view` — mirror `DashboardController::overview()`.
- Waiver rule: a violation is "waived" when its `WaiverMatcher::fingerprint(rule_id, selector)` appears in `WaiverMatcher::waivedFingerprints($nid)`; otherwise "open".
- All dynamic text rendered into HTML must pass through `Drupal\Component\Utility\Html::escape()`.
- Report HTML must be fully self-contained (inline `<style>`, no external images/fonts/scripts) — the scanner blocks all outbound requests during PDF render.
- phpcs must stay clean: `vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard`.
- Entity field names (verbatim): scan has `target_entity_type`, `target_entity_id`, `content_author` (entity_reference→user), `created`, `count_critical|serious|moderate|minor`; violation has `scan_id`, `rule_id`, `impact`, `wcag_criterion`, `selector`.
- Commit after each task. Conventional-commit prefixes (`feat:`, `test:`, `docs:`). End commit messages with the `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>` trailer.

---

## File Structure

**New (Drupal):**
- `web/modules/custom/accessguard/src/Service/ViolationAnalytics.php` — aggregation service (`byRule()`, `byAuthor()`).
- `web/modules/custom/accessguard/src/Controller/AnalyticsController.php` — renders the two analytics tab pages.
- `web/modules/custom/accessguard/src/Service/ReportHtmlBuilder.php` — builds the self-contained audit HTML.
- `web/modules/custom/accessguard/src/Service/PdfClient.php` — POSTs HTML to the scanner `/pdf`, returns bytes.
- `web/modules/custom/accessguard/accessguard.links.task.yml` — the three dashboard tabs.
- Tests: `tests/src/Kernel/ViolationAnalyticsTest.php`, `tests/src/Kernel/AnalyticsControllerTest.php`, `tests/src/Kernel/ReportHtmlBuilderTest.php`, `tests/src/Unit/PdfClientTest.php`, `tests/src/Kernel/PdfExportTest.php`.

**Modified (Drupal):**
- `accessguard.services.yml` — register the three new services.
- `accessguard.routing.yml` — `accessguard.analytics_rules`, `accessguard.analytics_authors`, `accessguard.audit_export_pdf`.
- `src/Controller/DashboardController.php` — add `exportPdf()` action + PDF button in `overview()`.

**New (scanner):**
- `scanner/src/pdf.js` — `renderPdf(html)` using Puppeteer.
- Tests: `scanner/test/pdf.test.js`.

**Modified (scanner):**
- `scanner/src/server.js` — `POST /pdf` route with auth + 5mb limit.

---

## Task 1: `ViolationAnalytics` service — data + aggregation

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/ViolationAnalytics.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml`
- Test: `web/modules/custom/accessguard/tests/src/Kernel/ViolationAnalyticsTest.php`

**Interfaces:**
- Consumes: `accessguard.scan_repository` (`ScanRepository::latestScanIdByNode(): array<int,int>`), `accessguard.waiver_matcher` (`WaiverMatcher::waivedFingerprints(int): array<string,string>`, `WaiverMatcher::fingerprint(string,string): string`), `@entity_type.manager`, `@database`.
- Produces:
  - `byRule(): array<int, array{rule_id:string, impact:string, wcag:string, pages:int, open:int, waived:int}>` — sorted by `open` desc.
  - `byAuthor(): array<int, array{uid:?int, name:string, pages:int, critical:int, serious:int, moderate:int, minor:int, waived:int}>` — severity counts are of OPEN violations only; sorted by total open desc.
  - `summary(): array{pages:int, open:int, critical:int, serious:int, moderate:int, minor:int}` — open counts across accessible nodes.
  - Access filtering is applied inside the service using the current user; callers get already-filtered data.

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Kernel/ViolationAnalyticsTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests ViolationAnalytics aggregation, access filtering, and waiver split.
 *
 * @group accessguard
 */
class ViolationAnalyticsTest extends KernelTestBase {

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
   * Creates a completed scan for a node, returns the scan id.
   */
  private function makeScan(int $nid, ?int $authorUid = NULL, array $counts = []): int {
    $values = [
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
    ];
    foreach ($counts as $sev => $n) {
      $values['count_' . $sev] = $n;
    }
    if ($authorUid !== NULL) {
      $values['content_author'] = $authorUid;
    }
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create($values);
    $scan->save();
    return (int) $scan->id();
  }

  /**
   * Adds a violation to a scan.
   */
  private function addViolation(int $scanId, string $rule, string $impact, string $selector, string $wcag = 'wcag2aa'): void {
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scanId,
      'rule_id' => $rule,
      'impact' => $impact,
      'wcag_criterion' => $wcag,
      'selector' => $selector,
    ])->save();
  }

  /**
   * Tests by-rule aggregation splits open vs waived and counts pages.
   */
  public function testByRuleAggregatesAndSplitsWaivers(): void {
    $a = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $a->save();
    $b = Node::create(['type' => 'page', 'title' => 'B', 'status' => 1]);
    $b->save();
    $scanA = $this->makeScan((int) $a->id());
    $scanB = $this->makeScan((int) $b->id());
    // image-alt appears on both pages; on B it is waived.
    $this->addViolation($scanA, 'image-alt', 'critical', 'img');
    $this->addViolation($scanB, 'image-alt', 'critical', 'img');
    // label appears once, open.
    $this->addViolation($scanA, 'label', 'serious', 'input');
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver((int) $b->id(), 'image-alt', 'img', 'false_positive', 'decorative', 1);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $rows = \Drupal::service('accessguard.violation_analytics')->byRule();

    $byRule = [];
    foreach ($rows as $r) {
      $byRule[$r['rule_id']] = $r;
    }
    // image-alt: 2 pages affected, 1 open, 1 waived.
    $this->assertSame(2, $byRule['image-alt']['pages']);
    $this->assertSame(1, $byRule['image-alt']['open']);
    $this->assertSame(1, $byRule['image-alt']['waived']);
    // label: 1 page, 1 open.
    $this->assertSame(1, $byRule['label']['open']);
    $this->assertSame(0, $byRule['label']['waived']);
  }

  /**
   * Tests inaccessible nodes are excluded from aggregation.
   */
  public function testExcludesInaccessibleNodes(): void {
    $secret = Node::create(['type' => 'page', 'title' => 'secret', 'status' => 0]);
    $secret->save();
    $open = Node::create(['type' => 'page', 'title' => 'open', 'status' => 1]);
    $open->save();
    $this->addViolation($this->makeScan((int) $secret->id()), 'image-alt', 'critical', 'img');
    $this->addViolation($this->makeScan((int) $open->id()), 'label', 'serious', 'input');

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $rows = \Drupal::service('accessguard.violation_analytics')->byRule();

    $rules = array_column($rows, 'rule_id');
    $this->assertContains('label', $rules);
    $this->assertNotContains('image-alt', $rules);
  }

  /**
   * Tests by-author counts open violations by severity per content author.
   */
  public function testByAuthorCountsOpenSeverities(): void {
    $author = $this->createUser(['access content']);
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    $scan = $this->makeScan((int) $node->id(), (int) $author->id());
    $this->addViolation($scan, 'image-alt', 'critical', 'img');
    $this->addViolation($scan, 'label', 'serious', 'input');

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $rows = \Drupal::service('accessguard.violation_analytics')->byAuthor();

    $this->assertCount(1, $rows);
    $this->assertSame((int) $author->id(), $rows[0]['uid']);
    $this->assertSame(1, $rows[0]['critical']);
    $this->assertSame(1, $rows[0]['serious']);
    $this->assertSame(1, $rows[0]['pages']);
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/ViolationAnalyticsTest.php`
Expected: FAIL — service `accessguard.violation_analytics` does not exist (container exception).

- [ ] **Step 3: Create the service class**

Create `web/modules/custom/accessguard/src/Service/ViolationAnalytics.php`:

```php
<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Aggregates latest-scan violations by rule and by content author.
 *
 * Node-access filtering and the open/waived split happen here in PHP (not in
 * SQL) so callers — the analytics tabs and the PDF report — share one
 * definition of the numbers. See WaiverMatcher for the fingerprint scheme.
 */
class ViolationAnalytics {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRepository $scanRepository,
    protected WaiverMatcher $waiverMatcher,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Latest-scan violations grouped by rule.
   *
   * @return array<int, array{rule_id:string, impact:string, wcag:string, pages:int, open:int, waived:int}>
   *   Sorted by open count descending.
   */
  public function byRule(): array {
    $rules = [];
    foreach ($this->accessibleScans() as $ctx) {
      $seenPages = [];
      foreach ($ctx['violations'] as $v) {
        $rule = (string) $v->get('rule_id')->value;
        $selector = (string) $v->get('selector')->value;
        $rules[$rule] ??= [
          'rule_id' => $rule,
          'impact' => (string) $v->get('impact')->value,
          'wcag' => (string) $v->get('wcag_criterion')->value,
          'pages' => 0,
          'open' => 0,
          'waived' => 0,
          '_pages_seen' => [],
        ];
        if (empty($rules[$rule]['_pages_seen'][$ctx['nid']])) {
          $rules[$rule]['_pages_seen'][$ctx['nid']] = TRUE;
          $rules[$rule]['pages']++;
        }
        $fp = WaiverMatcher::fingerprint($rule, $selector);
        if (isset($ctx['waived'][$fp])) {
          $rules[$rule]['waived']++;
        }
        else {
          $rules[$rule]['open']++;
        }
      }
    }
    foreach ($rules as &$r) {
      unset($r['_pages_seen']);
    }
    unset($r);
    usort($rules, fn($a, $b) => $b['open'] <=> $a['open']);
    return array_values($rules);
  }

  /**
   * Latest-scan open violations grouped by content author.
   *
   * @return array<int, array{uid:?int, name:string, pages:int, critical:int, serious:int, moderate:int, minor:int, waived:int}>
   *   Sorted by total open descending.
   */
  public function byAuthor(): array {
    $userStorage = $this->entityTypeManager->getStorage('user');
    $authors = [];
    foreach ($this->accessibleScans() as $ctx) {
      $uid = $ctx['author_uid'];
      $key = $uid ?? 0;
      if (!isset($authors[$key])) {
        $name = 'Unknown';
        if ($uid && ($u = $userStorage->load($uid))) {
          $name = $u->getDisplayName();
        }
        $authors[$key] = [
          'uid' => $uid,
          'name' => $name,
          'pages' => 0,
          'critical' => 0,
          'serious' => 0,
          'moderate' => 0,
          'minor' => 0,
          'waived' => 0,
        ];
      }
      $authors[$key]['pages']++;
      foreach ($ctx['violations'] as $v) {
        $fp = WaiverMatcher::fingerprint((string) $v->get('rule_id')->value, (string) $v->get('selector')->value);
        if (isset($ctx['waived'][$fp])) {
          $authors[$key]['waived']++;
          continue;
        }
        $impact = (string) $v->get('impact')->value;
        if (isset($authors[$key][$impact])) {
          $authors[$key][$impact]++;
        }
      }
    }
    $total = fn(array $a) => $a['critical'] + $a['serious'] + $a['moderate'] + $a['minor'];
    usort($authors, fn($a, $b) => $total($b) <=> $total($a));
    return array_values($authors);
  }

  /**
   * Open-violation totals across accessible nodes.
   *
   * @return array{pages:int, open:int, critical:int, serious:int, moderate:int, minor:int}
   */
  public function summary(): array {
    $out = ['pages' => 0, 'open' => 0, 'critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
    foreach ($this->accessibleScans() as $ctx) {
      $out['pages']++;
      foreach ($ctx['violations'] as $v) {
        $fp = WaiverMatcher::fingerprint((string) $v->get('rule_id')->value, (string) $v->get('selector')->value);
        if (isset($ctx['waived'][$fp])) {
          continue;
        }
        $out['open']++;
        $impact = (string) $v->get('impact')->value;
        if (isset($out[$impact])) {
          $out[$impact]++;
        }
      }
    }
    return $out;
  }

  /**
   * Yields per-node context for the latest scan of each accessible node.
   *
   * @return \Generator<array{nid:int, author_uid:?int, violations:array, waived:array<string,string>}>
   */
  protected function accessibleScans(): \Generator {
    $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
    $violationStorage = $this->entityTypeManager->getStorage('accessguard_violation');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $latestIds = $this->scanRepository->latestScanIdByNode();
    $scans = $scanStorage->loadMultiple(array_values($latestIds));
    foreach ($scans as $scan) {
      $nid = (int) $scan->get('target_entity_id')->value;
      $node = $nodeStorage->load($nid);
      if (!$node || !$node->access('view', $this->currentUser)) {
        continue;
      }
      $violations = $violationStorage->loadByProperties(['scan_id' => $scan->id()]);
      yield [
        'nid' => $nid,
        'author_uid' => $scan->get('content_author')->target_id ? (int) $scan->get('content_author')->target_id : NULL,
        'violations' => $violations,
        'waived' => $this->waiverMatcher->waivedFingerprints($nid),
      ];
    }
  }

}
```

- [ ] **Step 4: Register the service**

Add to `web/modules/custom/accessguard/accessguard.services.yml` (under the existing `services:` map):

```yaml
  accessguard.violation_analytics:
    class: Drupal\accessguard\Service\ViolationAnalytics
    arguments: ['@entity_type.manager', '@accessguard.scan_repository', '@accessguard.waiver_matcher', '@current_user']
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/ViolationAnalyticsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: phpcs and commit**

Run: `vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard/src/Service/ViolationAnalytics.php`
Expected: no errors. Fix any reported issues, then:

```bash
git add web/modules/custom/accessguard/src/Service/ViolationAnalytics.php \
  web/modules/custom/accessguard/accessguard.services.yml \
  web/modules/custom/accessguard/tests/src/Kernel/ViolationAnalyticsTest.php
git commit -m "feat(module): ViolationAnalytics service for per-rule/per-author aggregation"
```

---

## Task 2: Analytics controller + tab routes

**Files:**
- Create: `web/modules/custom/accessguard/src/Controller/AnalyticsController.php`
- Create: `web/modules/custom/accessguard/accessguard.links.task.yml`
- Modify: `web/modules/custom/accessguard/accessguard.routing.yml`
- Test: `web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php`

**Interfaces:**
- Consumes: `accessguard.violation_analytics` (`byRule()`, `byAuthor()` from Task 1).
- Produces: routes `accessguard.analytics_rules`, `accessguard.analytics_authors`; controller methods `byRule()`, `byAuthor()` returning render arrays with a `#type => 'table'` under key `table`.

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Controller\AnalyticsController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the analytics tab controller renders rule/author tables.
 *
 * @group accessguard
 */
class AnalyticsControllerTest extends KernelTestBase {

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
   * Tests the by-rule page lists a rule with its open count.
   */
  public function testByRulePageListsRules(): void {
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
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

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $build = AnalyticsController::create($this->container)->byRule();
    $this->assertNotEmpty($build['table']['#rows']);
    $this->assertSame('image-alt', $build['table']['#rows'][0][0]);
  }

  /**
   * Tests the by-author page renders an empty state with no data.
   */
  public function testByAuthorEmptyState(): void {
    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $build = AnalyticsController::create($this->container)->byAuthor();
    $this->assertSame([], $build['table']['#rows']);
    $this->assertNotEmpty($build['table']['#empty']);
  }

  /**
   * Tests the analytics routes require the reports permission.
   */
  public function testRoutesRequireReportsPermission(): void {
    $accessManager = \Drupal::service('access_manager');
    $viewer = $this->createUser(['view accessguard reports']);
    $nobody = $this->createUser([]);
    $this->assertTrue($accessManager->checkNamedRoute('accessguard.analytics_rules', [], $viewer));
    $this->assertFalse($accessManager->checkNamedRoute('accessguard.analytics_rules', [], $nobody));
    $this->assertTrue($accessManager->checkNamedRoute('accessguard.analytics_authors', [], $viewer));
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php`
Expected: FAIL — `AnalyticsController` class not found.

- [ ] **Step 3: Create the controller**

Create `web/modules/custom/accessguard/src/Controller/AnalyticsController.php`:

```php
<?php

namespace Drupal\accessguard\Controller;

use Drupal\accessguard\Service\ViolationAnalytics;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the per-rule and per-author analytics tabs.
 */
class AnalyticsController extends ControllerBase {

  public function __construct(protected ViolationAnalytics $analytics) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('accessguard.violation_analytics'));
  }

  /**
   * Violations grouped by rule, worst first.
   */
  public function byRule(): array {
    $rows = [];
    foreach ($this->analytics->byRule() as $r) {
      $rows[] = [
        $r['rule_id'],
        $r['impact'],
        $r['wcag'],
        $r['pages'],
        $r['open'],
        $r['waived'],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Rule'),
          $this->t('Impact'),
          $this->t('WCAG'),
          $this->t('Pages affected'),
          $this->t('Open'),
          $this->t('Waived'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No violations found in the latest scans.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Open violations grouped by content author, worst first.
   */
  public function byAuthor(): array {
    $rows = [];
    foreach ($this->analytics->byAuthor() as $a) {
      $rows[] = [
        $a['name'],
        $a['pages'],
        $a['critical'],
        $a['serious'],
        $a['moderate'],
        $a['minor'],
        $a['waived'],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Author'),
          $this->t('Pages'),
          $this->t('Critical'),
          $this->t('Serious'),
          $this->t('Moderate'),
          $this->t('Minor'),
          $this->t('Waived'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No scanned content with a known author yet.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
```

- [ ] **Step 4: Add the routes**

Append to `web/modules/custom/accessguard/accessguard.routing.yml`:

```yaml
accessguard.analytics_rules:
  path: '/admin/reports/accessguard/rules'
  defaults:
    _controller: 'Drupal\accessguard\Controller\AnalyticsController::byRule'
    _title: 'AccessGuard: by rule'
  requirements:
    _permission: 'view accessguard reports'

accessguard.analytics_authors:
  path: '/admin/reports/accessguard/authors'
  defaults:
    _controller: 'Drupal\accessguard\Controller\AnalyticsController::byAuthor'
    _title: 'AccessGuard: by author'
  requirements:
    _permission: 'view accessguard reports'
```

- [ ] **Step 5: Add the tabs**

Create `web/modules/custom/accessguard/accessguard.links.task.yml`:

```yaml
accessguard.overview_tab:
  title: 'Overview'
  route_name: accessguard.dashboard
  base_route: accessguard.dashboard

accessguard.rules_tab:
  title: 'By rule'
  route_name: accessguard.analytics_rules
  base_route: accessguard.dashboard

accessguard.authors_tab:
  title: 'By author'
  route_name: accessguard.analytics_authors
  base_route: accessguard.dashboard
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: phpcs and commit**

Run: `vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard/src/Controller/AnalyticsController.php web/modules/custom/accessguard/accessguard.links.task.yml web/modules/custom/accessguard/accessguard.routing.yml`
Expected: no errors. Then:

```bash
git add web/modules/custom/accessguard/src/Controller/AnalyticsController.php \
  web/modules/custom/accessguard/accessguard.links.task.yml \
  web/modules/custom/accessguard/accessguard.routing.yml \
  web/modules/custom/accessguard/tests/src/Kernel/AnalyticsControllerTest.php
git commit -m "feat(module): analytics tabs - by rule and by author"
```

---

## Task 3: Scanner `POST /pdf` endpoint

**Files:**
- Create: `scanner/src/pdf.js`
- Modify: `scanner/src/server.js`
- Test: `scanner/test/pdf.test.js`

**Interfaces:**
- Produces: `renderPdf(html: string): Promise<Buffer>` in `scanner/src/pdf.js`; `POST /pdf` route accepting `{ html }`, responding `application/pdf`.
- Consumes: existing `isAuthorized(req)` in `server.js`.

- [ ] **Step 1: Write the failing test**

Create `scanner/test/pdf.test.js`:

```js
import { app } from '../src/server.js';
import http from 'node:http';

function listen() {
  const server = http.createServer(app).listen(0);
  return { server, port: server.address().port };
}

test('POST /pdf returns PDF bytes for valid HTML', async () => {
  const { server, port } = listen();
  try {
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html: '<!doctype html><html><body><h1>Audit</h1></body></html>' }),
    });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/application\/pdf/);
    const buf = Buffer.from(await res.arrayBuffer());
    expect(buf.subarray(0, 4).toString('latin1')).toBe('%PDF');
  } finally {
    server.close();
  }
}, 30000);

test('POST /pdf returns 400 on missing html', async () => {
  const { server, port } = listen();
  try {
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({}),
    });
    expect(res.status).toBe(400);
  } finally {
    server.close();
  }
});

test('POST /pdf requires the token when SCANNER_AUTH_TOKEN is set', async () => {
  process.env.SCANNER_AUTH_TOKEN = 'sekret';
  const { server, port } = listen();
  try {
    const noToken = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html: '<h1>x</h1>' }),
    });
    expect(noToken.status).toBe(401);
  } finally {
    delete process.env.SCANNER_AUTH_TOKEN;
    server.close();
  }
});

test('POST /pdf renders even when HTML references an unreachable subresource', async () => {
  const { server, port } = listen();
  try {
    const html = '<!doctype html><html><head>'
      + '<img src="http://127.0.0.1:1/x.png">'
      + '</head><body><h1>Audit</h1></body></html>';
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html }),
    });
    expect(res.status).toBe(200);
    const buf = Buffer.from(await res.arrayBuffer());
    expect(buf.subarray(0, 4).toString('latin1')).toBe('%PDF');
  } finally {
    server.close();
  }
}, 30000);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd scanner && npm test -- pdf.test.js`
Expected: FAIL — `/pdf` route returns 404 (no such route).

- [ ] **Step 3: Create the render module**

Create `scanner/src/pdf.js`:

```js
import puppeteer from 'puppeteer';

/**
 * Renders self-contained HTML to a PDF Buffer.
 *
 * The report HTML is expected to be fully self-contained. As defense in depth
 * (and matching the scanner's SSRF posture), every outbound request the page
 * attempts during rendering is aborted — nothing is fetched from the network.
 *
 * @param {string} html
 * @returns {Promise<Buffer>}
 */
export async function renderPdf(html) {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  try {
    const page = await browser.newPage();
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      // Allow only the initial document set via setContent; block all network.
      if (req.url().startsWith('data:') || req.resourceType() === 'document') {
        req.continue();
        return;
      }
      req.abort('blockedbyclient').catch(() => {});
    });
    await page.setContent(html, { waitUntil: 'load', timeout: 20000 });
    const pdf = await page.pdf({
      format: 'A4',
      printBackground: true,
      margin: { top: '1cm', bottom: '1cm', left: '1cm', right: '1cm' },
    });
    return Buffer.from(pdf);
  } finally {
    await browser.close();
  }
}
```

- [ ] **Step 4: Add the route**

In `scanner/src/server.js`, add the import at the top (next to the existing `runScan` import):

```js
import { renderPdf } from './pdf.js';
```

Then add the route after the existing `/scan` handler (the route-specific `express.json({ limit: '5mb' })` middleware overrides the global 1mb limit for this endpoint only):

```js
app.post('/pdf', express.json({ limit: '5mb' }), async (req, res) => {
  if (!isAuthorized(req)) {
    return res.status(401).json({ error: 'unauthorized' });
  }
  const { html } = req.body || {};
  if (!html || typeof html !== 'string') {
    return res.status(400).json({ error: 'invalid_html' });
  }
  try {
    const pdf = await renderPdf(html);
    res.setHeader('Content-Type', 'application/pdf');
    res.send(pdf);
  } catch (err) {
    console.error('[accessguard-scanner] pdf failed:', err);
    res.status(500).json({ error: 'pdf_failed' });
  }
});
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd scanner && npm test -- pdf.test.js`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full scanner suite and commit**

Run: `cd scanner && npm test`
Expected: all tests pass (existing scan/urlGuard/pinnedFetch + new pdf).

```bash
git add scanner/src/pdf.js scanner/src/server.js scanner/test/pdf.test.js
git commit -m "feat(scanner): POST /pdf renders self-contained HTML to PDF"
```

---

## Task 4: `ReportHtmlBuilder` — self-contained audit HTML

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/ReportHtmlBuilder.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml`
- Test: `web/modules/custom/accessguard/tests/src/Kernel/ReportHtmlBuilderTest.php`

**Interfaces:**
- Consumes: `accessguard.violation_analytics` (`byRule()`, `byAuthor()`, `summary()`), `accessguard.scan_repository` (`latestScanIdByNode()`), `accessguard.waiver_matcher` (`waivedFingerprints()`, `fingerprint()`), `@entity_type.manager`, `@current_user`, `@date.formatter`.
- Produces: `build(): string` — one self-contained HTML document.

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Kernel/ReportHtmlBuilderTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests ReportHtmlBuilder produces a self-contained audit document.
 *
 * @group accessguard
 */
class ReportHtmlBuilderTest extends KernelTestBase {

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
   * Tests the report includes headings, the rule, and the waiver reason.
   */
  public function testBuildIncludesSectionsAndWaiverReason(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Homepage', 'status' => 1]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
      'url' => 'http://example.com/home',
    ]);
    $scan->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'image-alt',
      'impact' => 'critical',
      'wcag_criterion' => 'wcag2a',
      'selector' => 'img',
    ])->save();
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver((int) $node->id(), 'image-alt', 'img', 'false_positive', 'Decorative banner', 1);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $html = \Drupal::service('accessguard.report_html_builder')->build();

    $this->assertStringContainsString('Accessibility audit report', $html);
    $this->assertStringContainsString('Homepage', $html);
    $this->assertStringContainsString('image-alt', $html);
    // Waived items carry their justification.
    $this->assertStringContainsString('Decorative banner', $html);
    // Self-contained: no external asset references.
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('src="http', $html);
  }

  /**
   * Tests markup in a node title is escaped, not emitted raw.
   */
  public function testTitleIsEscaped(): void {
    $node = Node::create(['type' => 'page', 'title' => '<b>x</b>', 'status' => 1]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
    ]);
    $scan->save();

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $html = \Drupal::service('accessguard.report_html_builder')->build();
    $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/ReportHtmlBuilderTest.php`
Expected: FAIL — service `accessguard.report_html_builder` not found.

- [ ] **Step 3: Create the builder**

Create `web/modules/custom/accessguard/src/Service/ReportHtmlBuilder.php`:

```php
<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Builds a self-contained HTML audit report for PDF rendering.
 *
 * The output inlines all CSS and references no external assets, so the scanner
 * can render it to PDF with all outbound network requests blocked.
 */
class ReportHtmlBuilder {

  public function __construct(
    protected ViolationAnalytics $analytics,
    protected ScanRepository $scanRepository,
    protected WaiverMatcher $waiverMatcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds the complete audit report HTML document.
   */
  public function build(): string {
    $siteName = Html::escape(\Drupal::config('system.site')->get('name') ?? 'Site');
    $date = $this->dateFormatter->format(\Drupal::time()->getRequestTime(), 'medium');
    $preparedBy = Html::escape($this->currentUser->getDisplayName());

    $parts = [];
    $parts[] = '<!doctype html><html><head><meta charset="utf-8"><style>' . $this->css() . '</style></head><body>';
    $parts[] = '<section class="cover"><h1>Accessibility audit report</h1>'
      . '<p class="site">' . $siteName . '</p>'
      . '<p class="meta">Generated ' . Html::escape($date) . ' &middot; Prepared by ' . $preparedBy . '</p></section>';
    $parts[] = $this->summarySection();
    $parts[] = $this->byRuleSection();
    $parts[] = $this->byAuthorSection();
    $parts[] = $this->findingsSection();
    $parts[] = '</body></html>';
    return implode('', $parts);
  }

  /**
   * Inline stylesheet.
   */
  protected function css(): string {
    return 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#1a1a1a;font-size:12px}'
      . 'h1{font-size:26px;margin:0 0 8px}h2{font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;margin-top:24px}'
      . '.cover{padding:60px 0;text-align:center;page-break-after:always}.site{font-size:18px;font-weight:bold}'
      . '.meta{color:#666}table{width:100%;border-collapse:collapse;margin:8px 0}'
      . 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;vertical-align:top}'
      . 'th{background:#f0f0f0}.waived{color:#666}.page-block{page-break-inside:avoid;margin:12px 0}';
  }

  /**
   * Compliance summary block.
   */
  protected function summarySection(): string {
    $s = $this->analytics->summary();
    return '<section><h2>Compliance summary</h2><ul>'
      . '<li>Pages scanned: ' . (int) $s['pages'] . '</li>'
      . '<li>Total open violations: ' . (int) $s['open'] . '</li>'
      . '<li>Critical: ' . (int) $s['critical'] . ', Serious: ' . (int) $s['serious']
      . ', Moderate: ' . (int) $s['moderate'] . ', Minor: ' . (int) $s['minor'] . '</li></ul></section>';
  }

  /**
   * Violations-by-rule table.
   */
  protected function byRuleSection(): string {
    $rows = '';
    foreach ($this->analytics->byRule() as $r) {
      $rows .= '<tr><td>' . Html::escape($r['rule_id']) . '</td><td>' . Html::escape($r['impact'])
        . '</td><td>' . Html::escape($r['wcag']) . '</td><td>' . (int) $r['pages']
        . '</td><td>' . (int) $r['open'] . '</td><td>' . (int) $r['waived'] . '</td></tr>';
    }
    if ($rows === '') {
      $rows = '<tr><td colspan="6">No violations found.</td></tr>';
    }
    return '<section><h2>Violations by rule</h2><table><thead><tr>'
      . '<th>Rule</th><th>Impact</th><th>WCAG</th><th>Pages</th><th>Open</th><th>Waived</th>'
      . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
  }

  /**
   * Violations-by-author table.
   */
  protected function byAuthorSection(): string {
    $rows = '';
    foreach ($this->analytics->byAuthor() as $a) {
      $rows .= '<tr><td>' . Html::escape($a['name']) . '</td><td>' . (int) $a['pages']
        . '</td><td>' . (int) $a['critical'] . '</td><td>' . (int) $a['serious']
        . '</td><td>' . (int) $a['moderate'] . '</td><td>' . (int) $a['minor']
        . '</td><td>' . (int) $a['waived'] . '</td></tr>';
    }
    if ($rows === '') {
      $rows = '<tr><td colspan="7">No scanned content with a known author.</td></tr>';
    }
    return '<section><h2>Violations by author</h2><table><thead><tr>'
      . '<th>Author</th><th>Pages</th><th>Critical</th><th>Serious</th><th>Moderate</th><th>Minor</th><th>Waived</th>'
      . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
  }

  /**
   * Per-page findings, including waived items with their reasons.
   */
  protected function findingsSection(): string {
    $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
    $violationStorage = $this->entityTypeManager->getStorage('accessguard_violation');
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $waiverStorage = $this->entityTypeManager->getStorage('accessguard_waiver');

    $out = '<section><h2>Findings by page</h2>';
    $latestIds = $this->scanRepository->latestScanIdByNode();
    foreach ($scanStorage->loadMultiple(array_values($latestIds)) as $scan) {
      $nid = (int) $scan->get('target_entity_id')->value;
      $node = $nodeStorage->load($nid);
      if (!$node || !$node->access('view', $this->currentUser)) {
        continue;
      }
      $date = $this->dateFormatter->format((int) $scan->get('created')->value, 'short');
      $url = Html::escape((string) $scan->get('url')->value);
      $out .= '<div class="page-block"><h3>' . Html::escape($node->label()) . '</h3>'
        . '<p class="meta">' . $url . ' &middot; last scan ' . Html::escape($date) . '</p>';

      $waived = $this->waiverMatcher->waivedFingerprints($nid);
      $reasons = $this->waiverReasons($waiverStorage, $nid);
      $violations = $violationStorage->loadByProperties(['scan_id' => $scan->id()]);
      if (!$violations) {
        $out .= '<p>No violations in the latest scan.</p></div>';
        continue;
      }
      $out .= '<table><thead><tr><th>Rule</th><th>Impact</th><th>WCAG</th><th>Selector</th><th>Status</th></tr></thead><tbody>';
      foreach ($violations as $v) {
        $rule = (string) $v->get('rule_id')->value;
        $selector = (string) $v->get('selector')->value;
        $fp = WaiverMatcher::fingerprint($rule, $selector);
        if (isset($waived[$fp])) {
          $status = 'Waived (' . Html::escape(str_replace('_', ' ', $waived[$fp])) . ')';
          if (isset($reasons[$fp]) && $reasons[$fp] !== '') {
            $status .= ': ' . Html::escape($reasons[$fp]);
          }
          $cls = ' class="waived"';
        }
        else {
          $status = 'Open';
          $cls = '';
        }
        $out .= '<tr' . $cls . '><td>' . Html::escape($rule) . '</td><td>' . Html::escape((string) $v->get('impact')->value)
          . '</td><td>' . Html::escape((string) $v->get('wcag_criterion')->value) . '</td><td>' . Html::escape($selector)
          . '</td><td>' . $status . '</td></tr>';
      }
      $out .= '</tbody></table></div>';
    }
    return $out . '</section>';
  }

  /**
   * Waiver reasons for a node keyed by rule+selector fingerprint.
   *
   * @return array<string, string>
   */
  protected function waiverReasons($waiverStorage, int $nid): array {
    $ids = $waiverStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nid)
      ->execute();
    $map = [];
    foreach ($waiverStorage->loadMultiple($ids) as $w) {
      $fp = WaiverMatcher::fingerprint($w->get('rule_id')->value, (string) $w->get('selector')->value);
      $map[$fp] = (string) $w->get('reason')->value;
    }
    return $map;
  }

}
```

- [ ] **Step 4: Register the service**

Add to `web/modules/custom/accessguard/accessguard.services.yml`:

```yaml
  accessguard.report_html_builder:
    class: Drupal\accessguard\Service\ReportHtmlBuilder
    arguments: ['@accessguard.violation_analytics', '@accessguard.scan_repository', '@accessguard.waiver_matcher', '@entity_type.manager', '@current_user', '@date.formatter']
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/ReportHtmlBuilderTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: phpcs and commit**

Run: `vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard/src/Service/ReportHtmlBuilder.php`
Expected: no errors. (If `\Drupal::config`/`\Drupal::time` static-call sniffs complain, inject `@config.factory` and `@datetime.time` instead and adjust the constructor — keep it clean.) Then:

```bash
git add web/modules/custom/accessguard/src/Service/ReportHtmlBuilder.php \
  web/modules/custom/accessguard/accessguard.services.yml \
  web/modules/custom/accessguard/tests/src/Kernel/ReportHtmlBuilderTest.php
git commit -m "feat(module): ReportHtmlBuilder for self-contained audit HTML"
```

---

## Task 5: `PdfClient` — POST HTML to the scanner

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/PdfClient.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml`
- Test: `web/modules/custom/accessguard/tests/src/Unit/PdfClientTest.php`

**Interfaces:**
- Consumes: `@http_client` (Guzzle `ClientInterface`), `@config.factory` — mirrors `ScanRunner`.
- Produces: `PdfClient::render(string $html): string` — returns raw PDF bytes, throws `\RuntimeException` on failure. Sends `X-Scanner-Token` when `scanner_auth_token` is set, POSTs to `{scanner_endpoint}/pdf`.

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Unit/PdfClientTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Service\PdfClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Tests PdfClient's HTTP interaction with the scanner /pdf endpoint.
 *
 * @group accessguard
 */
class PdfClientTest extends UnitTestCase {

  /**
   * Tests a 200 returns the raw PDF body and posts to /pdf with the token.
   */
  public function testRenderReturnsBytesAndSendsToken(): void {
    $transactions = [];
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 body')]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($transactions));
    $client = new Client(['handler' => $stack]);
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => [
        'scanner_endpoint' => 'http://scanner:3000',
        'scanner_auth_token' => 'sekret',
      ],
    ]);
    $bytes = (new PdfClient($client, $config))->render('<h1>x</h1>');
    $this->assertStringStartsWith('%PDF', $bytes);
    $request = $transactions[0]['request'];
    $this->assertSame('http://scanner:3000/pdf', (string) $request->getUri());
    $this->assertSame('sekret', $request->getHeaderLine('X-Scanner-Token'));
  }

  /**
   * Tests an HTTP error throws.
   */
  public function testHttpErrorThrows(): void {
    $mock = new MockHandler([new Response(500, [], 'pdf_failed')]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub(['accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000']]);
    $this->expectException(\RuntimeException::class);
    (new PdfClient($client, $config))->render('<h1>x</h1>');
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Unit/PdfClientTest.php`
Expected: FAIL — `PdfClient` class not found.

- [ ] **Step 3: Create the client**

Create `web/modules/custom/accessguard/src/Service/PdfClient.php`:

```php
<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Sends report HTML to the scanner's /pdf endpoint and returns PDF bytes.
 */
class PdfClient {

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Renders HTML to a PDF via the scanner.
   *
   * @throws \RuntimeException
   *   On transport or non-2xx response.
   */
  public function render(string $html): string {
    $config = $this->configFactory->get('accessguard.settings');
    $endpoint = rtrim((string) $config->get('scanner_endpoint'), '/');
    $options = [
      'json' => ['html' => $html],
      'timeout' => 60,
    ];
    $token = (string) ($config->get('scanner_auth_token') ?? '');
    if ($token !== '') {
      $options['headers'] = ['X-Scanner-Token' => $token];
    }
    try {
      $response = $this->httpClient->request('POST', $endpoint . '/pdf', $options);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('AccessGuard PDF render failed: ' . $e->getMessage(), 0, $e);
    }
    return (string) $response->getBody();
  }

}
```

- [ ] **Step 4: Register the service**

Add to `web/modules/custom/accessguard/accessguard.services.yml`:

```yaml
  accessguard.pdf_client:
    class: Drupal\accessguard\Service\PdfClient
    arguments: ['@http_client', '@config.factory']
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Unit/PdfClientTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: phpcs and commit**

Run: `vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard/src/Service/PdfClient.php`
Expected: no errors. Then:

```bash
git add web/modules/custom/accessguard/src/Service/PdfClient.php \
  web/modules/custom/accessguard/accessguard.services.yml \
  web/modules/custom/accessguard/tests/src/Unit/PdfClientTest.php
git commit -m "feat(module): PdfClient posts report HTML to the scanner"
```

---

## Task 6: PDF export route + dashboard button + graceful failure

**Files:**
- Modify: `web/modules/custom/accessguard/src/Controller/DashboardController.php`
- Modify: `web/modules/custom/accessguard/accessguard.routing.yml`
- Test: `web/modules/custom/accessguard/tests/src/Kernel/PdfExportTest.php`

**Interfaces:**
- Consumes: `accessguard.report_html_builder` (`build()`), `accessguard.pdf_client` (`render()`).
- Produces: route `accessguard.audit_export_pdf`; `DashboardController::exportPdf()` returning either a `Response` (PDF, 200) or a `RedirectResponse` back to the dashboard with an error message on `PdfClient` failure.

- [ ] **Step 1: Write the failing test**

Create `web/modules/custom/accessguard/tests/src/Kernel/PdfExportTest.php`:

```php
<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Controller\DashboardController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Tests the PDF export route degrades gracefully when the scanner is down.
 *
 * @group accessguard
 */
class PdfExportTest extends KernelTestBase {

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
    $this->createUser([]);
  }

  /**
   * Tests a failing PdfClient yields a redirect and an error message.
   */
  public function testExportPdfRedirectsWhenScannerDown(): void {
    $failing = $this->createMock(\Drupal\accessguard\Service\PdfClient::class);
    $failing->method('render')->willThrowException(new \RuntimeException('connection refused'));
    $this->container->set('accessguard.pdf_client', $failing);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $response = DashboardController::create($this->container)->exportPdf();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $messages = \Drupal::messenger()->all();
    $this->assertNotEmpty($messages['error'] ?? []);
  }

  /**
   * Tests a working PdfClient yields a PDF response.
   */
  public function testExportPdfStreamsPdf(): void {
    $ok = $this->createMock(\Drupal\accessguard\Service\PdfClient::class);
    $ok->method('render')->willReturn('%PDF-1.4 body');
    $this->container->set('accessguard.pdf_client', $ok);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $response = DashboardController::create($this->container)->exportPdf();

    $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    $this->assertStringStartsWith('%PDF', $response->getContent());
    $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/PdfExportTest.php`
Expected: FAIL — `DashboardController::exportPdf()` does not exist.

- [ ] **Step 3: Add the controller dependencies and action**

In `web/modules/custom/accessguard/src/Controller/DashboardController.php`:

Add imports near the top:

```php
use Drupal\accessguard\Service\PdfClient;
use Drupal\accessguard\Service\ReportHtmlBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
```

Add two promoted constructor parameters (append to the existing constructor param list):

```php
    protected ReportHtmlBuilder $reportHtmlBuilder,
    protected PdfClient $pdfClient,
```

Add the matching arguments to `create()` (append to the existing `new static(...)` argument list, in the same order):

```php
      $container->get('accessguard.report_html_builder'),
      $container->get('accessguard.pdf_client'),
```

Add the action method (after `exportCsv()`):

```php
  /**
   * Streams a PDF audit report rendered by the scanner.
   *
   * The scanner must be running; on failure the user is returned to the
   * dashboard with an error and the CSV export remains available.
   */
  public function exportPdf() {
    $html = $this->reportHtmlBuilder->build();
    try {
      $pdf = $this->pdfClient->render($html);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('PDF export requires the scanner service to be running. CSV export is still available.'));
      return new RedirectResponse(Url::fromRoute('accessguard.dashboard')->toString());
    }
    $response = new Response($pdf);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'attachment; filename="accessguard-audit-' . date('Y-m-d') . '.pdf"');
    return $response;
  }
```

Add the PDF button in `overview()`, right after the existing `$build['export']` block:

```php
    $build['export_pdf'] = [
      '#type' => 'link',
      '#title' => $this->t('Export audit PDF'),
      '#url' => Url::fromRoute('accessguard.audit_export_pdf'),
      '#attributes' => ['class' => ['button']],
    ];
```

- [ ] **Step 4: Add the route**

Append to `web/modules/custom/accessguard/accessguard.routing.yml`:

```yaml
accessguard.audit_export_pdf:
  path: '/admin/reports/accessguard/export/pdf'
  defaults:
    _controller: 'Drupal\accessguard\Controller\DashboardController::exportPdf'
    _title: 'Export AccessGuard PDF'
  requirements:
    _permission: 'view accessguard reports'
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests/src/Kernel/PdfExportTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Run the full module suite, phpcs, and commit**

Run: `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests`
Expected: all pass (existing + new).

Run: `vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard`
Expected: no errors. Then:

```bash
git add web/modules/custom/accessguard/src/Controller/DashboardController.php \
  web/modules/custom/accessguard/accessguard.routing.yml \
  web/modules/custom/accessguard/tests/src/Kernel/PdfExportTest.php
git commit -m "feat(module): PDF audit export route with graceful scanner-down fallback"
```

---

## Task 7: Docs — README roadmap + testing update

**Files:**
- Modify: `README.md`

**Interfaces:** none (documentation only).

- [ ] **Step 1: Update the roadmap and feature list**

In `README.md`, change the Roadmap section (lines ~123-126) so the shipped item is removed and only the remaining item stays:

```markdown
## Roadmap

- Concurrency-limited browser pooling in the scanner (reuse instances under load).
```

Then, wherever features are described (near the top feature list), add a short bullet noting the new capability. Add this line to the features list:

```markdown
- Per-rule and per-author analytics tabs, plus a downloadable PDF audit report (rendered by the scanner) alongside the CSV export.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: note analytics tabs + PDF export, trim shipped roadmap item"
```

---

## Final verification

- [ ] **Run both full suites**

Run: `cd scanner && npm test` — expected: all green.
Run (from repo root): `vendor/bin/phpunit -c web/core web/modules/custom/accessguard/tests` — expected: all green.
Run: `vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/accessguard` — expected: no errors.

- [ ] **Optional manual smoke test (requires running DDEV + scanner)**

Visit `/admin/reports/accessguard` — three tabs (Overview / By rule / By author) render; both export buttons present. Click "Export audit PDF" with the scanner up → a PDF downloads. Stop the scanner and click again → redirected back with the error message, CSV still works.
