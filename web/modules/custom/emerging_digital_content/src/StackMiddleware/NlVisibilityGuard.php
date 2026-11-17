<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Rejects unapproved Dutch pages before Drupal's anonymous page cache.
 *
 * Only a published NL translation of a page may be served on /nl. Editorial
 * drafts, unresolved aliases and non-page routes remain private by default.
 */
final class NlVisibilityGuard implements HttpKernelInterface {

  public function __construct(
    private readonly HttpKernelInterface $inner,
    private readonly AliasRepositoryInterface $aliasRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    $path = $request->getPathInfo();

    // Native edit and preview routes remain guarded by Drupal Core.
    $native_edit = (bool) preg_match('@^/nl/node/[0-9]+/edit/?$@D', $path);
    $preview_pattern = '@^/nl/node/preview/'
      . '[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/'
      . '[a-z0-9_]+/?$@D';
    $native_preview = (bool) preg_match($preview_pattern, $path);

    if (($path !== '/nl' && !str_starts_with($path, '/nl/'))
      || $native_edit || $native_preview) {
      return $this->inner->handle($request, $type, $catch);
    }

    // This runs outside the page cache: reevaluate entity publication on every
    // request, so an unpublished translation cannot reuse a cached NL response.
    try {
      if ($this->isPublishedDutchPage($path)) {
        return $this->inner->handle($request, $type, $catch);
      }
    }
    catch (\Throwable) {
      // An unavailable Drupal service is never permission to expose fallback.
    }

    return new Response('', Response::HTTP_NOT_FOUND, [
      'Cache-Control' => 'private, no-store',
      'X-Agency-NL-Guard' => 'blocked',
    ]);
  }

  /**
   * Checks the canonical node, never the current-language fallback.
   */
  private function isPublishedDutchPage(string $path): bool {
    // An NL node cannot be exposed if URL language negotiation is inactive.
    $negotiation = $this->configFactory->get('language.negotiation');
    if ($negotiation->get('url.source') !== 'path_prefix'
      || $negotiation->get('url.prefixes.nl') !== 'nl'
      || $this->configFactory->get('language.entity.nl')->get('status') !== TRUE) {
      return FALSE;
    }

    $relative = substr($path, 3);
    if ($relative === '' || $relative === '/') {
      $target = $this->configFactory->get('system.site')->get('page.front');
    }
    elseif (preg_match('@^/node/[1-9][0-9]*$@D', $relative)) {
      // Canonical node path: publication is still checked below.
      $target = $relative;
    }
    else {
      if (!str_starts_with($relative, '/') || str_contains($relative, '%')) {
        return FALSE;
      }
      // Core's repository may fall back to und: require a real NL record,
      // with an exact requested alias, before delegating to Drupal.
      $record = $this->aliasRepository->lookupByAlias($relative, 'nl');
      if (!is_array($record)
        || ($record['langcode'] ?? NULL) !== 'nl'
        || ($record['alias'] ?? NULL) !== $relative) {
        return FALSE;
      }
      $target = $record['path'] ?? NULL;
    }

    if (!is_string($target) || !preg_match('@^/node/([1-9][0-9]*)$@D', $target, $matches)) {
      return FALSE;
    }

    $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
    return $node instanceof NodeInterface
      && $node->bundle() === 'page'
      && $node->hasTranslation('nl')
      && $node->getTranslation('nl')->isPublished();
  }

}
