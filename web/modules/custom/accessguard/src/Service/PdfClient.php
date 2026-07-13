<?php

namespace Drupal\accessguard\Service;

use Drupal\accessguard\Exception\ReportTooLargeException;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Sends report HTML to the scanner's /pdf endpoint and returns PDF bytes.
 */
class PdfClient {

  /**
   * Largest report HTML (in bytes) the client will send.
   *
   * The scanner caps /pdf request bodies at 5mb of JSON; 4mb of raw HTML
   * leaves headroom for the JSON encoding overhead. Bigger reports fail fast
   * with a distinct exception instead of a 413 that reads as "scanner down".
   */
  public const MAX_REPORT_BYTES = 4194304;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Renders HTML to a PDF via the scanner.
   *
   * @throws \Drupal\accessguard\Exception\ReportTooLargeException
   *   When the report HTML exceeds MAX_REPORT_BYTES.
   * @throws \RuntimeException
   *   On transport failure, non-2xx response, or a response that is not PDF.
   */
  public function render(string $html): string {
    if (strlen($html) > self::MAX_REPORT_BYTES) {
      throw new ReportTooLargeException(sprintf(
        'AccessGuard report HTML is %.1f MB; the scanner accepts at most %.1f MB. Use the CSV export for sites this large.',
        strlen($html) / 1048576,
        self::MAX_REPORT_BYTES / 1048576,
      ));
    }
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
