<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Service\ScanRunner;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ScanRunnerTest extends UnitTestCase {

  public function testScanReturnsDecodedViolations(): void {
    $payload = json_encode(['url' => 'http://x/node/1', 'violations' => [
      ['ruleId' => 'image-alt', 'impact' => 'critical', 'wcagCriterion' => 'wcag2a',
       'selector' => 'img', 'html' => '<img>', 'helpUrl' => 'http://help'],
    ]]);
    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000'],
    ]);
    $runner = new ScanRunner($client, $config);
    $result = $runner->scan('http://x/node/1');
    $this->assertSame('image-alt', $result['violations'][0]['ruleId']);
  }

}
