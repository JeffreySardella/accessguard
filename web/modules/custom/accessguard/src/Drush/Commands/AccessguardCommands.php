<?php

namespace Drupal\accessguard\Drush\Commands;

use Drupal\accessguard\Service\ScanAccessToken;
use Drupal\accessguard\Service\ScanRecorder;
use Drupal\accessguard\Service\ScanRunner;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for AccessGuard.
 */
final class AccessguardCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly ScanRunner $scanRunner,
    private readonly ScanRecorder $scanRecorder,
    private readonly ScanAccessToken $scanAccessToken,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('queue'),
      $container->get('accessguard.scan_runner'),
      $container->get('accessguard.scan_recorder'),
      $container->get('accessguard.scan_access_token'),
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
      $this->queueFactory->get('accessguard_scan_queue')->createItem(['nid' => $nid, 'trigger' => 'manual']);
      $this->io()->success("Queued scan for node $nid.");
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      throw new \InvalidArgumentException("Node $nid not found.");
    }
    // Unpublished nodes get a signed token so the scanner sees the content
    // rather than a 403 page — this is what makes fix-then-rescan possible
    // for content the publish gate is holding back.
    $url = $this->scanAccessToken->buildScanUrl($node);
    $result = $this->scanRunner->scan($url);
    $scan = $this->scanRecorder->record('node', (int) $nid, (int) $node->getOwnerId(), 'manual', $result);
    $this->io()->success("Scan {$scan->id()}: " .
      $scan->get('count_critical')->value . " critical, " .
      $scan->get('count_serious')->value . " serious.");
  }

}
