<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests RegressionService's diffing of a node's two latest scans.
 *
 * @group accessguard
 */
class RegressionServiceTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
  }

  /**
   * Creates a completed scan for a node with the given rule violations.
   */
  private function scanWithRules(int $nid, array $rules): void {
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $nid,
      'status' => 'complete',
    ]);
    $scan->save();
    // Force increasing created timestamps so ordering is deterministic.
    static $t = 1000;
    $scan->set('created', $t += 100)->save();
    foreach ($rules as $rule) {
      \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
        'scan_id' => $scan->id(),
        'rule_id' => $rule,
        'impact' => 'serious',
      ])->save();
    }
  }

  /**
   * Tests that diff() classifies rules as new, fixed, or persisting.
   */
  public function testDiffClassifiesNewFixedPersisting(): void {
    // Previous scan: image-alt + link-name. Latest: link-name + color-contrast.
    $this->scanWithRules(5, ['image-alt', 'link-name']);
    $this->scanWithRules(5, ['link-name', 'color-contrast']);

    $diff = \Drupal::service('accessguard.regression')->diff(5);

    $this->assertSame(['color-contrast'], $diff['new']);
    $this->assertSame(['image-alt'], $diff['fixed']);
    $this->assertSame(['link-name'], $diff['persisting']);
  }

}
