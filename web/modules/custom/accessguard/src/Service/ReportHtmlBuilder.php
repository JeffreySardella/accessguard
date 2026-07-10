<?php

namespace Drupal\accessguard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Builds a self-contained HTML audit report for PDF rendering.
 *
 * The output inlines all CSS and references no external assets, so the scanner
 * can render it to PDF with all outbound network requests blocked.
 */
class ReportHtmlBuilder {

  public function __construct(
    protected ViolationAnalytics $analytics,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Builds the complete audit report HTML document.
   */
  public function build(): string {
    $siteName = Html::escape($this->configFactory->get('system.site')->get('name') ?? 'Site');
    $date = $this->dateFormatter->format($this->time->getRequestTime(), 'medium');
    $preparedBy = Html::escape($this->currentUser->getDisplayName());

    $parts = [];
    $parts[] = '<!doctype html><html><head><meta charset="utf-8"><style>' . $this->css() . '</style></head><body>';
    $parts[] = '<section class="cover"><h1>Accessibility audit report</h1>'
      . '<p class="site">' . $siteName . '</p>'
      . '<p class="meta">Generated ' . Html::escape($date) . ' &middot; Prepared by ' . $preparedBy . '</p></section>';
    $parts[] = $this->summarySection();
    $parts[] = $this->byRuleSection();
    $parts[] = $this->byAuthorSection();
    $parts[] = $this->findingsSection();
    $parts[] = '</body></html>';
    return implode('', $parts);
  }

  /**
   * Inline stylesheet.
   */
  protected function css(): string {
    return 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#1a1a1a;font-size:12px}'
      . 'h1{font-size:26px;margin:0 0 8px}h2{font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;margin-top:24px}'
      . '.cover{padding:60px 0;text-align:center;page-break-after:always}.site{font-size:18px;font-weight:bold}'
      . '.meta{color:#666}table{width:100%;border-collapse:collapse;margin:8px 0}'
      . 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;vertical-align:top}'
      . 'th{background:#f0f0f0}.waived{color:#666}.page-block{page-break-inside:avoid;margin:12px 0}';
  }

  /**
   * Compliance summary block.
   */
  protected function summarySection(): string {
    $s = $this->analytics->summary();
    return '<section><h2>Compliance summary</h2><ul>'
      . '<li>Pages scanned: ' . (int) $s['pages'] . '</li>'
      . '<li>Total open violations: ' . (int) $s['open'] . '</li>'
      . '<li>Critical: ' . (int) $s['critical'] . ', Serious: ' . (int) $s['serious']
      . ', Moderate: ' . (int) $s['moderate'] . ', Minor: ' . (int) $s['minor'] . '</li></ul></section>';
  }

  /**
   * Violations-by-rule table.
   */
  protected function byRuleSection(): string {
    $rows = '';
    foreach ($this->analytics->byRule() as $r) {
      $rows .= '<tr><td>' . Html::escape($r['rule_id']) . '</td><td>' . Html::escape($r['impact'])
        . '</td><td>' . Html::escape($r['wcag']) . '</td><td>' . (int) $r['pages']
        . '</td><td>' . (int) $r['open'] . '</td><td>' . (int) $r['waived'] . '</td></tr>';
    }
    if ($rows === '') {
      $rows = '<tr><td colspan="6">No violations found.</td></tr>';
    }
    return '<section><h2>Violations by rule</h2><table><thead><tr>'
      . '<th>Rule</th><th>Impact</th><th>WCAG</th><th>Pages</th><th>Open</th><th>Waived</th>'
      . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
  }

  /**
   * Violations-by-author table.
   */
  protected function byAuthorSection(): string {
    $rows = '';
    foreach ($this->analytics->byAuthor() as $a) {
      $rows .= '<tr><td>' . Html::escape($a['name']) . '</td><td>' . (int) $a['pages']
        . '</td><td>' . (int) $a['critical'] . '</td><td>' . (int) $a['serious']
        . '</td><td>' . (int) $a['moderate'] . '</td><td>' . (int) $a['minor']
        . '</td><td>' . (int) $a['waived'] . '</td></tr>';
    }
    if ($rows === '') {
      $rows = '<tr><td colspan="7">No scanned content with a known author.</td></tr>';
    }
    return '<section><h2>Violations by author</h2><table><thead><tr>'
      . '<th>Author</th><th>Pages</th><th>Critical</th><th>Serious</th><th>Moderate</th><th>Minor</th><th>Waived</th>'
      . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
  }

  /**
   * Per-page findings, including waived items with their reasons.
   */
  protected function findingsSection(): string {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $out = '<section><h2>Findings by page</h2>';
    // The shared analytics context is batch-loaded and node-access filtered,
    // so the report agrees with the summary/rule/author sections above and
    // does not issue per-node queries.
    foreach ($this->analytics->latestScanContexts() as $ctx) {
      $node = $nodeStorage->load($ctx['nid']);
      if (!$node) {
        continue;
      }
      $date = $this->dateFormatter->format($ctx['created'], 'short');
      $url = Html::escape($ctx['url']);
      $out .= '<div class="page-block"><h3>' . Html::escape($node->label()) . '</h3>'
        . '<p class="meta">' . $url . ' &middot; last scan ' . Html::escape($date) . '</p>';

      if (!$ctx['violations']) {
        $out .= '<p>No violations in the latest scan.</p></div>';
        continue;
      }
      $out .= '<table><thead><tr><th>Rule</th><th>Impact</th><th>WCAG</th><th>Selector</th><th>Status</th></tr></thead><tbody>';
      foreach ($ctx['violations'] as $v) {
        $rule = (string) $v->get('rule_id')->value;
        $selector = (string) $v->get('selector')->value;
        $fp = WaiverMatcher::fingerprint($rule, $selector);
        if (isset($ctx['waived'][$fp])) {
          $status = 'Waived (' . Html::escape(str_replace('_', ' ', $ctx['waived'][$fp])) . ')';
          if (isset($ctx['waiver_reasons'][$fp]) && $ctx['waiver_reasons'][$fp] !== '') {
            $status .= ': ' . Html::escape($ctx['waiver_reasons'][$fp]);
          }
          $cls = ' class="waived"';
        }
        else {
          $status = 'Open';
          $cls = '';
        }
        $out .= '<tr' . $cls . '><td>' . Html::escape($rule) . '</td><td>' . Html::escape((string) $v->get('impact')->value)
          . '</td><td>' . Html::escape((string) $v->get('wcag_criterion')->value) . '</td><td>' . Html::escape($selector)
          . '</td><td>' . $status . '</td></tr>';
      }
      $out .= '</tbody></table></div>';
    }
    return $out . '</section>';
  }

}
