<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Calls the Node scanner microservice and returns its decoded response.
 */
class ScanRunner {

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

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
    $token = (string) ($config->get('scanner_auth_token') ?? '');
    if ($token !== '') {
      $options['headers'] = ['X-Scanner-Token' => $token];
    }
    try {
      $response = $this->httpClient->request('POST', $endpoint . '/scan', $options);
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $e) {
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

}
