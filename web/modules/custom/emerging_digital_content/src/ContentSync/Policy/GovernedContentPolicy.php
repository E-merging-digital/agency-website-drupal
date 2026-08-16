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
    'ai-features/agent-workflows',
    'ai-features/brief-wizard',
    'ai-features/chatbot',
    'ai-features/content-assistant',
    'ai-features/document-search',
    'ai-features/observability',
    'ai-features/privacy-guardrails',
    'ai-features/rewrite-blocks',
    'ai-features/semantic-search',
    'ai-features/seo-assistant',
    'cas-clients/industrie-site-haute-disponibilite',
    'cas-clients/plateforme-contenus-api-first',
    'cas-clients/refonte-drupal-b2b',
    'cas-clients',
    'contact',
    'equipe',
    'homepage',
    'ia-drupal',
    'services/architecture',
    'services/communication',
    'services/contenus',
    'services/drupal',
    'services/hebergement',
    'services/ia-drupal',
    'services/infogerance',
    'services/migration',
    'services/sauvegardes',
    'services/securite',
    'services/seo',
    'services/support',
    'services/formation',
    'services/web',
    'services',
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
