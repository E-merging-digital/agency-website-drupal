<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\Route;

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
    private readonly RequestMatcherInterface $routeMatcher,
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
    // Allow only Core's menu-link translation editor, not public NL fallbacks.
    $menu_translation_pattern = '@^/nl/admin/structure/menu/item/'
      . '[1-9][0-9]*/edit'
      . '(?:/translations(?:/(?:add/(?:fr|en)/nl|edit/nl))?)?/?$@D';
    $native_menu_translation = (bool) preg_match($menu_translation_pattern, $path);
    // Drupal Core owns access to native node translation forms.
    $node_translation_pattern = '@^/nl/node/[1-9][0-9]*/translations'
      . '(?:/(?:add/(?:fr|en)/nl|edit/nl))?/?$@D';
    $native_node_translation = (bool) preg_match($node_translation_pattern, $path);
    // Keep the Contact Webform edit/translation routes inside Core permissions.
    $contact_translation_pattern = '@^/nl/admin/structure/webform/manage/contact'
      . '(?:/translate(?:/(?:add/(?:fr|en)/nl|edit/nl|nl/(?:add|edit)))?)?/?$@D';
    $native_contact_translation = (bool) preg_match($contact_translation_pattern, $path);

    // Only real administrative routes can pass to Drupal's native permission
    // system. A path prefix alone must never grant access.
    $native_admin = $this->isNativeAdminRoute($request, $path);

    if (($path !== '/nl' && !str_starts_with($path, '/nl/'))
      || $native_edit || $native_preview || $native_menu_translation
      || $native_node_translation || $native_contact_translation
      || $native_admin) {
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
   * Delegates only real Drupal administrative routes to Core access checks.
   *
   * Route matching here only classifies the requested route; it does not
   * authorize any user or bypass Drupal's access manager. Unknown or
   * non-administrative routes remain blocked before the anonymous cache.
   */
  private function isNativeAdminRoute(Request $request, string $path): bool {
    if (!preg_match('@^/nl/admin(?:/|$)@D', $path)) {
      return FALSE;
    }

    try {
      $matched = $this->routeMatcher->matchRequest($request);
      $route = $matched[RouteObjectInterface::ROUTE_OBJECT] ?? NULL;
      return $route instanceof Route
        && $route->getOption('_admin_route') === TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
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
    if ($relative === '/blog') {
      // Only published articles in the active content language may appear.
      return $this->isSafeDutchBlogView();
    }
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
      && in_array($node->bundle(), ['page', 'article', 'service', 'case_client', 'ai_feature'], TRUE)
      && $node->hasTranslation('nl')
      && $node->getTranslation('nl')->isPublished();
  }

  /**
   * Only the native Blog View with strict language/publication filters is safe.
   */
  private function isSafeDutchBlogView(): bool {
    $view = $this->configFactory->get('views.view.blog');
    $displays = $view->get('display');
    if ($view->get('status') !== TRUE || !is_array($displays)) {
      return FALSE;
    }

    $page = $displays['page_1'] ?? [];
    $default = $displays['default']['display_options'] ?? [];
    $filters = $default['filters'] ?? [];
    $groups = $default['filter_groups'] ?? [];
    return ($page['display_plugin'] ?? NULL) === 'page'
      && ($page['display_options']['path'] ?? NULL) === 'blog'
      // Page-level overrides must not bypass the checked default filters.
      && !isset($page['display_options']['filters'])
      && !isset($page['display_options']['filter_groups'])
      && ($groups['operator'] ?? NULL) === 'AND'
      && ($groups['groups'][1] ?? NULL) === 'AND'
      && ($filters['status']['group'] ?? NULL) === 1
      && ($filters['status']['operator'] ?? NULL) === '='
      && ($filters['status']['exposed'] ?? TRUE) === FALSE
      && ($filters['status']['value'] ?? NULL) === '1'
      && ($filters['type']['group'] ?? NULL) === 1
      && ($filters['type']['operator'] ?? NULL) === 'in'
      && ($filters['type']['exposed'] ?? TRUE) === FALSE
      && ($filters['type']['value']['article'] ?? NULL) === 'article'
      && ($filters['langcode']['group'] ?? NULL) === 1
      && ($filters['langcode']['operator'] ?? NULL) === 'in'
      && ($filters['langcode']['exposed'] ?? TRUE) === FALSE
      && ($filters['langcode']['value']['***LANGUAGE_language_content***'] ?? NULL)
        === '***LANGUAGE_language_content***';
  }

}
