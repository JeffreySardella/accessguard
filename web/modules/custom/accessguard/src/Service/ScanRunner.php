<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Exception\ScannerBusyException;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Calls the Node scanner microservice and returns its decoded response.
 */
class ScanRunner {

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves the scanner auth token, preferring an environment variable.
   *
   * ACCESSGUARD_SCANNER_TOKEN, when set, wins over the stored config value,
   * so the shared secret can be supplied purely via the environment and kept
   * out of exported (and version-controlled) config.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The accessguard.settings config object.
   */
  public static function resolveToken($config): string {
    $env = getenv('ACCESSGUARD_SCANNER_TOKEN');
    if ($env !== FALSE && $env !== '') {
      return $env;
    }
    return (string) ($config->get('scanner_auth_token') ?? '');
  }

  /**
   * Scans a URL. Returns the decoded { url, violations[] } array.
   *
   * @throws \RuntimeException
   *   On transport or decode failure.
   */
  public function scan(string $url): array {
    $config = $this->configFactory->get('accessguard.settings');
    $endpoint = rtrim((string) $config->get('scanner_endpoint'), '/');
    $options = [
      'json' => ['url' => $url],
      'timeout' => 60,
    ];
    $token = self::resolveToken($config);
    if ($token !== '') {
      $options['headers'] = ['X-Scanner-Token' => $token];
    }
    try {
      $response = $this->httpClient->request('POST', $endpoint . '/scan', $options);
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $e) {
      // A 503 is the scanner shedding load at its concurrency cap — transient,
      // "retry later," not an item failure. Surface it distinctly so the queue
      // worker suspends rather than burning the item's retry budget.
      if ($e instanceof RequestException && $e->getResponse() && $e->getResponse()->getStatusCode() === 503) {
        throw new ScannerBusyException('AccessGuard scanner is at capacity.', 0, $e);
      }
      throw new \RuntimeException('AccessGuard scan failed: ' . $e->getMessage(), 0, $e);
    }
    // Reject a well-formed-JSON-but-wrong-shape response. Without this a
    // response like `null` would decode to an empty result and be recorded as
    // a clean (zero-violation) scan, silently clearing the publish gate.
    if (!is_array($data) || !isset($data['violations']) || !is_array($data['violations'])) {
      throw new \RuntimeException('AccessGuard scanner returned an unexpected response shape.');
    }
    return $data;
  }

  /**
   * Probes whether the scanner service itself is up.
   *
   * Lets the queue worker tell a service-wide outage (suspend the queue,
   * retry next cron) apart from a single scan that failed against a healthy
   * scanner (retry just that item a bounded number of times).
   */
  public function isHealthy(): bool {
    $endpoint = rtrim((string) $this->configFactory->get('accessguard.settings')->get('scanner_endpoint'), '/');
    try {
      // Probe /ready (not /health): a saturated scanner answers 503 there, so
      // the worker treats "busy" like "down" — suspend and retry later —
      // instead of misreading a green liveness check as "scanner is fine, the
      // item is broken." http_errors is disabled so a 503 returns, not throws.
      $response = $this->httpClient->request('GET', $endpoint . '/ready', [
        'timeout' => 5,
        'http_errors' => FALSE,
      ]);
      return $response->getStatusCode() === 200;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
