<?php

namespace Drupal\accessguard\Exception;

/**
 * Thrown when the scanner sheds a request because it is at capacity (HTTP 503).
 *
 * A 503 means "retry me later," not "this scan is broken," so the queue worker
 * must not count it against the item's bounded retry budget — otherwise a
 * transiently-saturated scanner permanently drops valid scans.
 */
class ScannerBusyException extends \RuntimeException {}
