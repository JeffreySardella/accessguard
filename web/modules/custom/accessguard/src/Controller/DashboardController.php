<?php

namespace Drupal\accessguard\Controller;

use Drupal\accessguard\Csv\CsvSafe;
use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Service\RegressionService;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the AccessGuard compliance overview.
 */
class DashboardController extends ControllerBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManagerService,
    protected DateFormatterInterface $dateFormatter,
    protected ScanRepository $scanRepository,
    protected RegressionService $regressionService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('accessguard.scan_repository'),
      $container->get('accessguard.regression'),
    );
  }

  /**
   * Builds the overview: summary + per-page latest-scan table.
   */
  public function overview() {
    $scanStorage = $this->entityTypeManagerService->getStorage('accessguard_scan');
    $nodeStorage = $this->entityTypeManagerService->getStorage('node');

    // Load only the latest scan per node (not every scan ever run).
    $latestIds = $this->scanRepository->latestScanIdByNode();
    $latest = [];
    foreach ($scanStorage->loadMultiple(array_values($latestIds)) as $scan) {
      $latest[(int) $scan->get('target_entity_id')->value] = $scan;
    }

    $totals = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];
    $rows = [];
    foreach ($latest as $nid => $scan) {
      foreach ($totals as $sev => $_) {
        $totals[$sev] += (int) $scan->get('count_' . $sev)->value;
      }
      $node = $nodeStorage->load($nid);
      $rows[] = [
        $node ? Link::fromTextAndUrl($node->label(), Url::fromRoute('accessguard.node_detail', ['node' => $nid])) : $this->t('Node @id', ['@id' => $nid]),
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

    // Load only the latest scan per node (not every scan ever run).
    $latestIds = $this->scanRepository->latestScanIdByNode();
    $latest = [];
    foreach ($scanStorage->loadMultiple(array_values($latestIds)) as $scan) {
      $latest[(int) $scan->get('target_entity_id')->value] = $scan;
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
      fputcsv($handle, array_map([CsvSafe::class, 'cell'], $row));
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
   * Per-node detail: scan history, regression diff, author attribution.
   */
  public function detail(NodeInterface $node) {
    $nid = (int) $node->id();
    $scanStorage = $this->entityTypeManagerService->getStorage('accessguard_scan');
    $userStorage = $this->entityTypeManagerService->getStorage('user');

    $ids = $scanStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nid)
      ->sort('created', 'DESC')
      ->execute();

    $author = $this->t('Unknown');
    $historyRows = [];
    $first = TRUE;
    foreach ($scanStorage->loadMultiple($ids) as $scan) {
      if ($first) {
        $uid = $scan->get('content_author')->target_id;
        if ($uid && ($u = $userStorage->load($uid))) {
          $author = $u->getDisplayName();
        }
        $first = FALSE;
      }
      $historyRows[] = [
        $this->dateFormatter->format((int) $scan->get('created')->value, 'short'),
        (int) $scan->get('count_critical')->value,
        (int) $scan->get('count_serious')->value,
        (int) $scan->get('count_moderate')->value,
        (int) $scan->get('count_minor')->value,
      ];
    }

    $diff = $this->regressionService->diff($nid);

    $build = [];
    $build['title'] = [
      '#markup' => '<h2>' . Html::escape($node->label()) . '</h2>',
    ];
    $build['attribution'] = [
      '#markup' => '<p>' . $this->t('Content author: <strong>@a</strong>', ['@a' => $author]) . '</p>',
    ];
    $build['regression'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Change since previous scan'),
      '#items' => [
        $this->t('New: @v', ['@v' => $diff['new'] ? implode(', ', $diff['new']) : $this->t('none')]),
        $this->t('Fixed: @v', ['@v' => $diff['fixed'] ? implode(', ', $diff['fixed']) : $this->t('none')]),
        $this->t('Still present: @v', ['@v' => $diff['persisting'] ? implode(', ', $diff['persisting']) : $this->t('none')]),
      ],
    ];
    $build['history'] = [
      '#type' => 'table',
      '#caption' => $this->t('Scan history'),
      '#header' => [
        $this->t('Date'),
        $this->t('Critical'),
        $this->t('Serious'),
        $this->t('Moderate'),
        $this->t('Minor'),
      ],
      '#rows' => $historyRows,
      '#empty' => $this->t('No scans yet.'),
    ];
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

}
