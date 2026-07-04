<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls the AccessGuard Node scanner over HTTP and returns the decoded scan.
 */
class ScanRunner {

  public function __construct(
    protected HttpClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Scans a URL for accessibility violations via the Node scanner service.
   *
   * @param string $url
   *   The URL to scan.
   *
   * @return array
   *   The decoded { url, violations[] } array from the scanner.
   *
   * @throws \RuntimeException
   *   If the request to the scanner fails.
   */
  public function scan(string $url): array {
    $endpoint = rtrim($this->configFactory->get('accessguard.settings')->get('scanner_endpoint'), '/');
    try {
      $response = $this->httpClient->request('POST', $endpoint . '/scan', [
        'json' => ['url' => $url],
        'timeout' => 60,
      ]);
      return $response->toArray();
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('AccessGuard scan failed: ' . $e->getMessage(), 0, $e);
    }
  }

}
