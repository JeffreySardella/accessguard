<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Service\WaiverMatcher;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that WaiverMatcher records and looks up waivers by fingerprint.
 *
 * @group accessguard
 */
class WaiverTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
  }

  /**
   * Tests that createWaiver() records a waiver findable by fingerprint.
   */
  public function testCreateWaiverAndMatch(): void {
    $matcher = \Drupal::service('accessguard.waiver_matcher');
    $matcher->createWaiver(7, 'image-alt', 'img', 'false_positive', 'decorative', 1);

    $map = $matcher->waivedFingerprints(7);
    $fp = WaiverMatcher::fingerprint('image-alt', 'img');
    $this->assertSame('false_positive', $map[$fp] ?? NULL);
    // A different node is unaffected.
    $this->assertSame([], $matcher->waivedFingerprints(99));
  }

}
