<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\StackMiddleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Keeps unpublished NL-BE content private before Drupal's anonymous page cache.
 *
 * Core controls access to the ordinary entity edit forms. No published Dutch
 * pages are admitted until the separate human visibility gate is completed.
 */
final class NlVisibilityGuard implements HttpKernelInterface {

  public function __construct(
    private readonly HttpKernelInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    $path = $request->getPathInfo();

    // Delegate only native edit and private-preview URLs to Drupal Core.
    $native_edit = (bool) preg_match('@^/nl/node/[0-9]+/edit/?$@D', $path);
    $preview_pattern = '@^/nl/node/preview/'
      . '[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/'
      . '[a-z0-9_]+/?$@D';
    $native_preview = (bool) preg_match($preview_pattern, $path);

    if (
      ($path === '/nl' || str_starts_with($path, '/nl/'))
      && !$native_edit
      && !$native_preview
    ) {
      return new Response('', Response::HTTP_NOT_FOUND, [
        'Cache-Control' => 'private, no-store',
        'X-Agency-NL-Guard' => 'blocked',
      ]);
    }

    // Native edit and preview routes retain Drupal Core access checks.
    return $this->inner->handle($request, $type, $catch);
  }

}
