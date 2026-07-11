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

  /**
   * The honest automated-coverage disclaimer (safe static HTML).
   *
   * Automated testing (axe-core) detects only a fraction of accessibility
   * issues, so a clean scan is not the same as WCAG conformance. Stating this
   * on the report keeps the artifact from being mistaken for a conformance
   * certification (see docs/AUDIT-PASS3.md).
   */
  public const DISCLAIMER_HTML = '<strong>Automated testing only.</strong> '
    . 'This report reflects automated checks (axe-core), which detect roughly 30–57% of '
    . 'accessibility issues and can definitively evaluate only about a third of WCAG 2.2 '
    . 'Level A/AA success criteria. A clean automated scan is necessary but <em>not '
    . 'sufficient</em> for WCAG conformance or legal compliance; full conformance requires '
    . 'manual expert evaluation and assistive-technology testing. This document is not a '
    . 'conformance certification.';

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
    // The lang and title attributes satisfy WCAG 3.1.1 / 2.4.2 (Level A).
    // lang is "en" because the report body is authored in English (see the
    // static strings below); it must state the content's real language, not
    // the site's UI language.
    $parts[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
      . '<title>Accessibility audit report — ' . $siteName . '</title>'
      . '<style>' . $this->css() . '</style></head><body>';
    $parts[] = '<section class="cover"><h1>Accessibility audit report</h1>'
      . '<p class="site">' . $siteName . '</p>'
      . '<p class="meta">Generated ' . Html::escape($date) . ' &middot; Prepared by ' . $preparedBy . '</p>'
      . '<div class="disclaimer">' . self::DISCLAIMER_HTML . '</div></section>';
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
      . 'th{background:#f0f0f0}.waived{color:#666}.page-block{page-break-inside:avoid;margin:12px 0}'
      . '.disclaimer{margin:24px auto 0;max-width:640px;padding:10px 14px;border:1px solid #999;'
      . 'background:#f7f7f7;color:#333;font-size:11px;text-align:left;line-height:1.4}';
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
      . '<th scope="col">Rule</th><th scope="col">Impact</th><th scope="col">WCAG</th><th scope="col">Pages</th><th scope="col">Open</th><th scope="col">Waived</th>'
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
      . '<th scope="col">Author</th><th scope="col">Pages</th><th scope="col">Critical</th><th scope="col">Serious</th><th scope="col">Moderate</th><th scope="col">Minor</th><th scope="col">Waived</th>'
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
      $out .= '<table><thead><tr><th scope="col">Rule</th><th scope="col">Impact</th><th scope="col">WCAG</th><th scope="col">Selector</th><th scope="col">Status</th></tr></thead><tbody>';
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
