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

    if (
      ($path === '/nl' || str_starts_with($path, '/nl/'))
      && !preg_match('@^/nl/node/[0-9]+/edit/?$@D', $path)
    ) {
      return new Response('', Response::HTTP_NOT_FOUND, [
        'Cache-Control' => 'private, no-store',
        'X-Agency-NL-Guard' => 'blocked',
      ]);
    }

    // The Drupal node edit route must retain its normal authorization checks.
    return $this->inner->handle($request, $type, $catch);
  }

}
