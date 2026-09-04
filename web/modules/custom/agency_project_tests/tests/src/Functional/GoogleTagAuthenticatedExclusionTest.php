<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\google_tag\Entity\TagContainer;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the PROD-like GA4 audience boundary with native Google Tag conditions.
 *
 * @group agency_project_tests
 * @group google_tag_environment_policy
 */
#[RunTestsInSeparateProcesses]
final class GoogleTagAuthenticatedExclusionTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'google_tag',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A representative public page.
   */
  private Node $page;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create([
      'type' => 'page',
      'name' => 'Basic page',
    ])->save();

    TagContainer::create([
      'id' => 'G-K5TDNZCPTY.69f8b7287a84a3.47771255',
      'label' => 'G-K5TDNZCPTY',
      'status' => TRUE,
      'weight' => 0,
      'tag_container_ids' => [
        'G-K5TDNZCPTY',
        '',
      ],
      'advanced_settings' => [
        'consent_mode' => FALSE,
      ],
      'dimensions_metrics' => [],
      'conditions' => [
        'user_role' => [
          'id' => 'user_role',
          'negate' => FALSE,
          'context_mapping' => [
            'user' => '@user.current_user_context:current_user',
          ],
          'roles' => [
            'anonymous' => 'anonymous',
          ],
        ],
      ],
      'events' => [],
    ])->save();

    $this->config('google_tag.settings')
      ->set('default_google_tag_entity', 'G-K5TDNZCPTY.69f8b7287a84a3.47771255')
      ->save();

    $this->page = Node::create([
      'type' => 'page',
      'title' => 'Analytics audience proof',
      'status' => Node::PUBLISHED,
    ]);
    $this->page->save();

    drupal_flush_all_caches();
  }

  /**
   * Anonymous PROD-like traffic remains eligible for the configured GA4 tag.
   */
  public function testAnonymousPageContainsGa4Injection(): void {
    $this->drupalGet($this->page->toUrl());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('G-K5TDNZCPTY');
  }

  /**
   * Every authenticated account is excluded regardless of its eventual role.
   */
  public function testAuthenticatedPageContainsNoGa4Injection(): void {
    $account = $this->drupalCreateUser();
    self::assertNotFalse($account);
    $this->drupalLogin($account);

    $this->drupalGet($this->page->toUrl());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('G-K5TDNZCPTY');
    $this->assertSession()->responseNotContains('googletagmanager.com');
    $this->assertSession()->responseNotContains('google-analytics.com');
  }

}
