<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
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
    private readonly AliasManagerInterface $aliasManager,
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
    $relative = substr($path, 3);

    if ($relative === '' || $relative === '/') {
      $target = $this->configFactory->get('system.site')->get('page.front');
    }
    else {
      // Only paths resolved by Drupal's NL alias manager or canonical /node/N
      // routes are eligible. Never infer a translation from a FR/EN URL.
      if (!str_starts_with($relative, '/') || str_contains($relative, '%')) {
        return FALSE;
      }
      $target = $this->aliasManager->getPathByAlias($relative, 'nl');
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
