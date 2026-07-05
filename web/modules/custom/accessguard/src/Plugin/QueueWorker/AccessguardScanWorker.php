<?php

namespace Drupal\accessguard\Plugin\QueueWorker;

use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
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

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRunner $scanRunner,
    protected ScanRecorder $scanRecorder,
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
      $c->get('logger.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
    if (!$node) {
      return;
    }
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $author = (int) $node->getOwnerId();

    try {
      $result = $this->scanRunner->scan($url);
    }
    catch (\RuntimeException $e) {
      // The scanner is unreachable or erroring. Log it and suspend the queue
      // for this run so the item stays queued and is retried next cron, rather
      // than churning through and re-failing every item against a down service.
      $this->loggerFactory->get('accessguard')->warning('Scan failed for node @nid: @msg', [
        '@nid' => $node->id(),
        '@msg' => $e->getMessage(),
      ]);
      throw new SuspendQueueException($e->getMessage(), 0, $e);
    }

    $this->scanRecorder->record('node', (int) $node->id(), $author, $data['trigger'] ?? 'cron', $result);
  }

}
