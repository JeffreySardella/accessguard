<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Service\ScanRunner;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests ScanRunner's handling of the scanner microservice's HTTP response.
 *
 * @group accessguard
 */
class ScanRunnerTest extends UnitTestCase {

  /**
   * Tests that a well-formed response decodes to its violations.
   */
  public function testScanReturnsDecodedViolations(): void {
    $payload = json_encode([
      'url' => 'http://x/node/1',
      'violations' => [
      [
        'ruleId' => 'image-alt',
        'impact' => 'critical',
        'wcagCriterion' => 'wcag2a',
        'selector' => 'img',
        'html' => '<img>',
        'helpUrl' => 'http://help',
      ],
      ],
    ]);
    $mock = new MockHandler([new Response(200, [], $payload)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000'],
    ]);
    $runner = new ScanRunner($client, $config);
    $result = $runner->scan('http://x/node/1');
    $this->assertSame('image-alt', $result['violations'][0]['ruleId']);
  }

  /**
   * Tests that a well-formed-JSON-but-wrong-shape response throws.
   */
  public function testMalformedJsonThrows(): void {
    $mock = new MockHandler([new Response(200, [], 'null')]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub(['accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000']]);
    $runner = new ScanRunner($client, $config);
    $this->expectException(\RuntimeException::class);
    $runner->scan('http://x/node/1');
  }

  /**
   * Tests that an HTTP error response throws.
   */
  public function testHttpErrorThrows(): void {
    $mock = new MockHandler([new Response(500, [], 'oops')]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub(['accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000']]);
    $runner = new ScanRunner($client, $config);
    $this->expectException(\RuntimeException::class);
    $runner->scan('http://x/node/1');
  }

}
