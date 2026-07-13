<?php

namespace Drupal\Tests\accessguard\Unit;

use Drupal\accessguard\Exception\ReportTooLargeException;
use Drupal\accessguard\Service\PdfClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests PdfClient's HTTP interaction with the scanner /pdf endpoint.
 */
#[Group('accessguard')]
class PdfClientTest extends UnitTestCase {

  /**
   * Tests a 200 returns the raw PDF body and posts to /pdf with the token.
   */
  public function testRenderReturnsBytesAndSendsToken(): void {
    $transactions = [];
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 body')]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($transactions));
    $client = new Client(['handler' => $stack]);
    $config = $this->getConfigFactoryStub([
      'accessguard.settings' => [
        'scanner_endpoint' => 'http://scanner:3000',
        'scanner_auth_token' => 'sekret',
      ],
    ]);
    $bytes = (new PdfClient($client, $config))->render('<h1>x</h1>');
    $this->assertStringStartsWith('%PDF', $bytes);
    $request = $transactions[0]['request'];
    $this->assertSame('http://scanner:3000/pdf', (string) $request->getUri());
    $this->assertSame('sekret', $request->getHeaderLine('X-Scanner-Token'));
  }

  /**
   * Tests that a 200 response that is not a PDF throws.
   *
   * A misconfigured endpoint answering 200 with HTML/JSON must hit the
   * error path, not become a corrupt .pdf download.
   */
  public function testNonPdfBodyThrows(): void {
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'text/html'], '<html lang="en">not a pdf</html>')]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub(['accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000']]);
    $this->expectException(\RuntimeException::class);
    (new PdfClient($client, $config))->render('<h1>x</h1>');
  }

  /**
   * Tests an oversized report throws a distinct error without an HTTP call.
   *
   * The scanner caps /pdf request bodies at 5mb; posting a bigger report
   * would 413 and read as "scanner down". Failing fast with a distinct
   * exception lets the controller tell the user what actually happened.
   */
  public function testOversizedReportThrowsWithoutRequest(): void {
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 body')]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub(['accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000']]);
    $html = str_repeat('a', PdfClient::MAX_REPORT_BYTES + 1);
    try {
      (new PdfClient($client, $config))->render($html);
      $this->fail('Expected ReportTooLargeException.');
    }
    catch (ReportTooLargeException $e) {
      // The queued mock response must still be unconsumed: no request went out.
      $this->assertSame(1, $mock->count());
    }
  }

  /**
   * Tests an HTTP error throws.
   */
  public function testHttpErrorThrows(): void {
    $mock = new MockHandler([new Response(500, [], 'pdf_failed')]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $config = $this->getConfigFactoryStub(['accessguard.settings' => ['scanner_endpoint' => 'http://scanner:3000']]);
    $this->expectException(\RuntimeException::class);
    (new PdfClient($client, $config))->render('<h1>x</h1>');
  }

}
