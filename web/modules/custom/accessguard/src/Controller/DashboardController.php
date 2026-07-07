<?php

namespace Drupal\accessguard\Controller;

use Drupal\accessguard\Csv\CsvSafe;
use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Service\PdfClient;
use Drupal\accessguard\Service\RegressionService;
use Drupal\accessguard\Service\ReportHtmlBuilder;
use Drupal\accessguard\Service\WaiverMatcher;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
    protected WaiverMatcher $waiverMatcher,
    protected ReportHtmlBuilder $reportHtmlBuilder,
    protected PdfClient $pdfClient,
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
      $container->get('accessguard.waiver_matcher'),
      $container->get('accessguard.report_html_builder'),
      $container->get('accessguard.pdf_client'),
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
      $node = $nodeStorage->load($nid);
      // Node-level access applies to the report too: don't leak titles or
      // violation counts of content the viewer cannot see.
      if (!$node || !$node->access('view')) {
        continue;
      }
      foreach ($totals as $sev => $_) {
        $totals[$sev] += (int) $scan->get('count_' . $sev)->value;
      }
      $rows[] = [
        Link::fromTextAndUrl($node->label(), Url::fromRoute('accessguard.node_detail', ['node' => $nid])),
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
        $this->t('Pages scanned: @n', ['@n' => count($rows)]),
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

    $build['export_pdf'] = [
      '#type' => 'link',
      '#title' => $this->t('Export audit PDF'),
      '#url' => Url::fromRoute('accessguard.audit_export_pdf'),
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
    $rows[] = ['Page', 'Node ID', 'URL', 'Scan date', 'Rule', 'Impact', 'WCAG', 'Selector', 'Status'];
    foreach ($latest as $nid => $scan) {
      $node = $nodeStorage->load($nid);
      // The audit export honors node-level access like the overview does.
      if (!$node || !$node->access('view')) {
        continue;
      }
      $waivedByNode = $this->waiverMatcher->waivedFingerprints($nid);
      $title = $node->label();
      $date = $this->dateFormatter->format((int) $scan->get('created')->value, 'short');
      $url = $scan->get('url')->value;
      $violations = $violationStorage->loadByProperties(['scan_id' => $scan->id()]);
      if (!$violations) {
        $rows[] = [$title, $nid, $url, $date, '(no violations)', '', '', '', ''];
        continue;
      }
      foreach ($violations as $v) {
        $fp = WaiverMatcher::fingerprint($v->get('rule_id')->value, (string) $v->get('selector')->value);
        $status = $waivedByNode[$fp] ?? 'open';
        $rows[] = [
          $title,
          $nid,
          $url,
          $date,
          $v->get('rule_id')->value,
          $v->get('impact')->value,
          $v->get('wcag_criterion')->value,
          $v->get('selector')->value,
          $status,
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
   * Streams a PDF audit report rendered by the scanner.
   *
   * The scanner must be running; on failure the user is returned to the
   * dashboard with an error and the CSV export remains available.
   */
  public function exportPdf() {
    $html = $this->reportHtmlBuilder->build();
    try {
      $pdf = $this->pdfClient->render($html);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('PDF export requires the scanner service to be running. CSV export is still available.'));
      return new RedirectResponse(Url::fromRoute('accessguard.dashboard')->toString());
    }
    $response = new Response($pdf);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'attachment; filename="accessguard-audit-' . date('Y-m-d') . '.pdf"');
    return $response;
  }

  /**
   * Per-node detail: scan history, regression diff, author attribution.
   */
  public function detail(NodeInterface $node) {
    $nid = (int) $node->id();
    $scanStorage = $this->entityTypeManagerService->getStorage('accessguard_scan');
    $userStorage = $this->entityTypeManagerService->getStorage('user');

    // Author attribution comes from the latest scan, looked up on its own so
    // it stays correct on any page of the (paginated) history below.
    $author = $this->t('Unknown');
    $latestId = $this->scanRepository->latestScanIdForNode($nid);
    if ($latestId && ($latest = $scanStorage->load($latestId))) {
      $uid = $latest->get('content_author')->target_id;
      if ($uid && ($u = $userStorage->load($uid))) {
        $author = $u->getDisplayName();
      }
    }

    $ids = $scanStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'node')
      ->condition('target_entity_id', $nid)
      ->sort('created', 'DESC')
      ->pager(25)
      ->execute();

    $historyRows = [];
    foreach ($scanStorage->loadMultiple($ids) as $scan) {
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
    $latestScanId = $diff['latest_scan'] ?? NULL;
    $waived = $this->waiverMatcher->waivedFingerprints($nid);
    $canTriage = $this->currentUser()->hasPermission('triage accessguard violations');
    $vRows = [];
    if ($latestScanId) {
      $violations = $this->entityTypeManagerService->getStorage('accessguard_violation')
        ->loadByProperties(['scan_id' => $latestScanId]);
      foreach ($violations as $v) {
        $rule = $v->get('rule_id')->value;
        $selector = (string) $v->get('selector')->value;
        $fp = WaiverMatcher::fingerprint($rule, $selector);
        if (isset($waived[$fp])) {
          $label = $this->t('Waived (@s)', ['@s' => str_replace('_', ' ', $waived[$fp])]);
          $statusCell = $canTriage
            ? [
              'data' => [
                'status' => ['#plain_text' => $label],
                'unwaive' => [
                  '#type' => 'link',
                  '#title' => $this->t('Un-waive'),
                  '#url' => Url::fromRoute('accessguard.unwaive', ['node' => $nid], [
                    'query' => ['rule' => $rule, 'selector' => $selector],
                  ]),
                  '#prefix' => ' — ',
                ],
              ],
            ]
            : $label;
        }
        else {
          $statusCell = $canTriage
            ? Link::fromTextAndUrl($this->t('Waive'), Url::fromRoute('accessguard.waive', ['node' => $nid], [
              'query' => ['rule' => $rule, 'selector' => $selector],
            ]))
            : $this->t('Open');
        }
        $vRows[] = [$rule, $v->get('impact')->value, $selector, $statusCell];
      }
    }
    $build['violations'] = [
      '#type' => 'table',
      '#caption' => $this->t('Current violations'),
      '#header' => [$this->t('Rule'), $this->t('Impact'), $this->t('Selector'), $this->t('Status')],
      '#rows' => $vRows,
      '#empty' => $latestScanId
        ? $this->t('No violations in the latest scan.')
        : $this->t('This page has not been scanned yet.'),
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
    $build['history_pager'] = ['#type' => 'pager'];
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

}
