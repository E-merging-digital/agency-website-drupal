<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\emerging_digital_content\Plugin\Block\LanguageSwitcherBlock;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Tests the existing switcher's entityless front-page fallback.
 *
 * @group agency_project_tests
 */
final class LanguageSwitcherFrontPageTest extends TestCase {

  /**
   * The home page may expose no route entity despite targeting /node/5.
   */
  public function testFrontPageOnlyResolvesConfiguredNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::once())->method('load')->with('5')->willReturn($node);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects(self::once())->method('getStorage')->with('node')->willReturn($storage);

    $block = $this->makeBlock(TRUE, '/node/5', $manager);
    $entities = (new \ReflectionMethod($block, 'getRouteContentEntities'))->invoke($block);
    self::assertSame([$node], $entities);
  }

  /**
   * Other entityless routes and non-node front targets stay unadvertised.
   */
  public function testNonNodeOrNonFrontRoutesDoNotResolveEntity(): void {
    foreach ([[FALSE, '/node/5'], [TRUE, '/contact']] as [$is_front, $front]) {
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $manager->expects(self::never())->method('getStorage');
      $block = $this->makeBlock($is_front, $front, $manager);
      self::assertSame(
        [],
        (new \ReflectionMethod($block, 'getRouteContentEntities'))->invoke($block),
      );
      self::assertFalse(
        (new \ReflectionMethod($block, 'hasRouteEntityTranslation'))->invoke($block, [], 'nl'),
      );
    }
  }

  /**
   * The published native Blog View is the only entityless NL exception.
   */
  public function testBlogListingCanOfferDutchWithoutAContentEntity(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $block = $this->makeBlock(FALSE, '/node/5', $manager, 'view.blog.page_1');
    $filter = new \ReflectionMethod($block, 'hasRouteEntityTranslation');

    self::assertTrue($filter->invoke($block, [], 'nl'));
    self::assertTrue($filter->invoke($block, [], 'fr'));
    self::assertTrue($filter->invoke($block, [], 'en'));

    $unrelated = $this->makeBlock(FALSE, '/node/5', $manager, 'view.other.page_1');
    self::assertFalse($filter->invoke($unrelated, [], 'nl'));
  }

  /**
   * Draft Dutch translations stay hidden, published ones can be selected.
   */
  public function testOnlyPublishedDutchTranslationIsEligible(): void {
    $block = $this->makeBlock(FALSE, '/node/5', $this->createMock(EntityTypeManagerInterface::class));
    $filter = new \ReflectionMethod($block, 'hasRouteEntityTranslation');

    foreach ([FALSE, TRUE] as $published) {
      $translation = $this->createMock(NodeInterface::class);
      $translation->method('isPublished')->willReturn($published);
      $node = $this->createMock(NodeInterface::class);
      $node->method('isTranslatable')->willReturn(TRUE);
      $node->method('hasTranslation')->willReturnCallback(
        static fn (string $langcode): bool => in_array($langcode, ['fr', 'en', 'nl'], TRUE),
      );
      $node->method('getTranslation')->with('nl')->willReturn($translation);

      self::assertSame($published, $filter->invoke($block, [$node], 'nl'));
      self::assertTrue($filter->invoke($block, [$node], 'fr'));
      self::assertTrue($filter->invoke($block, [$node], 'en'));
    }
  }

  /**
   * Builds an isolated switcher with no Drupal runtime or credentials.
   */
  private function makeBlock(bool $is_front, string $front, EntityTypeManagerInterface $manager, ?string $route_name = NULL): LanguageSwitcherBlock {
    $path_matcher = $this->createMock(PathMatcherInterface::class);
    $path_matcher->method('isFrontPage')->willReturn($is_front);

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')->willReturn(new ParameterBag());
    $route_match->method('getRouteName')->willReturn($route_name);

    $site_config = $this->createMock(ImmutableConfig::class);
    $site_config->method('get')->with('page.front')->willReturn($front);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('system.site')->willReturn($site_config);

    return new LanguageSwitcherBlock(
      [],
      'emerging_digital_language_switcher',
      ['provider' => 'emerging_digital_content'],
      $this->createMock(LanguageManagerInterface::class),
      $path_matcher,
      $route_match,
      $factory,
      $manager,
    );
  }

}
