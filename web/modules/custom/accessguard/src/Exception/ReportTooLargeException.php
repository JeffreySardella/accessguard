<?php

namespace Drupal\accessguard\Exception;

/**
 * Thrown when the audit report HTML exceeds the scanner's render limit.
 *
 * Distinct from a generic transport failure so callers can tell the user the
 * report is too large rather than blaming a down scanner.
 */
class ReportTooLargeException extends \RuntimeException {
}
