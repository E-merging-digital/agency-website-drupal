<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a cacheable language switcher block.
 */
#[Block(
  id: 'emerging_digital_language_switcher',
  admin_label: new TranslatableMarkup('Language switcher'),
  category: new TranslatableMarkup('Emerging Digital'),
)]
final class LanguageSwitcherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LanguageManagerInterface $languageManager,
    protected PathMatcherInterface $pathMatcher,
    protected RouteMatchInterface $routeMatch,
    protected ConfigFactoryInterface $configFactory,
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
      $container->get('language_manager'),
      $container->get('path.matcher'),
      $container->get('current_route_match'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = [];
    $cache_metadata = (new CacheableMetadata())
      ->addCacheContexts([
        'languages:' . LanguageInterface::TYPE_INTERFACE,
        'route',
        'url.path',
        'url.query_args',
        'url.site',
      ])
      ->addCacheTags(['config:configurable_language_list']);

    if (!$this->languageManager->isMultilingual()) {
      $cache_metadata->applyTo($build);
      return $build;
    }

    $switch_links = $this->languageManager->getLanguageSwitchLinks(
      LanguageInterface::TYPE_INTERFACE,
      $this->getCurrentRouteUrl(),
    );

    if (empty($switch_links->links)) {
      $cache_metadata->applyTo($build);
      return $build;
    }

    $current_langcode = $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)
      ->getId();
    $route_entities = $this->getRouteContentEntities();
    $negotiation = $this->configFactory->get('language.negotiation');
    $cache_metadata->addCacheableDependency($negotiation);
    $nl_prefix = $negotiation->get('url.prefixes.nl');

    foreach ($route_entities as $entity) {
      $cache_metadata->addCacheableDependency($entity);
    }

    $links = [];
    foreach ($switch_links->links as $langcode => $link) {
      // Hide NL until a reviewed, routable /nl prefix is explicitly enabled.
      if ($langcode === 'nl' && $nl_prefix !== 'nl') {
        continue;
      }
      if (!$this->hasRouteEntityTranslation($route_entities, $langcode)) {
        continue;
      }

      if (($link['url'] ?? NULL) instanceof Url) {
        $cache_metadata->addCacheableDependency($link['url']->access(NULL, TRUE));
      }

      $link['attributes']['class'][] = 'language-switcher__link';

      if ($langcode === $current_langcode) {
        unset($link['url']);
      }

      $links[$langcode] = $link;
    }

    if (!$links) {
      $cache_metadata->applyTo($build);
      return $build;
    }

    $build = [
      '#theme' => 'links__language_block',
      '#links' => $links,
      '#set_active_class' => TRUE,
    ];

    $cache_metadata->applyTo($build);
    return $build;
  }

  /**
   * Gets the URL object for the current route.
   */
  private function getCurrentRouteUrl(): Url {
    if ($this->pathMatcher->isFrontPage() || !$this->routeMatch->getRouteObject()) {
      return Url::fromRoute('<front>');
    }

    return Url::fromRouteMatch($this->routeMatch);
  }

  /**
   * Gets content entities from the active route parameters.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The content entities found in the current route.
   */
  private function getRouteContentEntities(): array {
    $entities = [];
    foreach ($this->routeMatch->getParameters()->all() as $parameter) {
      if ($parameter instanceof ContentEntityInterface) {
        $entities[] = $parameter;
      }
    }

    return $entities;
  }

  /**
   * Determines whether every route entity is available in the target language.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $entities
   *   The route content entities to check.
   * @param string $langcode
   *   The target language code.
   */
  private function hasRouteEntityTranslation(array $entities, string $langcode): bool {
    // Entityless routes (including <front>) cannot prove a published NL page.
    if ($langcode === 'nl' && !$entities) {
      return FALSE;
    }

    foreach ($entities as $entity) {
      if ($entity->isTranslatable() && !$entity->hasTranslation($langcode)) {
        return FALSE;
      }
      // A draft translation must never advertise a public Dutch destination.
      if ($langcode === 'nl' && $entity->isTranslatable()) {
        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof EntityPublishedInterface && !$translation->isPublished()) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

}
