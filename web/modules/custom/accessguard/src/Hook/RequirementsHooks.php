<?php

namespace Drupal\accessguard\Hook;

use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Runtime status-report requirements for AccessGuard.
 */
class RequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    protected ScanRunner $scanRunner,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Warns on the status report when the scanner service is unreachable.
   *
   * The module's core function depends on the external scanner microservice,
   * so a silent outage should be visible at /admin/reports/status rather than
   * surfacing only as failing scans.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $endpoint = (string) $this->configFactory->get('accessguard.settings')->get('scanner_endpoint');
    $healthy = $this->scanRunner->isHealthy();
    return [
      'accessguard_scanner' => [
        'title' => $this->t('AccessGuard scanner'),
        'value' => $healthy
          ? $this->t('Reachable at @endpoint', ['@endpoint' => $endpoint])
          : $this->t('Unreachable at @endpoint', ['@endpoint' => $endpoint]),
        'description' => $healthy
          ? $this->t('The accessibility scanner service is responding.')
          : $this->t('The accessibility scanner service is not responding; scans will fail until it is reachable. Check the service and the endpoint in the AccessGuard settings.'),
        'severity' => $healthy ? REQUIREMENT_OK : REQUIREMENT_WARNING,
      ],
    ];
  }

}
