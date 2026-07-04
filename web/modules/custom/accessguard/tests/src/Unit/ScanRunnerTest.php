<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Service\ScanRunner;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class ScanRunnerTest extends UnitTestCase {

  public function testScanReturnsDecodedViolations(): void {
    $payload = json_encode(['url' => 'http://x/node/1', 'violations' => [
      ['ruleId' => 'image-alt', 'impact' => 'critical', 'wcagCriterion' => 'wcag2a',
       'selector' => 'img', 'html' => '<img>', 'helpUrl' => 'http://help'],
    ]]);
    $mock = new MockHandler([new Response(200, [], $payload)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000'],
    ]);
    $runner = new ScanRunner($client, $config);
    $result = $runner->scan('http://x/node/1');
    $this->assertSame('image-alt', $result['violations'][0]['ruleId']);
  }

}
