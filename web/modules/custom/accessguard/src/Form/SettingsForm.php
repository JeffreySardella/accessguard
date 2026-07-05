<?php

namespace Drupal\accessguard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure AccessGuard settings.
 */
class SettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['accessguard.settings'];
  }

  public function getFormId(): string {
    return 'accessguard_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('accessguard.settings');

    $form['scanner_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scanner endpoint'),
      '#description' => $this->t('Base URL of the Node scanner service, e.g. http://accessguard-scanner:3000.'),
      '#default_value' => $config->get('scanner_endpoint'),
      '#required' => TRUE,
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
    $form['rescan_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable cron re-scanning'),
      '#default_value' => (bool) $config->get('rescan_enabled'),
    ];
    $form['rescan_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Re-scan interval (seconds)'),
      '#default_value' => $config->get('rescan_interval') ?: 86400,
      '#min' => 60,
    ];
    $form['rescan_batch'] = [
      '#type' => 'number',
      '#title' => $this->t('Max nodes enqueued per cron run'),
      '#default_value' => $config->get('rescan_batch') ?: 25,
      '#min' => 1,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('accessguard.settings')
      ->set('scanner_endpoint', $form_state->getValue('scanner_endpoint'))
      ->set('gate_enabled', (bool) $form_state->getValue('gate_enabled'))
      ->set('gate_threshold', $form_state->getValue('gate_threshold'))
      ->set('rescan_enabled', (bool) $form_state->getValue('rescan_enabled'))
      ->set('rescan_interval', (int) $form_state->getValue('rescan_interval'))
      ->set('rescan_batch', (int) $form_state->getValue('rescan_batch'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
