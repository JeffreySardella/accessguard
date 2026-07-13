<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Controller\AnalyticsController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the analytics tab controller renders rule/author tables.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
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
   * Tests the by-author table surfaces unknown-severity counts.
   *
   * A violation whose impact falls outside the four ranked severities must
   * appear in an Unknown column rather than silently vanishing from the row.
   */
  public function testByAuthorShowsUnknownSeverityColumn(): void {
    $author = $this->createUser(['access content']);
    $node = Node::create(['type' => 'page', 'title' => 'A', 'status' => 1]);
    $node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $node->id(),
      'status' => 'complete',
      'content_author' => $author->id(),
    ]);
    $scan->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'custom-rule',
      'impact' => 'unknown',
      'selector' => 'div',
    ])->save();

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $build = AnalyticsController::create($this->container)->byAuthor();

    $headers = array_map('strval', $build['table']['#header']);
    $unknownIndex = array_search('Unknown', $headers, TRUE);
    $this->assertNotFalse($unknownIndex, 'The by-author table has an Unknown column.');
    $this->assertSame(1, $build['table']['#rows'][0][$unknownIndex]);
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
