<?php

namespace Drupal\accessguard\Controller;

use Drupal\accessguard\Csv\CsvSafe;
use Drupal\accessguard\Exception\ReportTooLargeException;
use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Service\PdfClient;
use Drupal\accessguard\Service\RegressionService;
use Drupal\accessguard\Service\ReportHtmlBuilder;
use Drupal\accessguard\Service\ViolationAnalytics;
use Drupal\accessguard\Service\WaiverMatcher;
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
    protected WaiverMatcher $waiverMatcher,
    protected ReportHtmlBuilder $reportHtmlBuilder,
    protected PdfClient $pdfClient,
    protected ViolationAnalytics $violationAnalytics,
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
      $container->get('accessguard.violation_analytics'),
    );
  }

  /**
   * Builds the overview: summary + per-page latest-scan table.
   */
  public function overview() {
    $nodeStorage = $this->entityTypeManagerService->getStorage('node');

    // Per-page open/waived counts from the shared analytics context, so the
    // overview agrees with the gate, the analytics tabs, and the PDF summary
    // (raw stored scan counts would include waived violations and hide
    // unknown-impact ones). Node access is already applied there.
    $pages = $this->violationAnalytics->byPage();

    $totals = [
      'open' => 0,
      'critical' => 0,
      'serious' => 0,
      'moderate' => 0,
      'minor' => 0,
      'unknown' => 0,
      'needs_review' => 0,
      'waived' => 0,
    ];
    $rows = [];
    foreach ($pages as $nid => $page) {
      $node = $nodeStorage->load($nid);
      if (!$node) {
        continue;
      }
      foreach ($totals as $key => $_) {
        $totals[$key] += $page[$key];
      }
      $rows[] = [
        Link::fromTextAndUrl($node->label(), Url::fromRoute('accessguard.node_detail', ['node' => $nid])),
        $this->dateFormatter->format($page['created'], 'short'),
        $page['open'],
        $page['critical'],
        $page['serious'],
        $page['moderate'],
        $page['minor'],
        $page['needs_review'],
        $page['waived'],
      ];
    }

    $severityLine = $this->t('Critical: @c, Serious: @s, Moderate: @m, Minor: @mi', [
      '@c' => $totals['critical'],
      '@s' => $totals['serious'],
      '@m' => $totals['moderate'],
      '@mi' => $totals['minor'],
    ]);
    if ($totals['unknown'] > 0) {
      $severityLine = $this->t('@line, Unknown severity: @u', ['@line' => $severityLine, '@u' => $totals['unknown']]);
    }
    $build = [];
    // Honest scope note: automated scanning covers only part of WCAG, so this
    // screen tracks the automatable layer, not conformance (see AUDIT-PASS3).
    $build['disclaimer'] = [
      '#markup' => '<p class="accessguard-disclaimer"><em>'
      . $this->t('Automated checks only (axe-core). A clean scan covers roughly a third of WCAG 2.2 AA criteria and is not, by itself, WCAG conformance or legal compliance — the rest requires manual review.')
      . '</em></p>',
    ];
    // Explicit h2 so the heading outline is h1 (page title) → h2, rather than
    // jumping to the h3 that item_list's own #title renders (WCAG 1.3.1).
    $build['summary_heading'] = [
      '#markup' => '<h2>' . $this->t('Compliance summary') . '</h2>',
    ];
    $build['summary'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Pages scanned: @n', ['@n' => count($rows)]),
        $this->t('Open violations (latest scans): @n', ['@n' => $totals['open']]),
        $severityLine,
        $this->t('Needs review (manual check): @n', ['@n' => $totals['needs_review']]),
        $this->t('Waived: @n', ['@n' => $totals['waived']]),
      ],
    ];
    $build['table'] = [
      '#type' => 'table',
      '#caption' => $this->t('Per-page violation summary'),
      '#header' => [
        $this->t('Page'),
        $this->t('Last scan'),
        $this->t('Open'),
        $this->t('Critical'),
        $this->t('Serious'),
        $this->t('Moderate'),
        $this->t('Minor'),
        $this->t('Needs review'),
        $this->t('Waived'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No scans yet. Run: drush accessguard:scan <nid> --now'),
    ];
    // Cacheable: any scan/violation/waiver write invalidates the entity list
    // tags, node saves invalidate node_list (titles appear in rows), and the
    // node-access filtering and empty-state hint vary by the contexts below.
    $build['#cache'] = [
      'tags' => ['accessguard_scan_list', 'accessguard_violation_list', 'accessguard_waiver_list', 'node_list'],
      'contexts' => ['user.node_grants:view', 'user.permissions'],
    ];

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
    $nodeStorage = $this->entityTypeManagerService->getStorage('node');

    $rows = [];
    $rows[] = ['Page', 'Node ID', 'URL', 'Scan date', 'Rule', 'Impact', 'WCAG', 'Selector', 'Status'];
    // The shared analytics context is batch-loaded and node-access filtered,
    // so the export agrees with every other reporting surface and does not
    // issue per-node queries.
    foreach ($this->violationAnalytics->latestScanContexts() as $ctx) {
      $nid = $ctx['nid'];
      $node = $nodeStorage->load($nid);
      if (!$node) {
        continue;
      }
      $title = $node->label();
      $date = $this->dateFormatter->format($ctx['created'], 'short');
      $url = $ctx['url'];
      if (!$ctx['violations']) {
        $rows[] = [$title, $nid, $url, $date, '(no violations)', '', '', '', ''];
        continue;
      }
      foreach ($ctx['violations'] as $v) {
        $fp = WaiverMatcher::fingerprint($v->get('rule_id')->value, (string) $v->get('selector')->value);
        $status = $ctx['waived'][$fp] ?? 'open';
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
      // No escape character (RFC 4180); also PHP 8.4+ deprecates relying on
      // the historical "\\" default.
      fputcsv($handle, array_map([CsvSafe::class, 'cell'], $row), escape: '');
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
    try {
      $html = $this->reportHtmlBuilder->build();
      $pdf = $this->pdfClient->render($html);
    }
    catch (\Throwable $e) {
      $this->getLogger('accessguard')->error('PDF export failed: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($e instanceof ReportTooLargeException
        ? $this->t('The audit report is too large to render as a PDF. Use the CSV export instead.')
        : $this->t('PDF export failed — the scanner service may be down or misconfigured (details are in the site log). CSV export is still available.'));
      return $this->redirect('accessguard.dashboard');
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
      // Id tie-breaker: same-second scans would otherwise interleave
      // nondeterministically across pager pages.
      ->sort('id', 'DESC')
      ->pager(25)
      ->execute();

    $historyRows = [];
    foreach ($scanStorage->loadMultiple($ids) as $scan) {
      $engine = (string) $scan->get('engine_version')->value;
      $historyRows[] = [
        $this->dateFormatter->format((int) $scan->get('created')->value, 'short'),
        (int) $scan->get('count_critical')->value,
        (int) $scan->get('count_serious')->value,
        (int) $scan->get('count_moderate')->value,
        (int) $scan->get('count_minor')->value,
        $engine !== '' ? $engine : $this->t('—'),
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
        $this->t('Engine'),
      ],
      '#rows' => $historyRows,
      '#empty' => $this->t('No scans yet.'),
    ];
    $build['history_pager'] = ['#type' => 'pager'];
    // Cacheable per node: scan/violation/waiver writes invalidate the list
    // tags, the node's own tags cover title changes, the pager context covers
    // history pages, and permissions gate the waive/un-waive links.
    $build['#cache'] = [
      'tags' => array_merge(
        ['accessguard_scan_list', 'accessguard_violation_list', 'accessguard_waiver_list'],
        $node->getCacheTags()
      ),
      'contexts' => ['user.permissions', 'url.query_args.pagers:0'],
    ];
    return $build;
  }

}
