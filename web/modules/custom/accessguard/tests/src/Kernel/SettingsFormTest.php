<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\accessguard\Form\SettingsForm;

/**
 * @group accessguard
 */
class SettingsFormTest extends KernelTestBase {

  protected static $modules = ['accessguard', 'system', 'user'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['accessguard']);
  }

  public function testFormSavesSettings(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'scanner_endpoint' => 'http://custom-scanner:3000',
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
    $this->assertFalse($config->get('gate_enabled'));
    $this->assertSame('serious', $config->get('gate_threshold'));
    $this->assertSame(3600, $config->get('rescan_interval'));
    $this->assertSame(10, $config->get('rescan_batch'));
  }

}
