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
