<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Controller\DashboardController;
use Drupal\accessguard\Service\PdfClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
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
