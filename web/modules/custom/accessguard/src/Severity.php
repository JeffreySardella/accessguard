<?php

namespace Drupal\accessguard;

/**
 * The single source of truth for AccessGuard's impact-severity taxonomy.
 *
 * The axe engine reports an impact of critical/serious/moderate/minor, or
 * null; the normalized value for a null/unrecognized impact is 'unknown'.
 * This class
 * owns that set, the normalization, and the rank order the publish gate uses —
 * previously these were re-encoded independently in ScanRecorder, the gate
 * validator, and ViolationAnalytics, which is exactly how the "unknown-impact
 * invisible to counts" drift arose (a policy change in one place didn't move
 * the others). Everyone consults this now, so divergence is impossible.
 */
final class Severity {

  /**
   * Value stored for a null/unrecognized axe impact.
   */
  public const UNKNOWN = 'unknown';

  /**
   * The known axe impact levels, strongest first.
   */
  public const LEVELS = ['critical', 'serious', 'moderate', 'minor'];

  /**
   * Rank per severity. Higher blocks at a lower-or-equal gate threshold.
   *
   * 'unknown' ranks alongside 'moderate' so an unrankable violation is still
   * gateable (at a moderate/minor threshold) rather than invisibly passing
   * every gate — an unknown impact must not be weaker than the weakest known.
   */
  private const RANKS = [
    'minor' => 1,
    'moderate' => 2,
    self::UNKNOWN => 2,
    'serious' => 3,
    'critical' => 4,
  ];

  /**
   * Normalizes a raw axe impact to a known level or UNKNOWN.
   */
  public static function normalize(?string $impact): string {
    return in_array($impact, self::LEVELS, TRUE) ? $impact : self::UNKNOWN;
  }

  /**
   * Whether an impact is one of the known (counted) levels.
   */
  public static function isKnown(?string $impact): bool {
    return in_array($impact, self::LEVELS, TRUE);
  }

  /**
   * The rank of an impact (0 for anything with no defined rank).
   */
  public static function rank(?string $impact): int {
    return self::RANKS[$impact] ?? 0;
  }

  /**
   * A zeroed per-level count map (critical/serious/moderate/minor => 0).
   */
  public static function zeroCounts(): array {
    return array_fill_keys(self::LEVELS, 0);
  }

}
