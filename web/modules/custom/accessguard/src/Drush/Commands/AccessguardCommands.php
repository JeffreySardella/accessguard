<?php

namespace Drupal\accessguard\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Service\GateEvaluator;
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
    private readonly GateEvaluator $gateEvaluator,
    private readonly ScanRepository $scanRepository,
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
      $container->get('accessguard.gate_evaluator'),
      $container->get('accessguard.scan_repository'),
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

  /**
   * Evaluate the accessibility gate for CI: exit 1 if anything blocks.
   *
   * Applies the same policy as the publish gate (gate_threshold rank,
   * waivers honored, needs-review per gate_includes_needs_review) to every
   * published node that has a scan — or to one node when a nid is given.
   * Runs regardless of gate_enabled: invoking the command is the opt-in;
   * that flag governs the interactive publish gate only. Never-scanned
   * nodes are not failures (coverage is a different question).
   */
  #[CLI\Command(name: 'accessguard:gate')]
  #[CLI\Argument(name: 'nid', description: 'Evaluate one node id instead of all published scanned nodes.')]
  #[CLI\FieldLabels(labels: ['nid' => 'Node', 'title' => 'Title', 'blocking' => 'Blocking'])]
  #[CLI\DefaultTableFields(fields: ['nid', 'title', 'blocking'])]
  public function gate(?int $nid = NULL): CommandResult {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    if ($nid !== NULL) {
      $node = $nodeStorage->load($nid);
      if (!$node) {
        throw new \InvalidArgumentException("Node $nid not found.");
      }
      $nids = [$nid];
    }
    else {
      // Published content is what CI cares about; drafts are already held
      // back by the interactive publish gate.
      $nids = [];
      foreach (array_keys($this->scanRepository->latestScanIdByNode()) as $candidate) {
        $node = $nodeStorage->load($candidate);
        if ($node && $node->isPublished()) {
          $nids[] = (int) $candidate;
        }
      }
    }

    $rows = [];
    $failing = 0;
    foreach ($nids as $id) {
      $blocking = $this->gateEvaluator->blockingCount($id);
      if ($blocking === NULL) {
        // Never scanned: nothing to gate on (single-nid path only; the
        // all-nodes list is built from scans, so it can't get here).
        $this->io()->note("Node $id has never been scanned; nothing to gate on.");
        continue;
      }
      if ($blocking > 0) {
        $failing++;
      }
      $rows[] = [
        'nid' => $id,
        'title' => (string) $nodeStorage->load($id)->label(),
        'blocking' => $blocking,
      ];
    }

    if (!$rows) {
      $this->io()->note('No scanned published content to evaluate.');
    }
    elseif ($failing === 0) {
      $this->io()->success(sprintf('Gate passed: %d node(s) evaluated, none blocking.', count($rows)));
    }
    else {
      $this->io()->error(sprintf('Gate FAILED: %d of %d node(s) have blocking violations.', $failing, count($rows)));
    }

    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $failing > 0 ? 1 : 0);
  }

}
