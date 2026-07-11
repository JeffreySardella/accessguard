<?php

namespace Drupal\accessguard\Plugin\QueueWorker;

use Drupal\accessguard\Exception\ScannerBusyException;
use Drupal\accessguard\Service\ScanAccessToken;
use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued accessibility scan jobs for a node.
 */
#[QueueWorker(
  id: 'accessguard_scan_queue',
  title: new TranslatableMarkup('AccessGuard scan queue'),
  cron: ['time' => 60],
)]
class AccessguardScanWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Total tries per item before it is dropped with an error log.
   */
  public const MAX_ATTEMPTS = 3;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRunner $scanRunner,
    protected ScanRecorder $scanRecorder,
    protected ScanAccessToken $scanAccessToken,
    protected QueueFactory $queueFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $c, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $c->get('entity_type.manager'), $c->get('accessguard.scan_runner'), $c->get('accessguard.scan_recorder'),
      $c->get('accessguard.scan_access_token'), $c->get('queue'), $c->get('logger.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Normalize the payload once: a malformed (non-array) queue item must not
    // fatal on the unguarded offset access below.
    $data = is_array($data) ? $data : [];
    if (empty($data['nid'])) {
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
    if (!$node) {
      return;
    }
    // Unpublished nodes get a signed access token appended so the scanner
    // sees the real content instead of the site's 403 page. Recording a 403
    // page as a node's scan would corrupt compliance data either way: its
    // violations aren't the node's, and a *clean* 403 page would wrongly
    // clear the publish gate.
    $url = $this->scanAccessToken->buildScanUrl($node);
    $author = (int) $node->getOwnerId();

    try {
      $result = $this->scanRunner->scan($url);
    }
    catch (ScannerBusyException $e) {
      // 503 shed: the scanner is at capacity. Suspend the queue so it retries
      // next cron, WITHOUT consuming this item's bounded retry budget.
      $this->loggerFactory->get('accessguard')->info('Scanner busy while scanning node @nid; suspending the queue until next cron.', [
        '@nid' => $data['nid'],
      ]);
      throw new SuspendQueueException($e->getMessage(), 0, $e);
    }
    catch (\RuntimeException $e) {
      $this->handleFailure($data, $e);
      return;
    }

    $this->scanRecorder->record('node', (int) $node->id(), $author, $data['trigger'] ?? 'cron', $result);
  }

  /**
   * Routes a scan failure to queue suspension or bounded per-item retry.
   *
   * A blanket SuspendQueueException on every failure would let one
   * permanently failing page sit at the head of the FIFO queue and block
   * every other node's scan forever. So: probe the scanner's /health
   * endpoint. Service down → suspend the whole queue (the item stays queued
   * and everything retries next cron). Service healthy → the failure is
   * specific to this item; re-enqueue it a bounded number of times, then
   * drop it with an error log.
   */
  protected function handleFailure(array $data, \RuntimeException $e): void {
    $logger = $this->loggerFactory->get('accessguard');
    $nid = $data['nid'] ?? NULL;

    if (!$this->scanRunner->isHealthy()) {
      $logger->warning('Scanner unreachable while scanning node @nid; suspending the scan queue until next cron. @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);
      throw new SuspendQueueException($e->getMessage(), 0, $e);
    }

    $attempts = ((int) ($data['attempts'] ?? 0)) + 1;
    if ($attempts < self::MAX_ATTEMPTS) {
      $this->queueFactory->get('accessguard_scan_queue')->createItem(['attempts' => $attempts] + $data);
      $logger->warning('Scan failed for node @nid (attempt @attempt of @max); requeued. @msg', [
        '@nid' => $nid,
        '@attempt' => $attempts,
        '@max' => self::MAX_ATTEMPTS,
        '@msg' => $e->getMessage(),
      ]);
      return;
    }
    $logger->error('Scan failed for node @nid after @max attempts; giving up on this item. @msg', [
      '@nid' => $nid,
      '@max' => self::MAX_ATTEMPTS,
      '@msg' => $e->getMessage(),
    ]);
  }

}
