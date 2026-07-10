<?php

namespace Drupal\accessguard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;

/**
 * Grants the scanner short-lived view access to unpublished nodes.
 *
 * Scans run as an anonymous HTTP client, so an unpublished node would serve
 * the scanner a 403 page — meaning unpublished content could never be
 * re-scanned, and a node blocked by the publish gate could never clear it
 * (fix, save, re-scan). A signed, expiring token in the scan URL lets
 * hook_node_access() grant view access for exactly that node, for the
 * duration of one scan window, without opening unpublished content to anyone
 * who does not hold the token.
 *
 * The token is an HMAC over the node id and an expiry timestamp, keyed by the
 * site's private key + hash salt, so it cannot be forged or transplanted onto
 * another node.
 */
class ScanAccessToken {

  /**
   * Query parameter carrying the token on scan URLs.
   */
  public const QUERY_KEY = 'accessguard-scan-token';

  /**
   * How long a token stays valid, in seconds.
   *
   * Long enough to survive queue latency between URL construction and the
   * scanner fetching the page; short enough that a leaked URL goes stale.
   */
  protected const LIFETIME = 3600;

  public function __construct(
    protected PrivateKey $privateKey,
    protected TimeInterface $time,
  ) {}

  /**
   * Builds the URL the scanner should fetch for a node.
   *
   * Published nodes get their plain canonical URL. Unpublished nodes get a
   * token appended so the scan sees the real content instead of a 403 page.
   */
  public function buildScanUrl(NodeInterface $node): string {
    $options = ['absolute' => TRUE];
    if (!$node->isPublished()) {
      $options['query'][self::QUERY_KEY] = $this->generate((int) $node->id());
    }
    return $node->toUrl('canonical', $options)->toString();
  }

  /**
   * Generates a token granting view access to one node until expiry.
   */
  public function generate(int $nid): string {
    $expires = $this->time->getRequestTime() + self::LIFETIME;
    return $expires . '.' . $this->hmac($nid, $expires);
  }

  /**
   * Validates a presented token for a node id.
   */
  public function validate(int $nid, string $token): bool {
    if (!preg_match('/^(\d+)\.([A-Za-z0-9_-]+)$/', $token, $matches)) {
      return FALSE;
    }
    $expires = (int) $matches[1];
    if ($expires < $this->time->getRequestTime()) {
      return FALSE;
    }
    return hash_equals($this->hmac($nid, $expires), $matches[2]);
  }

  /**
   * Computes the keyed signature binding a node id to an expiry time.
   */
  protected function hmac(int $nid, int $expires): string {
    return Crypt::hmacBase64("accessguard_scan:$nid:$expires", $this->privateKey->get() . Settings::getHashSalt());
  }

}
