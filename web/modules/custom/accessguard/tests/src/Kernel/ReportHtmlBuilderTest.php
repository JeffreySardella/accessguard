<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ReportHtmlBuilder produces a self-contained audit document.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
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
    // Accessibility of the report itself (WCAG 3.1.1 / 2.4.2 / 1.3.1).
    $this->assertStringContainsString('<html lang="en">', $html);
    $this->assertStringContainsString('<title>Accessibility audit report', $html);
    $this->assertStringContainsString('<th scope="col">Rule</th>', $html);
    // No unscoped header cells remain.
    $this->assertStringNotContainsString('<th>', $html);
    // The honest automated-coverage disclaimer is present so the report can't
    // be mistaken for a conformance certification.
    $this->assertStringContainsString('Automated testing only', $html);
    $this->assertStringContainsString('not a conformance certification', $html);
  }

  /**
   * Tests the summary reports waived and unknown-severity counts.
   *
   * The open total must be explainable from the report itself: waived items
   * get their own line and non-standard impacts land in an Unknown bucket
   * instead of making the total exceed the sum of the severity counts.
   */
  public function testSummaryShowsWaivedAndUnknownCounts(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Homepage', 'status' => 1]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
    ]);
    $scan->save();
    $violationStorage = \Drupal::entityTypeManager()->getStorage('accessguard_violation');
    $violationStorage->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'image-alt',
      'impact' => 'critical',
      'selector' => 'img',
    ])->save();
    $violationStorage->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'custom-rule',
      'impact' => 'unknown',
      'selector' => 'div',
    ])->save();
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver((int) $node->id(), 'image-alt', 'img', 'false_positive', 'decorative', 1);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $html = \Drupal::service('accessguard.report_html_builder')->build();

    $this->assertStringContainsString('Open violations (latest scans): 1', $html);
    $this->assertStringContainsString('Waived: 1', $html);
    $this->assertStringContainsString('Unknown severity: 1', $html);
  }

  /**
   * Tests findings exclude nodes the current user cannot view.
   *
   * The findings section iterates the shared analytics context, which is
   * node-access filtered; this pins that behavior so a refactor to a
   * non-filtered data source cannot silently leak restricted content.
   */
  public function testFindingsExcludeInaccessibleNodes(): void {
    $public = Node::create(['type' => 'page', 'title' => 'Public page', 'status' => 1]);
    $public->save();
    $secret = Node::create(['type' => 'page', 'title' => 'Secret page', 'status' => 0]);
    $secret->save();
    $scanStorage = \Drupal::entityTypeManager()->getStorage('accessguard_scan');
    foreach ([$public, $secret] as $node) {
      $scan = $scanStorage->create([
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
    }

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $html = \Drupal::service('accessguard.report_html_builder')->build();

    $this->assertStringContainsString('Public page', $html);
    $this->assertStringNotContainsString('Secret page', $html);
    // The inaccessible node's scan must not inflate the summary either.
    $this->assertStringContainsString('Pages scanned: 1', $html);
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
