<?php

namespace Drupal\accessguard\Form;

use Drupal\accessguard\Service\WaiverMatcher;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Removes the waiver for a specific violation (rule + selector) on a node.
 */
class UnwaiveForm extends ConfirmFormBase {

  /**
   * The node the waiver belongs to.
   */
  protected ?NodeInterface $node = NULL;

  /**
   * The waived rule id.
   */
  protected string $rule = '';

  /**
   * The waived selector.
   */
  protected string $selector = '';

  public function __construct(
    protected WaiverMatcher $waiverMatcher,
    RequestStack $requestStack,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'accessguard_unwaive';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove the waiver for %rule on %selector?', [
      '%rule' => $this->rule,
      '%selector' => $this->selector,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The violation will count against the publish gate again.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('accessguard.node_detail', ['node' => $this->node ? $this->node->id() : 0]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $this->node = $node;
    $query = $this->requestStack->getCurrentRequest()->query;
    $this->rule = (string) $query->get('rule', '');
    $this->selector = (string) $query->get('selector', '');

    $form['node_id'] = ['#type' => 'value', '#value' => $node ? (int) $node->id() : 0];
    $form['rule'] = ['#type' => 'value', '#value' => $this->rule];
    $form['selector'] = ['#type' => 'value', '#value' => $this->selector];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->getValue('node_id');
    $this->waiverMatcher->deleteWaivers(
      $nid,
      (string) $form_state->getValue('rule'),
      (string) $form_state->getValue('selector'),
    );
    $this->messenger()->addStatus($this->t('Waiver removed.'));
    $form_state->setRedirectUrl(Url::fromRoute('accessguard.node_detail', ['node' => $nid]));
  }

}
