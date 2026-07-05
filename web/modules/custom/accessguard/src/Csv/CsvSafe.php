<?php

namespace Drupal\accessguard\Csv;

/**
 * Neutralizes CSV formula injection.
 */
class CsvSafe {

  /**
   * Prefixes a cell that starts with a formula trigger.
   *
   * This makes spreadsheet apps read it as text, not a formula.
   */
  public static function cell(mixed $value): mixed {
    if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], TRUE)) {
      return "'" . $value;
    }
    return $value;
  }

}
