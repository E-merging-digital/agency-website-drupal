<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Policy;

/**
 * Transitional admission and release policy for Governed Content.
 */
final class GovernedContentPolicy {

  /**
   * Content intentionally kept under Git governance.
   */
  public const GOVERNED_CONTENT_IDS = [
    'mentions-legales',
    'politique-confidentialite',
    'politique-cookies',
  ];

  /**
   * Ordinary content still waiting for a controlled release from Git.
   */
  public const LEGACY_RELEASE_PENDING_IDS = [
    'accessibilite-seo-optimisation',
    'agence-drupal-belgique',
    'agence-web-belgique',
    'agence-web-liege',
    'audit-drupal',
    'cas-clients',
    'contact',
    'creation-site-drupal',
    'creation-site-web-professionnel',
    'equipe',
    'homepage',
    'ia-drupal',
    'ia-integree',
    'ia-pour-pme',
    'maintenance-drupal',
    'migration-drupal',
    'refonte-site-drupal',
    'refonte-site-internet',
    'services',
    'site-web-pme',
  ];

  /**
   * Returns whether a content ID may be released from Git governance now.
   */
  public static function isReleasePending(string $content_id): bool {
    return in_array($content_id, self::LEGACY_RELEASE_PENDING_IDS, TRUE);
  }

  /**
   * Returns whether a content ID is intentionally Governed Content.
   */
  public static function isGoverned(string $content_id): bool {
    return in_array($content_id, self::GOVERNED_CONTENT_IDS, TRUE);
  }

}
