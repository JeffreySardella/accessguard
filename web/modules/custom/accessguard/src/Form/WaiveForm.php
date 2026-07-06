<?php

namespace Drupal\accessguard\Form;

use Drupal\accessguard\Repository\ScanRepository;
use Drupal\accessguard\Service\WaiverMatcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records a waiver for a specific violation (rule + selector) on a node.
 */
class WaiveForm extends FormBase {

  public function __construct(
    protected WaiverMatcher $waiverMatcher,
    RequestStack $requestStack,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRepository $scanRepository,
  ) {
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('accessguard.waiver_matcher'),
      $container->get('request_stack'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('accessguard.scan_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'accessguard_waive';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $query = $this->requestStack->getCurrentRequest()->query;
    $rule = (string) $query->get('rule', '');
    $selector = (string) $query->get('selector', '');

    $form['node_id'] = ['#type' => 'value', '#value' => $node ? (int) $node->id() : 0];
    $form['rule'] = ['#type' => 'value', '#value' => $rule];
    $form['selector'] = ['#type' => 'value', '#value' => $selector];

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Waiving <strong>@rule</strong> on <code>@sel</code>.', [
        '@rule' => $rule,
        '@sel' => $selector,
      ]) . '</p>',
    ];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Disposition'),
      '#options' => [
        'accepted_risk' => $this->t('Accepted risk'),
        'false_positive' => $this->t('False positive'),
      ],
      '#required' => TRUE,
    ];
    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Recorded in the audit trail.'),
      '#required' => TRUE,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Record waiver'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * A waiver only makes sense for a violation that actually exists in the
   * node's latest scan and is not already waived — otherwise a mistyped or
   * stale URL would silently record a waiver that never matches anything.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $nid = (int) $form_state->getValue('node_id');
    $rule = (string) $form_state->getValue('rule');
    $selector = (string) $form_state->getValue('selector');
    $fp = WaiverMatcher::fingerprint($rule, $selector);

    if (isset($this->waiverMatcher->waivedFingerprints($nid)[$fp])) {
      $form_state->setErrorByName('status', $this->t('This violation is already waived.'));
      return;
    }

    $scanId = $this->scanRepository->latestScanIdForNode($nid);
    $current = [];
    if ($scanId) {
      $violations = $this->entityTypeManager->getStorage('accessguard_violation')
        ->loadByProperties(['scan_id' => $scanId]);
      foreach ($violations as $v) {
        $current[WaiverMatcher::fingerprint((string) $v->get('rule_id')->value, (string) $v->get('selector')->value)] = TRUE;
      }
    }
    if (!isset($current[$fp])) {
      $form_state->setErrorByName('status', $this->t("The latest scan has no violation matching this rule and selector, so there is nothing to waive."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->getValue('node_id');
    $this->waiverMatcher->createWaiver(
      $nid,
      (string) $form_state->getValue('rule'),
      (string) $form_state->getValue('selector'),
      (string) $form_state->getValue('status'),
      (string) $form_state->getValue('reason'),
      (int) $this->currentUser->id(),
    );
    $this->messenger()->addStatus($this->t('Waiver recorded.'));
    $form_state->setRedirectUrl(Url::fromRoute('accessguard.node_detail', ['node' => $nid]));
  }

}
