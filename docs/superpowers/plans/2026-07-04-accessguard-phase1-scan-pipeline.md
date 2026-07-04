# AccessGuard Phase 1: Scan Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the end-to-end accessibility scan pipeline: trigger a scan of a Drupal node, run it through a headless axe-core service, and store the results as Drupal entities.

**Architecture:** A minimal Node microservice (Puppeteer + axe-core) exposes `POST /scan { url }` and returns JSON violations. A Drupal 11 custom module `accessguard` calls that service via a `ScanRunner` service, and a `ScanRecorder` service turns the JSON into one `accessguard_scan` entity plus child `accessguard_violation` entities. A queue worker and a manual drush command drive the pipeline.

**Tech Stack:** Drupal 11.3 (PHP 8.3), Symfony HTTP client, Drupal Entity API + Queue API, Node.js 20 + Express + Puppeteer + axe-core, PHPUnit (Drupal Kernel tests), Jest (Node tests).

## Global Constraints

- Drupal core: **^11.3**, PHP **8.3+**. Use modern Entity API (attribute-based or annotation content entities), typed properties.
- Module machine name: **`accessguard`**. Namespace: `Drupal\accessguard`.
- The Node scanner is a **separate service**, not PHP. It lives in `scanner/` at repo root and must run in the same Docker network as Drupal so Drupal can reach it at `http://accessguard-scanner:3000` (dev default; overridable by config).
- axe-core ruleset: run tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`.
- Severity vocabulary (axe `impact`): `critical`, `serious`, `moderate`, `minor`. Store exactly these strings.
- No paid services, no API keys. Everything free and open source.
- TDD: write the failing test first for every unit that has testable logic. Commit after each task.

---

## File Structure

```
scanner/                              # Node microservice (own package)
  package.json
  src/server.js                       # Express app: POST /scan
  src/scan.js                         # runScan(url) -> { url, violations[] }
  test/scan.test.js                   # Jest tests against a fixture HTML
  test/fixtures/missing-alt.html      # known-bad page for tests
  Dockerfile

web/modules/custom/accessguard/       # Drupal module (developed inside drupal-practice)
  accessguard.info.yml
  accessguard.services.yml
  accessguard.install                 # entity schema is automatic; kept for future
  src/Entity/AccessguardScan.php
  src/Entity/AccessguardViolation.php
  src/Service/ScanRunner.php          # HTTP call to the Node scanner
  src/Service/ScanRecorder.php        # JSON -> scan + violation entities
  src/Plugin/QueueWorker/AccessguardScanWorker.php
  src/Commands/AccessguardCommands.php # drush: accessguard:scan <nid>
  drush.services.yml
  tests/src/Kernel/ScanRecorderTest.php
  tests/src/Unit/ScanRunnerTest.php
```

The module is developed inside the existing `drupal-practice` Drupal install at `web/modules/custom/accessguard`, but its files are the AccessGuard repo. During implementation, symlink or copy the module into `drupal-practice/web/modules/custom/` so it can be enabled and tested.

---

## Task 1: Node scanner — runScan core

**Files:**
- Create: `scanner/package.json`
- Create: `scanner/src/scan.js`
- Create: `scanner/test/scan.test.js`
- Create: `scanner/test/fixtures/missing-alt.html`

**Interfaces:**
- Produces: `runScan(url: string): Promise<{ url: string, violations: Violation[] }>` where `Violation = { ruleId: string, impact: string, wcagCriterion: string|null, selector: string, html: string, helpUrl: string }`.

- [ ] **Step 1: Create the package manifest**

`scanner/package.json`:
```json
{
  "name": "accessguard-scanner",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "scripts": {
    "start": "node src/server.js",
    "test": "node --experimental-vm-modules node_modules/.bin/jest"
  },
  "dependencies": {
    "axe-core": "^4.10.0",
    "express": "^4.21.0",
    "puppeteer": "^23.0.0"
  },
  "devDependencies": {
    "jest": "^29.7.0"
  }
}
```

Run: `cd scanner && npm install`

- [ ] **Step 2: Create the known-bad fixture**

`scanner/test/fixtures/missing-alt.html`:
```html
<!doctype html>
<html lang="en">
<head><title>Missing Alt</title></head>
<body>
  <h1>Test</h1>
  <img src="cat.jpg">
</body>
</html>
```

- [ ] **Step 3: Write the failing test**

`scanner/test/scan.test.js`:
```js
import { runScan } from '../src/scan.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const fixture = 'file://' + path.join(dir, 'fixtures', 'missing-alt.html');

test('flags an image with no alt text', async () => {
  const result = await runScan(fixture);
  const rules = result.violations.map(v => v.ruleId);
  expect(rules).toContain('image-alt');
}, 30000);
```

- [ ] **Step 4: Run the test to confirm it fails**

Run: `cd scanner && npm test`
Expected: FAIL — `runScan` is not defined / module not found.

- [ ] **Step 5: Implement runScan**

`scanner/src/scan.js`:
```js
import puppeteer from 'puppeteer';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const axePath = require.resolve('axe-core');
const axeSource = readFileSync(axePath, 'utf8');

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

export async function runScan(url) {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
    await page.evaluate(axeSource);
    const raw = await page.evaluate(async (tags) => {
      const results = await window.axe.run(document, { runOnly: { type: 'tag', values: tags } });
      return results.violations;
    }, TAGS);

    const violations = [];
    for (const v of raw) {
      const wcag = (v.tags.find(t => /^wcag\d/.test(t)) || null);
      for (const node of v.nodes) {
        violations.push({
          ruleId: v.id,
          impact: v.impact,
          wcagCriterion: wcag,
          selector: Array.isArray(node.target) ? node.target.join(' ') : String(node.target),
          html: node.html,
          helpUrl: v.helpUrl,
        });
      }
    }
    return { url, violations };
  } finally {
    await browser.close();
  }
}
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `cd scanner && npm test`
Expected: PASS — `image-alt` is in the returned rule ids.

- [ ] **Step 7: Commit**

```bash
git add scanner/package.json scanner/src/scan.js scanner/test/
git commit -m "feat(scanner): runScan detects axe violations on a URL"
```

---

## Task 2: Node scanner — HTTP server

**Files:**
- Create: `scanner/src/server.js`
- Create: `scanner/Dockerfile`
- Modify: `scanner/test/scan.test.js` (add a server route test)

**Interfaces:**
- Consumes: `runScan` from Task 1.
- Produces: `POST /scan` with JSON body `{ "url": "..." }` returning `200 { url, violations }`, or `400 { error }` when `url` is missing.

- [ ] **Step 1: Write the failing test for the route**

Append to `scanner/test/scan.test.js`:
```js
import { app } from '../src/server.js';
import http from 'node:http';

test('POST /scan returns 400 when url missing', async () => {
  const server = http.createServer(app).listen(0);
  const port = server.address().port;
  const res = await fetch(`http://127.0.0.1:${port}/scan`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({}),
  });
  server.close();
  expect(res.status).toBe(400);
});
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `cd scanner && npm test`
Expected: FAIL — cannot import `app` from `../src/server.js`.

- [ ] **Step 3: Implement the server**

`scanner/src/server.js`:
```js
import express from 'express';
import { runScan } from './scan.js';

export const app = express();
app.use(express.json({ limit: '1mb' }));

app.get('/health', (req, res) => res.json({ ok: true }));

app.post('/scan', async (req, res) => {
  const { url } = req.body || {};
  if (!url || typeof url !== 'string') {
    return res.status(400).json({ error: 'Missing required "url" string.' });
  }
  try {
    const result = await runScan(url);
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: String(err && err.message ? err.message : err) });
  }
});

const PORT = process.env.PORT || 3000;
if (process.env.NODE_ENV !== 'test') {
  app.listen(PORT, () => console.log(`accessguard-scanner listening on ${PORT}`));
}
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `cd scanner && npm test`
Expected: PASS — both the runScan test and the 400 route test.

- [ ] **Step 5: Add the Dockerfile**

`scanner/Dockerfile`:
```dockerfile
FROM ghcr.io/puppeteer/puppeteer:23.0.0
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
COPY src ./src
ENV NODE_ENV=production
EXPOSE 3000
CMD ["node", "src/server.js"]
```

- [ ] **Step 6: Commit**

```bash
git add scanner/src/server.js scanner/Dockerfile scanner/test/scan.test.js
git commit -m "feat(scanner): add POST /scan HTTP endpoint and Dockerfile"
```

---

## Task 3: Drupal module skeleton and config

**Files:**
- Create: `web/modules/custom/accessguard/accessguard.info.yml`
- Create: `web/modules/custom/accessguard/config/install/accessguard.settings.yml`
- Create: `web/modules/custom/accessguard/config/schema/accessguard.schema.yml`

**Interfaces:**
- Produces: config object `accessguard.settings` with key `scanner_endpoint` (string, default `http://accessguard-scanner:3000`).

- [ ] **Step 1: Create the module info file**

`accessguard.info.yml`:
```yaml
name: AccessGuard
type: module
description: 'Section 508 / WCAG compliance governance built on axe-core.'
package: Accessibility
core_version_requirement: ^11
```

- [ ] **Step 2: Create default config and its schema**

`config/install/accessguard.settings.yml`:
```yaml
scanner_endpoint: 'http://accessguard-scanner:3000'
```

`config/schema/accessguard.schema.yml`:
```yaml
accessguard.settings:
  type: config_object
  label: 'AccessGuard settings'
  mapping:
    scanner_endpoint:
      type: string
      label: 'Scanner service endpoint'
```

- [ ] **Step 3: Enable the module to verify it installs**

Run: `cd drupal-practice && ddev drush en accessguard -y && ddev drush config:get accessguard.settings`
Expected: module enables; config shows `scanner_endpoint: 'http://accessguard-scanner:3000'`.

- [ ] **Step 4: Commit**

```bash
git add web/modules/custom/accessguard/accessguard.info.yml web/modules/custom/accessguard/config
git commit -m "feat(module): accessguard skeleton with scanner_endpoint config"
```

---

## Task 4: The two content entities

**Files:**
- Create: `web/modules/custom/accessguard/src/Entity/AccessguardScan.php`
- Create: `web/modules/custom/accessguard/src/Entity/AccessguardViolation.php`

**Interfaces:**
- Produces: content entity `accessguard_scan` with base fields `target_entity_type`, `target_entity_id`, `url`, `triggered_by`, `content_author`, `status`, `count_critical`, `count_serious`, `count_moderate`, `count_minor`, `created`. Content entity `accessguard_violation` with base fields `scan_id` (entity_reference to accessguard_scan), `rule_id`, `impact`, `wcag_criterion`, `selector`, `html_snippet`, `help_url`.

- [ ] **Step 1: Generate the scan entity scaffold**

Run: `cd drupal-practice && ddev drush generate entity:content`
Answer prompts: module `accessguard`, entity label `Accessguard Scan`, entity id `accessguard_scan`, no bundles, no revisions, not translatable. This writes a base `AccessguardScan.php`. (Generation is standard Drupal practice and produces real, working code; the next step replaces its base-field body with the exact fields below.)

- [ ] **Step 2: Replace the scan entity base fields**

Set the `baseFieldDefinitions()` of `src/Entity/AccessguardScan.php` to exactly:
```php
public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
  $fields = parent::baseFieldDefinitions($entity_type);

  $fields['target_entity_type'] = BaseFieldDefinition::create('string')
    ->setLabel(t('Target entity type'))->setRequired(TRUE);
  $fields['target_entity_id'] = BaseFieldDefinition::create('integer')
    ->setLabel(t('Target entity ID'))->setRequired(TRUE);
  $fields['url'] = BaseFieldDefinition::create('string')
    ->setLabel(t('Scanned URL'))->setSetting('max_length', 2048);
  $fields['triggered_by'] = BaseFieldDefinition::create('string')
    ->setLabel(t('Triggered by'));
  $fields['content_author'] = BaseFieldDefinition::create('entity_reference')
    ->setLabel(t('Content author'))->setSetting('target_type', 'user');
  $fields['status'] = BaseFieldDefinition::create('string')
    ->setLabel(t('Status'))->setDefaultValue('queued');
  foreach (['critical', 'serious', 'moderate', 'minor'] as $sev) {
    $fields['count_' . $sev] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Count @sev', ['@sev' => $sev]))->setDefaultValue(0);
  }
  $fields['created'] = BaseFieldDefinition::create('created')
    ->setLabel(t('Created'));

  return $fields;
}
```
Ensure the file imports `Drupal\Core\Entity\EntityTypeInterface`, `Drupal\Core\Field\BaseFieldDefinition`.

- [ ] **Step 3: Generate and set the violation entity**

Run `ddev drush generate entity:content` again: entity id `accessguard_violation`, label `Accessguard Violation`. Then set its `baseFieldDefinitions()` to exactly:
```php
public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
  $fields = parent::baseFieldDefinitions($entity_type);
  $fields['scan_id'] = BaseFieldDefinition::create('entity_reference')
    ->setLabel(t('Scan'))->setSetting('target_type', 'accessguard_scan')->setRequired(TRUE);
  $fields['rule_id'] = BaseFieldDefinition::create('string')->setLabel(t('Rule ID'));
  $fields['impact'] = BaseFieldDefinition::create('string')->setLabel(t('Impact'));
  $fields['wcag_criterion'] = BaseFieldDefinition::create('string')->setLabel(t('WCAG criterion'));
  $fields['selector'] = BaseFieldDefinition::create('string')->setLabel(t('Selector'))->setSetting('max_length', 2048);
  $fields['html_snippet'] = BaseFieldDefinition::create('string_long')->setLabel(t('HTML snippet'));
  $fields['help_url'] = BaseFieldDefinition::create('string')->setLabel(t('Help URL'))->setSetting('max_length', 2048);
  return $fields;
}
```

- [ ] **Step 4: Install the entity schemas**

Run: `cd drupal-practice && ddev drush entity:updates -y` (or `ddev drush cr` then confirm via `ddev drush php:eval "\Drupal::entityTypeManager()->getStorage('accessguard_scan');"`).
Expected: no errors; both entity types are registered.

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/accessguard/src/Entity
git commit -m "feat(module): add accessguard_scan and accessguard_violation entities"
```

---

## Task 5: ScanRunner service (calls the Node scanner)

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/ScanRunner.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml`
- Create: `web/modules/custom/accessguard/tests/src/Unit/ScanRunnerTest.php`

**Interfaces:**
- Consumes: config `accessguard.settings:scanner_endpoint`, Symfony `http_client`.
- Produces: `ScanRunner::scan(string $url): array` returning the decoded `{ url, violations[] }` array from the scanner. Throws `\RuntimeException` on transport failure.

- [ ] **Step 1: Write the failing unit test**

`tests/src/Unit/ScanRunnerTest.php`:
```php
namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Service\ScanRunner;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ScanRunnerTest extends UnitTestCase {
  public function testScanReturnsDecodedViolations(): void {
    $payload = json_encode(['url' => 'http://x/node/1', 'violations' => [
      ['ruleId' => 'image-alt', 'impact' => 'critical', 'wcagCriterion' => 'wcag2a',
       'selector' => 'img', 'html' => '<img>', 'helpUrl' => 'http://help'],
    ]]);
    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000'],
    ]);
    $runner = new ScanRunner($client, $config);
    $result = $runner->scan('http://x/node/1');
    $this->assertSame('image-alt', $result['violations'][0]['ruleId']);
  }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `cd drupal-practice && ddev exec vendor/bin/phpunit web/modules/custom/accessguard/tests/src/Unit/ScanRunnerTest.php`
Expected: FAIL — class `Drupal\accessguard\Service\ScanRunner` not found.

- [ ] **Step 3: Implement ScanRunner**

`src/Service/ScanRunner.php`:
```php
namespace Drupal\accessguard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ScanRunner {
  public function __construct(
    protected HttpClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  public function scan(string $url): array {
    $endpoint = rtrim($this->configFactory->get('accessguard.settings')->get('scanner_endpoint'), '/');
    try {
      $response = $this->httpClient->request('POST', $endpoint . '/scan', [
        'json' => ['url' => $url],
        'timeout' => 60,
      ]);
      return $response->toArray();
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('AccessGuard scan failed: ' . $e->getMessage(), 0, $e);
    }
  }
}
```

- [ ] **Step 4: Register the service**

`accessguard.services.yml`:
```yaml
services:
  accessguard.scan_runner:
    class: Drupal\accessguard\Service\ScanRunner
    arguments: ['@http_client', '@config.factory']
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `cd drupal-practice && ddev exec vendor/bin/phpunit web/modules/custom/accessguard/tests/src/Unit/ScanRunnerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/src/Service/ScanRunner.php web/modules/custom/accessguard/accessguard.services.yml web/modules/custom/accessguard/tests/src/Unit/ScanRunnerTest.php
git commit -m "feat(module): ScanRunner calls the Node scanner over HTTP"
```

---

## Task 6: ScanRecorder service (JSON to entities)

**Files:**
- Create: `web/modules/custom/accessguard/src/Service/ScanRecorder.php`
- Modify: `web/modules/custom/accessguard/accessguard.services.yml`
- Create: `web/modules/custom/accessguard/tests/src/Kernel/ScanRecorderTest.php`

**Interfaces:**
- Consumes: entity_type.manager; the `{ url, violations[] }` array shape from ScanRunner.
- Produces: `ScanRecorder::record(string $entityType, int $entityId, ?int $authorUid, string $triggeredBy, array $scanResult): AccessguardScan`. Creates one saved `accessguard_scan` (status `complete`, severity counts filled) and one saved `accessguard_violation` per entry.

- [ ] **Step 1: Write the failing Kernel test**

`tests/src/Kernel/ScanRecorderTest.php`:
```php
namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;

class ScanRecorderTest extends KernelTestBase {
  protected static $modules = ['accessguard', 'user', 'system'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('accessguard_scan');
    $this->installEntitySchema('accessguard_violation');
    $this->installEntitySchema('user');
  }

  public function testRecordCreatesScanAndViolations(): void {
    $result = ['url' => 'http://x/node/5', 'violations' => [
      ['ruleId' => 'image-alt', 'impact' => 'critical', 'wcagCriterion' => 'wcag2a',
       'selector' => 'img', 'html' => '<img>', 'helpUrl' => 'http://h'],
      ['ruleId' => 'link-name', 'impact' => 'serious', 'wcagCriterion' => 'wcag2a',
       'selector' => 'a', 'html' => '<a>', 'helpUrl' => 'http://h'],
    ]];
    $recorder = $this->container->get('accessguard.scan_recorder');
    $scan = $recorder->record('node', 5, NULL, 'manual', $result);

    $this->assertSame('complete', $scan->get('status')->value);
    $this->assertSame(1, (int) $scan->get('count_critical')->value);
    $this->assertSame(1, (int) $scan->get('count_serious')->value);
    $violations = \Drupal::entityTypeManager()->getStorage('accessguard_violation')
      ->loadByProperties(['scan_id' => $scan->id()]);
    $this->assertCount(2, $violations);
  }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `cd drupal-practice && ddev exec vendor/bin/phpunit web/modules/custom/accessguard/tests/src/Kernel/ScanRecorderTest.php`
Expected: FAIL — service `accessguard.scan_recorder` not found.

- [ ] **Step 3: Implement ScanRecorder**

`src/Service/ScanRecorder.php`:
```php
namespace Drupal\accessguard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

class ScanRecorder {
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public function record(string $entityType, int $entityId, ?int $authorUid, string $triggeredBy, array $scanResult) {
    $violations = $scanResult['violations'] ?? [];
    $counts = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
    foreach ($violations as $v) {
      $impact = $v['impact'] ?? 'minor';
      if (isset($counts[$impact])) {
        $counts[$impact]++;
      }
    }

    $scanStorage = $this->entityTypeManager->getStorage('accessguard_scan');
    $scan = $scanStorage->create([
      'target_entity_type' => $entityType,
      'target_entity_id' => $entityId,
      'url' => $scanResult['url'] ?? '',
      'triggered_by' => $triggeredBy,
      'content_author' => $authorUid,
      'status' => 'complete',
      'count_critical' => $counts['critical'],
      'count_serious' => $counts['serious'],
      'count_moderate' => $counts['moderate'],
      'count_minor' => $counts['minor'],
    ]);
    $scan->save();

    $vStorage = $this->entityTypeManager->getStorage('accessguard_violation');
    foreach ($violations as $v) {
      $vStorage->create([
        'scan_id' => $scan->id(),
        'rule_id' => $v['ruleId'] ?? '',
        'impact' => $v['impact'] ?? '',
        'wcag_criterion' => $v['wcagCriterion'] ?? '',
        'selector' => $v['selector'] ?? '',
        'html_snippet' => $v['html'] ?? '',
        'help_url' => $v['helpUrl'] ?? '',
      ])->save();
    }
    return $scan;
  }
}
```

- [ ] **Step 4: Register the service**

Append to `accessguard.services.yml`:
```yaml
  accessguard.scan_recorder:
    class: Drupal\accessguard\Service\ScanRecorder
    arguments: ['@entity_type.manager']
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `cd drupal-practice && ddev exec vendor/bin/phpunit web/modules/custom/accessguard/tests/src/Kernel/ScanRecorderTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add web/modules/custom/accessguard/src/Service/ScanRecorder.php web/modules/custom/accessguard/accessguard.services.yml web/modules/custom/accessguard/tests/src/Kernel/ScanRecorderTest.php
git commit -m "feat(module): ScanRecorder persists scan results as entities"
```

---

## Task 7: Queue worker + drush command (wire it end to end)

**Files:**
- Create: `web/modules/custom/accessguard/src/Plugin/QueueWorker/AccessguardScanWorker.php`
- Create: `web/modules/custom/accessguard/src/Commands/AccessguardCommands.php`
- Create: `web/modules/custom/accessguard/drush.services.yml`

**Interfaces:**
- Consumes: `accessguard.scan_runner`, `accessguard.scan_recorder`, `entity_type.manager`, the `node` entity.
- Produces: queue `accessguard_scan_queue` whose items are `['nid' => int]`; the worker resolves the node's URL and author, runs the scan, and records it. Drush command `accessguard:scan {nid}` enqueues (or runs immediately with `--now`).

- [ ] **Step 1: Implement the queue worker**

`src/Plugin/QueueWorker/AccessguardScanWorker.php`:
```php
namespace Drupal\accessguard\Plugin\QueueWorker;

use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @QueueWorker(
 *   id = "accessguard_scan_queue",
 *   title = @Translation("AccessGuard scan queue"),
 *   cron = {"time" = 60}
 * )
 */
class AccessguardScanWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(
    array $configuration, $plugin_id, $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRunner $scanRunner,
    protected ScanRecorder $scanRecorder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $c, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $c->get('entity_type.manager'), $c->get('accessguard.scan_runner'), $c->get('accessguard.scan_recorder'));
  }

  public function processItem($data): void {
    $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
    if (!$node) {
      return;
    }
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $author = (int) $node->getOwnerId();
    $result = $this->scanRunner->scan($url);
    $this->scanRecorder->record('node', (int) $node->id(), $author, 'cron', $result);
  }
}
```

- [ ] **Step 2: Implement the drush command**

`src/Commands/AccessguardCommands.php`:
```php
namespace Drupal\accessguard\Commands;

use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Commands\DrushCommands;

class AccessguardCommands extends DrushCommands {
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected ScanRunner $scanRunner,
    protected ScanRecorder $scanRecorder,
  ) {
    parent::__construct();
  }

  /**
   * Scan a node for accessibility violations.
   *
   * @command accessguard:scan
   * @param int $nid The node ID to scan.
   * @option now Run immediately instead of queueing.
   */
  public function scan(int $nid, array $options = ['now' => FALSE]): void {
    if (empty($options['now'])) {
      $this->queueFactory->get('accessguard_scan_queue')->createItem(['nid' => $nid]);
      $this->logger()->success("Queued scan for node $nid.");
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      throw new \InvalidArgumentException("Node $nid not found.");
    }
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $result = $this->scanRunner->scan($url);
    $scan = $this->scanRecorder->record('node', (int) $nid, (int) $node->getOwnerId(), 'manual', $result);
    $this->logger()->success("Scan {$scan->id()}: " .
      $scan->get('count_critical')->value . " critical, " .
      $scan->get('count_serious')->value . " serious.");
  }
}
```

- [ ] **Step 3: Register the drush command**

`drush.services.yml`:
```yaml
services:
  accessguard.commands:
    class: Drupal\accessguard\Commands\AccessguardCommands
    arguments: ['@entity_type.manager', '@queue', '@accessguard.scan_runner', '@accessguard.scan_recorder']
    tags:
      - { name: drush.command }
```

- [ ] **Step 4: Manual end-to-end verification**

Start the scanner and run a real scan against a real node:
```bash
# build & run the scanner container on the ddev network (see scanner/Dockerfile)
cd scanner && docker build -t accessguard-scanner . && \
  docker run -d --name accessguard-scanner --network ddev_default -p 3000:3000 accessguard-scanner
# create a node with a bad image, then:
cd ../drupal-practice && ddev drush accessguard:scan 1 --now
```
Expected: command prints a scan id with non-zero critical/serious counts; `ddev drush php:eval "print count(\Drupal::entityTypeManager()->getStorage('accessguard_violation')->loadMultiple());"` shows violation rows.

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/accessguard/src/Plugin web/modules/custom/accessguard/src/Commands web/modules/custom/accessguard/drush.services.yml
git commit -m "feat(module): queue worker and drush command drive the scan pipeline"
```

---

## Phase 1 Done Criteria

- `cd scanner && npm test` passes.
- `ddev exec vendor/bin/phpunit web/modules/custom/accessguard/tests` passes.
- `ddev drush accessguard:scan <nid> --now` against a deliberately broken node creates one `accessguard_scan` and matching `accessguard_violation` rows with correct severity counts.

Phases 2 to 5 (dashboards, publish-gating, cron site-wide + regression + attribution + audit export, then fixtures/benchmark/README) get their own plans.
