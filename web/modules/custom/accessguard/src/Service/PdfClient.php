<?php

namespace Drupal\accessguard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Sends report HTML to the scanner's /pdf endpoint and returns PDF bytes.
 */
class PdfClient {

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Renders HTML to a PDF via the scanner.
   *
   * @throws \RuntimeException
   *   On transport or non-2xx response.
   */
  public function render(string $html): string {
    $config = $this->configFactory->get('accessguard.settings');
    $endpoint = rtrim((string) $config->get('scanner_endpoint'), '/');
    $options = [
      'json' => ['html' => $html],
      'timeout' => 60,
    ];
    $token = (string) ($config->get('scanner_auth_token') ?? '');
    if ($token !== '') {
      $options['headers'] = ['X-Scanner-Token' => $token];
    }
    try {
      $response = $this->httpClient->request('POST', $endpoint . '/pdf', $options);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('AccessGuard PDF render failed: ' . $e->getMessage(), 0, $e);
    }
    return (string) $response->getBody();
  }

}
