<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\block\Entity\Block;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Vérifie le rendu de la région header_language du thème.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class LanguageSwitcherAliasTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'emerging_digital';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Block::create([
      'id' => 'test_header_language_region',
      'theme' => $this->defaultTheme,
      'region' => 'header_language',
      'plugin' => 'system_powered_by_block',
      'weight' => 0,
      'visibility' => [],
      'settings' => [
        'id' => 'system_powered_by_block',
        'label' => 'Header language test block',
        'label_display' => FALSE,
        'provider' => 'system',
      ],
    ])->save();

    $block = Block::load('test_header_language_region');
    self::assertNotNull($block);
    self::assertSame('header_language', $block->getRegion());
    self::assertSame($this->defaultTheme, $block->getTheme());

    drupal_flush_all_caches();
  }

  /**
   * Vérifie que la région header_language est bien rendue dans la page.
   */
  public function testHeaderLanguageRegionRendersBlock(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('block-test-header-language-region');
    $this->assertSession()->responseContains('Powered by');
  }

}
