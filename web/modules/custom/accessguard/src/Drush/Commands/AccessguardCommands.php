<?php

namespace Drupal\accessguard\Drush\Commands;

use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AccessguardCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly ScanRunner $scanRunner,
    private readonly ScanRecorder $scanRecorder,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('queue'),
      $container->get('accessguard.scan_runner'),
      $container->get('accessguard.scan_recorder'),
    );
  }

  /**
   * Scan a node for accessibility violations.
   */
  #[CLI\Command(name: 'accessguard:scan')]
  #[CLI\Argument(name: 'nid', description: 'The node ID to scan.')]
  #[CLI\Option(name: 'now', description: 'Run immediately instead of queueing.')]
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
