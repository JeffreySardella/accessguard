<?php

namespace Drupal\Tests\accessguard\Kernel;

use Drupal\accessguard\Service\ScanAccessToken;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the signed scan-access token grant for unpublished nodes.
 *
 * @group accessguard
 */
#[RunTestsInSeparateProcesses]
class ScanTokenAccessTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<int, string>
   */
  protected static $modules = ['accessguard', 'node', 'user', 'system', 'field', 'text', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('accessguard_scan');
    $this->installEntitySchema('accessguard_violation');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'filter', 'node', 'accessguard']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // The scanner fetches pages as an anonymous client; like on a real site,
    // anonymous needs 'access content' before any node view access exists.
    if (!Role::load(AccountInterface::ANONYMOUS_ROLE)) {
      Role::create(['id' => AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])->save();
    }
    Role::load(AccountInterface::ANONYMOUS_ROLE)->grantPermission('access content')->save();
  }

  /**
   * Checks anonymous view access with a given token in the request.
   */
  private function anonymousViewAccess(Node $node, ?string $token): bool {
    $request = Request::create('/node/' . $node->id(), 'GET', $token === NULL ? [] : [ScanAccessToken::QUERY_KEY => $token]);
    \Drupal::requestStack()->push($request);
    try {
      // Entity access results are statically cached per account; reset so
      // each check sees the current request.
      \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
      return $node->access('view', new AnonymousUserSession());
    }
    finally {
      \Drupal::requestStack()->pop();
    }
  }

  /**
   * Tests that a valid token grants anonymous view of an unpublished node.
   */
  public function testValidTokenGrantsViewOnUnpublishedNode(): void {
    $node = Node::create(['type' => 'page', 'title' => 'draft', 'status' => 0]);
    $node->save();

    $this->assertFalse($this->anonymousViewAccess($node, NULL), 'Without a token, anonymous cannot view an unpublished node.');

    $token = \Drupal::service('accessguard.scan_access_token')->generate((int) $node->id());
    $this->assertTrue($this->anonymousViewAccess($node, $token), 'A valid token grants view access.');
  }

  /**
   * Tests that a token is bound to its node id.
   */
  public function testTokenForAnotherNodeIsRejected(): void {
    $node = Node::create(['type' => 'page', 'title' => 'draft', 'status' => 0]);
    $node->save();
    $other = Node::create(['type' => 'page', 'title' => 'other draft', 'status' => 0]);
    $other->save();

    $tokenForOther = \Drupal::service('accessguard.scan_access_token')->generate((int) $other->id());
    $this->assertFalse($this->anonymousViewAccess($node, $tokenForOther), 'A token minted for one node grants nothing on another.');
  }

  /**
   * Tests that garbage and tampered tokens are rejected.
   */
  public function testTamperedTokenIsRejected(): void {
    $node = Node::create(['type' => 'page', 'title' => 'draft', 'status' => 0]);
    $node->save();
    $nid = (int) $node->id();
    /** @var \Drupal\accessguard\Service\ScanAccessToken $service */
    $service = \Drupal::service('accessguard.scan_access_token');

    $this->assertFalse($this->anonymousViewAccess($node, 'garbage'));

    // Extending the expiry invalidates the signature.
    [$expires, $sig] = explode('.', $service->generate($nid), 2);
    $tampered = ($expires + 9999) . '.' . $sig;
    $this->assertFalse($service->validate($nid, $tampered));
    $this->assertFalse($this->anonymousViewAccess($node, $tampered));
  }

  /**
   * Tests that a token grants view only — never update or delete.
   */
  public function testTokenDoesNotGrantOtherOperations(): void {
    $node = Node::create(['type' => 'page', 'title' => 'draft', 'status' => 0]);
    $node->save();
    $token = \Drupal::service('accessguard.scan_access_token')->generate((int) $node->id());

    $request = Request::create('/node/' . $node->id(), 'GET', [ScanAccessToken::QUERY_KEY => $token]);
    \Drupal::requestStack()->push($request);
    try {
      \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
      $anon = new AnonymousUserSession();
      $this->assertFalse($node->access('update', $anon));
      $this->assertFalse($node->access('delete', $anon));
    }
    finally {
      \Drupal::requestStack()->pop();
    }
  }

}
