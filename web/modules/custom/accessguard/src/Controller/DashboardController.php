<?php

namespace Drupal\accessguard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the AccessGuard compliance overview.
 */
class DashboardController extends ControllerBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManagerService,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the overview: summary + per-page latest-scan table.
   */
  public function overview() {
    $scanStorage = $this->entityTypeManagerService->getStorage('accessguard_scan');
    $nodeStorage = $this->entityTypeManagerService->getStorage('node');

    // Keep only the latest scan per target node.
    $latest = [];
    foreach ($scanStorage->loadMultiple() as $scan) {
      $nid = $scan->get('target_entity_id')->value;
      $created = (int) $scan->get('created')->value;
      if (!isset($latest[$nid]) || $created > (int) $latest[$nid]->get('created')->value) {
        $latest[$nid] = $scan;
      }
    }

    $totals = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
    $rows = [];
    foreach ($latest as $nid => $scan) {
      foreach ($totals as $sev => $_) {
        $totals[$sev] += (int) $scan->get('count_' . $sev)->value;
      }
      $node = $nodeStorage->load($nid);
      $rows[] = [
        $node ? $node->toLink() : $this->t('Node @id', ['@id' => $nid]),
        $this->dateFormatter->format((int) $scan->get('created')->value, 'short'),
        (int) $scan->get('count_critical')->value,
        (int) $scan->get('count_serious')->value,
        (int) $scan->get('count_moderate')->value,
        (int) $scan->get('count_minor')->value,
      ];
    }

    $totalViolations = array_sum($totals);

    $build = [];
    $build['summary'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Compliance summary'),
      '#items' => [
        $this->t('Pages scanned: @n', ['@n' => count($latest)]),
        $this->t('Total violations (latest scans): @n', ['@n' => $totalViolations]),
        $this->t('Critical: @c, Serious: @s, Moderate: @m, Minor: @mi', [
          '@c' => $totals['critical'],
          '@s' => $totals['serious'],
          '@m' => $totals['moderate'],
          '@mi' => $totals['minor'],
        ]),
      ],
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Page'),
        $this->t('Last scan'),
        $this->t('Critical'),
        $this->t('Serious'),
        $this->t('Moderate'),
        $this->t('Minor'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No scans yet. Run: drush accessguard:scan <nid> --now'),
    ];
    $build['#cache'] = ['max-age' => 0];

    $build['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export audit CSV'),
      '#url' => Url::fromRoute('accessguard.audit_export'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

  /**
   * Streams a CSV of current violations (latest scan per node).
   */
  public function exportCsv() {
    $scanStorage = $this->entityTypeManagerService->getStorage('accessguard_scan');
    $violationStorage = $this->entityTypeManagerService->getStorage('accessguard_violation');
    $nodeStorage = $this->entityTypeManagerService->getStorage('node');

    // Latest scan per node.
    $latest = [];
    foreach ($scanStorage->loadMultiple() as $scan) {
      $nid = $scan->get('target_entity_id')->value;
      $created = (int) $scan->get('created')->value;
      if (!isset($latest[$nid]) || $created > (int) $latest[$nid]->get('created')->value) {
        $latest[$nid] = $scan;
      }
    }

    $rows = [];
    $rows[] = ['Page', 'Node ID', 'URL', 'Scan date', 'Rule', 'Impact', 'WCAG', 'Selector'];
    foreach ($latest as $nid => $scan) {
      $node = $nodeStorage->load($nid);
      $title = $node ? $node->label() : ('Node ' . $nid);
      $date = $this->dateFormatter->format((int) $scan->get('created')->value, 'short');
      $url = $scan->get('url')->value;
      $violations = $violationStorage->loadByProperties(['scan_id' => $scan->id()]);
      if (!$violations) {
        $rows[] = [$title, $nid, $url, $date, '(no violations)', '', '', ''];
        continue;
      }
      foreach ($violations as $v) {
        $rows[] = [
          $title,
          $nid,
          $url,
          $date,
          $v->get('rule_id')->value,
          $v->get('impact')->value,
          $v->get('wcag_criterion')->value,
          $v->get('selector')->value,
        ];
      }
    }

    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
      fputcsv($handle, array_map([$this, 'sanitizeCsvCell'], $row));
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="accessguard-audit.csv"');
    return $response;
  }

  /**
   * Neutralizes CSV formula injection.
   *
   * Spreadsheet apps treat a cell beginning with =, +, -, @, tab, or CR as a
   * formula. Prefix any such string value with a single quote so it is read as
   * text, not executed.
   */
  protected function sanitizeCsvCell($cell) {
    if (is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], TRUE)) {
      return "'" . $cell;
    }
    return $cell;
  }

}
