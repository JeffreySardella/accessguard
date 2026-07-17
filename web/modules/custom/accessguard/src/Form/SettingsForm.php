<?php

namespace Drupal\accessguard\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure AccessGuard settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['accessguard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'accessguard_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('accessguard.settings');

    $form['scanner_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scanner endpoint'),
      '#description' => $this->t('Base URL of the Node scanner service, e.g. http://accessguard-scanner:3000.'),
      '#default_value' => $config->get('scanner_endpoint'),
      '#required' => TRUE,
    ];
    // The token is a shared secret: render it as a password field and never
    // echo the stored value back into the page.
    $hasToken = (string) $config->get('scanner_auth_token') !== '';
    $form['scanner_auth_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Scanner auth token'),
      '#description' => $this->t('Shared secret sent as the X-Scanner-Token header, matching SCANNER_AUTH_TOKEN on the scanner service. For a secret you do not want in exported config, set the ACCESSGUARD_SCANNER_TOKEN environment variable instead — it overrides this field. @state', [
        '@state' => $hasToken
          ? $this->t('A token is currently saved; enter a value to replace it, or leave blank to keep it.')
          : $this->t('Leave empty if the scanner does not require a token.'),
      ]),
    ];
    if ($hasToken) {
      $form['scanner_auth_token_clear'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the saved scanner auth token'),
      ];
    }
    $form['scan_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scan base URL'),
      '#description' => $this->t('Base URL the scanner should use to reach this site, e.g. http://web when the scanner runs on a Docker network. Required for CLI queue processing (drush queue:run, CLI cron), which otherwise generates http://default/... URLs. Leave empty to use the URLs this site generates for itself.'),
      '#default_value' => $config->get('scan_base_url'),
    ];
    $form['gate_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable publish gating'),
      '#default_value' => (bool) $config->get('gate_enabled'),
    ];
    $form['gate_threshold'] = [
      '#type' => 'select',
      '#title' => $this->t('Gating severity threshold'),
      '#options' => [
        'minor' => $this->t('Minor'),
        'moderate' => $this->t('Moderate'),
        'serious' => $this->t('Serious'),
        'critical' => $this->t('Critical'),
      ],
      '#default_value' => $config->get('gate_threshold') ?: 'critical',
    ];
    $form['gate_includes_needs_review'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also block on needs-review findings'),
      '#description' => $this->t('axe reports some potential failures as "needs review" (e.g. contrast over a background image) because it cannot decide automatically. By default these are surfaced but do not block publishing; enable this to gate on them too (stricter, but may block on uncertain findings).'),
      '#default_value' => (bool) $config->get('gate_includes_needs_review'),
    ];
    $form['rescan_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable cron re-scanning'),
      '#default_value' => (bool) $config->get('rescan_enabled'),
    ];
    $form['rescan_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Re-scan interval (seconds)'),
      '#description' => $this->t('The default staleness window for cron re-scans. Content types can override it — or opt out of scanning and gating entirely — in the AccessGuard section of their edit forms.'),
      '#default_value' => $config->get('rescan_interval') ?: 86400,
      '#min' => 60,
    ];
    $form['rescan_batch'] = [
      '#type' => 'number',
      '#title' => $this->t('Max nodes enqueued per cron run'),
      '#default_value' => $config->get('rescan_batch') ?: 25,
      '#min' => 1,
    ];
    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Scan retention (days)'),
      '#description' => $this->t('Cron deletes scans (and their violations) older than this. The latest scan of every page is always kept, so dashboards and the publish gate are unaffected. 0 keeps all scans forever — with daily re-scanning that is roughly 365 scans per page per year.'),
      '#default_value' => (int) ($config->get('retention_days') ?? 0),
      '#min' => 0,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    // A malformed endpoint would otherwise only surface at scan time, as
    // queue-log warnings on every single scan.
    $endpoint = trim((string) $form_state->getValue('scanner_endpoint'));
    if (!UrlHelper::isValid($endpoint, TRUE)) {
      $form_state->setErrorByName('scanner_endpoint', $this->t('The scanner endpoint must be an absolute URL, e.g. http://accessguard-scanner:3000.'));
    }
    $base = trim((string) $form_state->getValue('scan_base_url'));
    if ($base !== '' && !UrlHelper::isValid($base, TRUE)) {
      $form_state->setErrorByName('scan_base_url', $this->t('The scan base URL must be an absolute URL, e.g. http://web.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('accessguard.settings');
    // Blank means "keep the saved token" (the password field never shows it);
    // removal is the explicit checkbox.
    if ($form_state->getValue('scanner_auth_token_clear')) {
      $config->set('scanner_auth_token', '');
    }
    elseif ((string) $form_state->getValue('scanner_auth_token') !== '') {
      $config->set('scanner_auth_token', (string) $form_state->getValue('scanner_auth_token'));
    }
    $config
      ->set('scanner_endpoint', trim((string) $form_state->getValue('scanner_endpoint')))
      ->set('scan_base_url', trim((string) $form_state->getValue('scan_base_url')))
      ->set('gate_enabled', (bool) $form_state->getValue('gate_enabled'))
      ->set('gate_threshold', $form_state->getValue('gate_threshold'))
      ->set('gate_includes_needs_review', (bool) $form_state->getValue('gate_includes_needs_review'))
      ->set('rescan_enabled', (bool) $form_state->getValue('rescan_enabled'))
      ->set('rescan_interval', (int) $form_state->getValue('rescan_interval'))
      ->set('rescan_batch', (int) $form_state->getValue('rescan_batch'))
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
