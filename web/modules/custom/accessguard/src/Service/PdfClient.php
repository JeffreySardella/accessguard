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
    $token = ScanRunner::resolveToken($config);
    if ($token !== '') {
      $options['headers'] = ['X-Scanner-Token' => $token];
    }
    try {
      $response = $this->httpClient->request('POST', $endpoint . '/pdf', $options);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('AccessGuard PDF render failed: ' . $e->getMessage(), 0, $e);
    }
    $body = (string) $response->getBody();
    // A misconfigured endpoint can answer 200 with HTML or JSON; without this
    // check the user downloads a corrupt .pdf instead of hitting the friendly
    // error path the caller provides for exceptions.
    if (!str_starts_with($body, '%PDF')) {
      throw new \RuntimeException('AccessGuard PDF render returned something that is not a PDF.');
    }
    return $body;
  }

}
