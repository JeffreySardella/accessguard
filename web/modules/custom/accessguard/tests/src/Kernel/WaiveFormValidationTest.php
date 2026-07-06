<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Form\WaiveForm;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that WaiveForm only accepts real, un-waived current violations.
 *
 * @group accessguard
 */
class WaiveFormValidationTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'node', 'user', 'system', 'field', 'text', 'filter'];

  /**
   * The node under test.
   */
  protected Node $node;

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
    $this->installConfig(['field', 'filter', 'node', 'accessguard']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->node = Node::create(['type' => 'page', 'title' => 'x', 'status' => 1]);
    $this->node->save();
    $scan = \Drupal::entityTypeManager()->getStorage('accessguard_scan')->create([
      'target_entity_type' => 'node',
      'target_entity_id' => $this->node->id(),
      'status' => 'complete',
      'count_critical' => 1,
    ]);
    $scan->save();
    \Drupal::entityTypeManager()->getStorage('accessguard_violation')->create([
      'scan_id' => $scan->id(),
      'rule_id' => 'image-alt',
      'impact' => 'critical',
      'selector' => 'img',
    ])->save();
  }

  /**
   * Submits the waive form for this node with the given values.
   *
   * The rule and selector travel as query parameters, exactly like the
   * "Waive" links on the detail page produce them.
   */
  private function submitWaiveForm(string $rule, string $selector): FormState {
    $request = Request::create('/waive', 'GET', ['rule' => $rule, 'selector' => $selector]);
    \Drupal::requestStack()->push($request);
    try {
      $form_state = new FormState();
      $form_state->setValues([
        'status' => 'false_positive',
        'reason' => 'test',
      ]);
      \Drupal::formBuilder()->submitForm(WaiveForm::class, $form_state, $this->node);
      return $form_state;
    }
    finally {
      \Drupal::requestStack()->pop();
    }
  }

  /**
   * Tests that a rule/selector matching a current violation is accepted.
   */
  public function testMatchingViolationIsWaivable(): void {
    $form_state = $this->submitWaiveForm('image-alt', 'img');
    $this->assertSame([], $form_state->getErrors());
    $waivers = \Drupal::entityTypeManager()->getStorage('accessguard_waiver')->loadMultiple();
    $this->assertCount(1, $waivers);
  }

  /**
   * Tests that a rule/selector with no matching violation is rejected.
   */
  public function testUnknownViolationIsRejected(): void {
    $form_state = $this->submitWaiveForm('bogus-rule', 'div.typo');
    $this->assertNotSame([], $form_state->getErrors());
    $waivers = \Drupal::entityTypeManager()->getStorage('accessguard_waiver')->loadMultiple();
    $this->assertCount(0, $waivers);
  }

  /**
   * Tests that an already-waived violation is rejected with an error.
   */
  public function testAlreadyWaivedViolationIsRejected(): void {
    \Drupal::service('accessguard.waiver_matcher')
      ->createWaiver((int) $this->node->id(), 'image-alt', 'img', 'accepted_risk', 'first', 1);

    $form_state = $this->submitWaiveForm('image-alt', 'img');

    $this->assertNotSame([], $form_state->getErrors());
    $waivers = \Drupal::entityTypeManager()->getStorage('accessguard_waiver')->loadMultiple();
    $this->assertCount(1, $waivers);
  }

}
