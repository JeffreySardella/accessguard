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
