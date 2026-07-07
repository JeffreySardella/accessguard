<?php

namespace Drupal\accessguard\Controller;

use Drupal\accessguard\Service\ViolationAnalytics;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the per-rule and per-author analytics tabs.
 */
class AnalyticsController extends ControllerBase {

  public function __construct(protected ViolationAnalytics $analytics) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('accessguard.violation_analytics'));
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
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Open violations grouped by content author, worst first.
   */
  public function byAuthor(): array {
    $rows = [];
    foreach ($this->analytics->byAuthor() as $a) {
      $rows[] = [
        $a['name'],
        $a['pages'],
        $a['critical'],
        $a['serious'],
        $a['moderate'],
        $a['minor'],
        $a['waived'],
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Author'),
          $this->t('Pages'),
          $this->t('Critical'),
          $this->t('Serious'),
          $this->t('Moderate'),
          $this->t('Minor'),
          $this->t('Waived'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No scanned content with a known author yet.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
