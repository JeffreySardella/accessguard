<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Service\ScanRunner;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ScanRunner's handling of the scanner microservice's HTTP response.
 */
#[Group('accessguard')]
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
   * Tests that a configured auth token is sent as a request header.
   */
  public function testAuthTokenHeaderSentWhenConfigured(): void {
    $transactions = [];
    $mock = new MockHandler([new Response(200, [], json_encode(['url' => 'x', 'violations' => []]))]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($transactions));
    $client = new Client(['handler' => $stack]);
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => [
        'scanner_endpoint' => 'http://scanner:3000',
        'scanner_auth_token' => 'sekret',
      ],
    ]);
    (new ScanRunner($client, $config))->scan('http://x/node/1');
    $this->assertSame('sekret', $transactions[0]['request']->getHeaderLine('X-Scanner-Token'));
  }

  /**
   * Tests that no auth header is sent when no token is configured.
   */
  public function testNoAuthHeaderWhenTokenNotConfigured(): void {
    $transactions = [];
    $mock = new MockHandler([new Response(200, [], json_encode(['url' => 'x', 'violations' => []]))]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($transactions));
    $client = new Client(['handler' => $stack]);
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000'],
    ]);
    (new ScanRunner($client, $config))->scan('http://x/node/1');
    $this->assertFalse($transactions[0]['request']->hasHeader('X-Scanner-Token'));
  }

  /**
   * Tests that the token env var overrides the configured value.
   *
   * Lets the shared secret be supplied via the environment and kept out of
   * exported config.
   */
  public function testEnvTokenOverridesConfig(): void {
    $transactions = [];
    $mock = new MockHandler([new Response(200, [], json_encode(['url' => 'x', 'violations' => []]))]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($transactions));
    $client = new Client(['handler' => $stack]);
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => [
        'scanner_endpoint' => 'http://scanner:3000',
        'scanner_auth_token' => 'from-config',
      ],
    ]);
    putenv('ACCESSGUARD_SCANNER_TOKEN=from-env');
    try {
      (new ScanRunner($client, $config))->scan('http://x/node/1');
    }
    finally {
      putenv('ACCESSGUARD_SCANNER_TOKEN');
    }
    $this->assertSame('from-env', $transactions[0]['request']->getHeaderLine('X-Scanner-Token'));
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
