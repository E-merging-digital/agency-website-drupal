<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Plugin\Block;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a cacheable language switcher block.
 *
 * @Block(
 *   id = "emerging_digital_language_switcher",
 *   admin_label = @Translation("Language switcher"),
 *   category = @Translation("Emerging Digital")
 * )
 */
final class LanguageSwitcherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $prefixes = ['en' => 'en', 'fr' => 'fr'];
    $current_langcode = $this->getCurrentLangcode($prefixes);
    $links = [];

    foreach ($prefixes as $langcode => $prefix) {
      $links[$langcode] = [
        'label' => $this->getLanguageLabel($langcode),
        'href' => $this->buildLanguageHref($langcode, $prefixes),
        'hreflang' => $langcode,
        'active' => $langcode === $current_langcode,
      ];
    }

    return [
      '#markup' => $this->buildSwitcherMarkup($links),
      '#cache' => [
        'contexts' => [
          'url.path',
          'url.query_args',
        ],
      ],
    ];
  }

  /**
   * Builds the switcher markup from already escaped language link data.
   */
  private function buildSwitcherMarkup(array $links): MarkupInterface {
    $active_label = '';
    foreach ($links as $link) {
      if ($link['active']) {
        $active_label = $link['label'];
        break;
      }
    }

    $active_label = $active_label ?: (string) reset($links)['label'];
    $items = '';

    foreach ($links as $link) {
      $item_class = 'language-switcher__item' . ($link['active'] ? ' is-active' : '');
      if ($link['active']) {
        $items .= '<li class="' . Html::escape($item_class) . '"><span class="language-switcher__link is-active" aria-current="true">' . Html::escape($link['label']) . '</span></li>';
      }
      else {
        $items .= '<li class="' . Html::escape($item_class) . '"><a href="' . Html::escape($link['href']) . '" hreflang="' . Html::escape($link['hreflang']) . '">' . Html::escape($link['label']) . '</a></li>';
      }
    }

    return Markup::create(
      '<div class="language-switcher" data-language-switcher>' .
      '<button type="button" class="language-switcher__toggle" aria-expanded="false" aria-haspopup="true" aria-controls="language-switcher-menu">' .
      '<span class="language-switcher__current">' . Html::escape($active_label) . '</span>' .
      '<span class="language-switcher__icon" aria-hidden="true">&#9662;</span>' .
      '<span class="visually-hidden">Choisir la langue</span>' .
      '</button>' .
      '<ul id="language-switcher-menu" class="language-switcher__menu" hidden>' . $items . '</ul>' .
      '</div>'
    );
  }

  /**
   * Builds a language href without invoking Drupal URL generators.
   */
  private function buildLanguageHref(string $targetLangcode, array $prefixes): string {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];
    $target_path = $this->buildPrefixedPath($prefixes[$targetLangcode], $prefixes);
    $query_string = $query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';

    return $target_path . $query_string;
  }

  /**
   * Gets the current language from the first URL path segment.
   */
  private function getCurrentLangcode(array $prefixes): string {
    $request = $this->requestStack->getCurrentRequest();
    $first_segment = $request ? strtok(trim($request->getPathInfo(), '/'), '/') : '';
    $langcode = array_search($first_segment, $prefixes, TRUE);

    return is_string($langcode) ? $langcode : (string) array_key_first($prefixes);
  }

  /**
   * Builds the target path by replacing the current language prefix.
   */
  private function buildPrefixedPath(string $targetPrefix, array $prefixes): string {
    $request = $this->requestStack->getCurrentRequest();
    $path = $request ? trim($request->getPathInfo(), '/') : '';
    $segments = $path === '' ? [] : explode('/', $path);
    $current_langcode = $this->getCurrentLangcode($prefixes);

    if ($segments && $segments[0] === $prefixes[$current_langcode]) {
      array_shift($segments);
    }

    return '/' . $targetPrefix . ($segments ? '/' . implode('/', $segments) : '');
  }

  /**
   * Gets a short native language label for the configured site languages.
   */
  private function getLanguageLabel(string $langcode): string {
    return match ($langcode) {
      'fr' => 'Français',
      'en' => 'English',
      default => strtoupper($langcode),
    };
  }

}
