<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\accessguard\Form\SettingsForm;

/**
 * Tests that the AccessGuard settings form saves its values.
 *
 * @group accessguard
 */
class SettingsFormTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['accessguard']);
  }

  /**
   * Tests that submitting the form persists the settings to config.
   */
  public function testFormSavesSettings(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'scanner_endpoint' => 'http://custom-scanner:3000',
      'scanner_auth_token' => 'sekret',
      // Drupal\Core\Render\Element\Checkbox::valueCallback() treats a raw
      // boolean FALSE as its internal "no input" sentinel (falling back to
      // #default_value, which is TRUE here). NULL is the correct
      // representation of an unchecked checkbox in FormState input.
      'gate_enabled' => NULL,
      'gate_threshold' => 'serious',
      'rescan_enabled' => TRUE,
      'rescan_interval' => 3600,
      'rescan_batch' => 10,
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);

    $config = $this->config('accessguard.settings');
    $this->assertSame('http://custom-scanner:3000', $config->get('scanner_endpoint'));
    $this->assertSame('sekret', $config->get('scanner_auth_token'));
    $this->assertFalse($config->get('gate_enabled'));
    $this->assertSame('serious', $config->get('gate_threshold'));
    $this->assertSame(3600, $config->get('rescan_interval'));
    $this->assertSame(10, $config->get('rescan_batch'));
  }

  /**
   * Tests that a malformed scanner endpoint is rejected at save time.
   *
   * Without validation it would only surface at scan time, as queue-log
   * warnings on every single scan.
   */
  public function testMalformedEndpointIsRejected(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'scanner_endpoint' => 'not a url',
      'gate_threshold' => 'critical',
      'rescan_interval' => 3600,
      'rescan_batch' => 10,
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);

    $this->assertNotEmpty($form_state->getErrors());
    // The shipped default survives the rejected submission.
    $this->assertSame('http://accessguard-scanner:3000', $this->config('accessguard.settings')->get('scanner_endpoint'));
  }

  /**
   * Tests that a malformed scan base URL is rejected while empty is allowed.
   */
  public function testMalformedScanBaseUrlIsRejected(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'scanner_endpoint' => 'http://scanner:3000',
      'scan_base_url' => 'web:8080',
      'gate_threshold' => 'critical',
      'rescan_interval' => 3600,
      'rescan_batch' => 10,
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertNotEmpty($form_state->getErrors());

    $form_state = new FormState();
    $form_state->setValues([
      'scanner_endpoint' => 'http://scanner:3000',
      'scan_base_url' => 'http://web:8080',
      'gate_threshold' => 'critical',
      'rescan_interval' => 3600,
      'rescan_batch' => 10,
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertEmpty($form_state->getErrors());
    $this->assertSame('http://web:8080', $this->config('accessguard.settings')->get('scan_base_url'));
  }

  /**
   * Tests the keep-blank / explicit-clear semantics of the token field.
   *
   * The token renders as a password field that never echoes the saved value,
   * so an untouched (blank) field must keep the stored secret and removal
   * must be the explicit checkbox.
   */
  public function testBlankTokenKeepsSavedValueAndClearRemovesIt(): void {
    $this->config('accessguard.settings')->set('scanner_auth_token', 'sekret')->save();

    $values = [
      'scanner_endpoint' => 'http://scanner:3000',
      'scanner_auth_token' => '',
      'gate_threshold' => 'critical',
      'rescan_interval' => 3600,
      'rescan_batch' => 10,
    ];
    $form_state = new FormState();
    $form_state->setValues($values);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertSame('sekret', $this->config('accessguard.settings')->get('scanner_auth_token'));

    $form_state = new FormState();
    $form_state->setValues(['scanner_auth_token_clear' => 1] + $values);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertSame('', $this->config('accessguard.settings')->get('scanner_auth_token'));
  }

}
