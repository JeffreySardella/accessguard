<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Severity;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Severity taxonomy value object.
 */
#[Group('accessguard')]
class SeverityTest extends UnitTestCase {

  /**
   * Tests normalization of raw axe impacts.
   */
  public function testNormalize(): void {
    foreach (['critical', 'serious', 'moderate', 'minor'] as $level) {
      $this->assertSame($level, Severity::normalize($level));
    }
    $this->assertSame('unknown', Severity::normalize(NULL));
    $this->assertSame('unknown', Severity::normalize(''));
    $this->assertSame('unknown', Severity::normalize('bogus'));
    $this->assertSame('unknown', Severity::normalize('Critical'));
  }

  /**
   * Tests the rank order the publish gate depends on.
   */
  public function testRank(): void {
    $this->assertSame(4, Severity::rank('critical'));
    $this->assertSame(3, Severity::rank('serious'));
    $this->assertSame(2, Severity::rank('moderate'));
    $this->assertSame(1, Severity::rank('minor'));
    // Unknown ranks with moderate so it stays gateable, never below minor.
    $this->assertSame(2, Severity::rank('unknown'));
    $this->assertSame(0, Severity::rank('bogus'));
    $this->assertSame(0, Severity::rank(NULL));
    $this->assertGreaterThan(Severity::rank('minor'), Severity::rank('unknown'));
  }

  /**
   * Tests isKnown and zeroCounts helpers.
   */
  public function testHelpers(): void {
    $this->assertTrue(Severity::isKnown('critical'));
    $this->assertFalse(Severity::isKnown('unknown'));
    $this->assertFalse(Severity::isKnown(NULL));
    $this->assertSame(
      ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0],
      Severity::zeroCounts()
    );
  }

}
