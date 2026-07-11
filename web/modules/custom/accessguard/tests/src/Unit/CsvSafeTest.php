<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Csv\CsvSafe;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CsvSafe's formula-injection neutralization.
 */
#[Group('accessguard')]
class CsvSafeTest extends UnitTestCase {

  /**
   * Tests that cells starting with a formula trigger are quoted.
   */
  public function testFormulaTriggersAreQuoted(): void {
    foreach (['=SUM(A1)', '+1', '-1', '@cmd', "\tx", "\rx"] as $dangerous) {
      $this->assertSame("'" . $dangerous, CsvSafe::cell($dangerous));
    }
  }

  /**
   * Tests that normal values pass through unchanged.
   */
  public function testNormalValuesPassThrough(): void {
    $this->assertSame('image-alt', CsvSafe::cell('image-alt'));
    $this->assertSame('', CsvSafe::cell(''));
    $this->assertSame(42, CsvSafe::cell(42));
    $this->assertSame('a=b', CsvSafe::cell('a=b'));
  }

}
