<?php

namespace Drupal\accessguard\Plugin\QueueWorker;

use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @QueueWorker(
 *   id = "accessguard_scan_queue",
 *   title = @Translation("AccessGuard scan queue"),
 *   cron = {"time" = 60}
 * )
 */
class AccessguardScanWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(
    array $configuration, $plugin_id, $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRunner $scanRunner,
    protected ScanRecorder $scanRecorder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $c, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $c->get('entity_type.manager'), $c->get('accessguard.scan_runner'), $c->get('accessguard.scan_recorder'));
  }

  public function processItem($data): void {
    $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
    if (!$node) {
      return;
    }
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $author = (int) $node->getOwnerId();
    $result = $this->scanRunner->scan($url);
    $this->scanRecorder->record('node', (int) $node->id(), $author, 'cron', $result);
  }
}
