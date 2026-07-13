<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Controller\DashboardController;
use Drupal\accessguard\Exception\ReportTooLargeException;
use Drupal\accessguard\Service\PdfClient;
use Drupal\accessguard\Service\ReportHtmlBuilder;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the PDF export route degrades gracefully when the scanner is down.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class PdfExportTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'node', 'user', 'system', 'field', 'text', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('accessguard_scan');
    $this->installEntitySchema('accessguard_violation');
    $this->installEntitySchema('accessguard_waiver');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'accessguard']);
    $this->createUser([]);
  }

  /**
   * Tests a failing PdfClient yields a redirect and an error message.
   */
  public function testExportPdfRedirectsWhenScannerDown(): void {
    $failing = $this->createMock(PdfClient::class);
    $failing->method('render')->willThrowException(new \RuntimeException('connection refused'));
    $this->container->set('accessguard.pdf_client', $failing);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $response = DashboardController::create($this->container)->exportPdf();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $messages = \Drupal::messenger()->all();
    $this->assertNotEmpty($messages['error'] ?? []);
  }

  /**
   * Tests a failing ReportHtmlBuilder also degrades gracefully.
   *
   * The graceful fallback must cover report building, not just the HTTP
   * render call — a storage-level exception must not 500.
   */
  public function testExportPdfRedirectsWhenReportBuildFails(): void {
    $failing = $this->createMock(ReportHtmlBuilder::class);
    $failing->method('build')->willThrowException(new \RuntimeException('storage exploded'));
    $this->container->set('accessguard.report_html_builder', $failing);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $response = DashboardController::create($this->container)->exportPdf();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $messages = \Drupal::messenger()->all();
    $this->assertNotEmpty($messages['error'] ?? []);
  }

  /**
   * Tests the underlying failure is logged so operators can diagnose it.
   *
   * A 401 token mismatch, a 413, and a timeout must not vanish into an
   * unconditional "scanner is down" message with no trace in the log.
   */
  public function testExportPdfFailureIsLogged(): void {
    $failing = $this->createMock(PdfClient::class);
    $failing->method('render')->willThrowException(new \RuntimeException('401 unauthorized: token mismatch'));
    $this->container->set('accessguard.pdf_client', $failing);

    $logger = new class() implements LoggerInterface {
      use RfcLoggerTrait;

      /**
       * Collected log lines with placeholders substituted.
       *
       * @var array<int, string>
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = strtr((string) $message, $context);
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    DashboardController::create($this->container)->exportPdf();

    $hits = array_filter($logger->records, fn(string $r) => str_contains($r, 'token mismatch'));
    $this->assertNotEmpty($hits, 'The real failure reason reaches the log.');
  }

  /**
   * Tests an oversized report gets its own message, not "scanner is down".
   */
  public function testExportPdfTooLargeGetsDistinctMessage(): void {
    $failing = $this->createMock(PdfClient::class);
    $failing->method('render')->willThrowException(new ReportTooLargeException('report is 6.0 MB'));
    $this->container->set('accessguard.pdf_client', $failing);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $response = DashboardController::create($this->container)->exportPdf();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $errors = \Drupal::messenger()->all()['error'] ?? [];
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('too large', strtolower((string) reset($errors)));
  }

  /**
   * Tests a working PdfClient yields a PDF response.
   */
  public function testExportPdfStreamsPdf(): void {
    $ok = $this->createMock(PdfClient::class);
    $ok->method('render')->willReturn('%PDF-1.4 body');
    $this->container->set('accessguard.pdf_client', $ok);

    $this->setCurrentUser($this->createUser(['view accessguard reports', 'access content']));
    $response = DashboardController::create($this->container)->exportPdf();

    $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    $this->assertStringStartsWith('%PDF', $response->getContent());
    $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
  }

}
