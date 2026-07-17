<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\Core\Form\FormState;
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

}
