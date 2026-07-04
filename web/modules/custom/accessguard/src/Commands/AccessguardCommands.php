<?php

namespace Drupal\accessguard\Commands;

use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Commands\DrushCommands;

class AccessguardCommands extends DrushCommands {
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected ScanRunner $scanRunner,
    protected ScanRecorder $scanRecorder,
  ) {
    parent::__construct();
  }

  /**
   * Scan a node for accessibility violations.
   *
   * @command accessguard:scan
   * @param int $nid The node ID to scan.
   * @option now Run immediately instead of queueing.
   */
  public function scan(int $nid, array $options = ['now' => FALSE]): void {
    if (empty($options['now'])) {
      $this->queueFactory->get('accessguard_scan_queue')->createItem(['nid' => $nid]);
      $this->logger()->success("Queued scan for node $nid.");
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      throw new \InvalidArgumentException("Node $nid not found.");
    }
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $result = $this->scanRunner->scan($url);
    $scan = $this->scanRecorder->record('node', (int) $nid, (int) $node->getOwnerId(), 'manual', $result);
    $this->logger()->success("Scan {$scan->id()}: " .
      $scan->get('count_critical')->value . " critical, " .
      $scan->get('count_serious')->value . " serious.");
  }
}
