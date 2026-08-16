<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Centralizes the minimum access contract for node AI translation.
 */
final class AiTranslationAccess {

  /**
   * Returns whether an account may trigger AI translation for a node.
   */
  public static function allowed(NodeInterface $node, AccountInterface $account): bool {
    if (!$node->access('update', $account)) {
      return FALSE;
    }

    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }

    return $account->hasPermission('trigger ai translation')
      && $account->hasPermission(sprintf('translate %s node', $node->bundle()));
  }

}
