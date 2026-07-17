<?php

namespace Drupal\accessguard\Controller;

use Drupal\accessguard\Service\TrendBuilder;
use Drupal\accessguard\Service\ViolationAnalytics;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the per-rule, per-author, and trends analytics tabs.
 */
class AnalyticsController extends ControllerBase {

  public function __construct(
    protected ViolationAnalytics $analytics,
    protected TrendBuilder $trendBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('accessguard.violation_analytics'),
      $container->get('accessguard.trend_builder'),
    );
  }

  /**
   * Violations grouped by rule, worst first.
   */
  public function byRule(): array {
    $rows = [];
    foreach ($this->analytics->byRule() as $r) {
      $rows[] = [
        $r['rule_id'],
        $r['impact'],
        $r['wcag'],
        $r['pages'],
        $r['open'],
        $r['waived'],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#caption' => $this->t('Violations by rule'),
        '#header' => [
          $this->t('Rule'),
          $this->t('Impact'),
          $this->t('WCAG'),
          $this->t('Pages affected'),
          $this->t('Open'),
          $this->t('Waived'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No violations found in the latest scans.'),
      ],
      '#cache' => [
        // Any scan/violation/waiver write invalidates the list tags; the
        // aggregation is node-access filtered, hence the grants context.
        'tags' => ['accessguard_scan_list', 'accessguard_violation_list', 'accessguard_waiver_list', 'node_list'],
        'contexts' => ['user.node_grants:view', 'user.permissions'],
      ],
    ];
  }

  /**
   * Open violations grouped by content author, worst first.
   */
  public function byAuthor(): array {
    $rows = [];
    foreach ($this->analytics->byAuthor() as $a) {
      $rows[] = [
        $a['name'] ?? $this->t('Unknown'),
        $a['pages'],
        $a['critical'],
        $a['serious'],
        $a['moderate'],
        $a['minor'],
        $a['unknown'],
        $a['waived'],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#caption' => $this->t('Open violations by author'),
        '#header' => [
          $this->t('Author'),
          $this->t('Pages'),
          $this->t('Critical'),
          $this->t('Serious'),
          $this->t('Moderate'),
          $this->t('Minor'),
          $this->t('Unknown'),
          $this->t('Waived'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No scanned content with a known author yet.'),
      ],
      '#cache' => [
        // Any scan/violation/waiver write invalidates the list tags; the
        // aggregation is node-access filtered, hence the grants context.
        'tags' => ['accessguard_scan_list', 'accessguard_violation_list', 'accessguard_waiver_list', 'node_list'],
        'contexts' => ['user.node_grants:view', 'user.permissions'],
      ],
    ];
  }

  /**
   * Daily severity trend: site state as of end of each scan-day.
   */
  public function trends(): array {
    $rows = [];
    foreach (array_reverse($this->trendBuilder->dailySeries()) as $day) {
      $rows[] = [
        $day['date'],
        $day['critical'],
        $day['serious'],
        $day['moderate'],
        $day['minor'],
        $day['needs_review'],
        $day['total'],
      ];
    }
    return [
      // Historical waiver state is not reconstructable, so this series is
      // "as scanned", not "open now" — say so instead of quietly disagreeing
      // with the Overview's numbers.
      'note' => [
        '#markup' => '<p><em>' . $this->t('Counts are as recorded at scan time; waivers are not applied retroactively, so numbers can differ from the Overview.') . '</em></p>',
      ],
      'table' => [
        '#type' => 'table',
        '#caption' => $this->t('Violations over time (site state per scan-day)'),
        '#header' => [
          $this->t('Date'),
          $this->t('Critical'),
          $this->t('Serious'),
          $this->t('Moderate'),
          $this->t('Minor'),
          $this->t('Needs review'),
          $this->t('Total'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No scans recorded yet.'),
      ],
      '#cache' => [
        // Any scan write moves the series; node deletions/access changes
        // change which scans are visible.
        'tags' => ['accessguard_scan_list', 'node_list'],
        'contexts' => ['user.node_grants:view', 'user.permissions'],
      ],
    ];
  }

}
