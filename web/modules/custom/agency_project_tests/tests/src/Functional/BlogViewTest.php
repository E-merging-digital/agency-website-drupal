<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the public Blog view contract.
 *
 * @group agency_project_tests
 * @group blog
 */
#[RunTestsInSeparateProcesses]
#[Group('blog')]
final class BlogViewTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Verifies published filtering, ordering and pagination on /blog.
   */
  public function testBlogListsPublishedArticlesWithPager(): void {
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
      'new_revision' => TRUE,
      'display_submitted' => TRUE,
    ])->save();

    $definition = Yaml::parseFile(DRUPAL_ROOT . '/../config/sync/views.view.blog.yml');
    if (!is_array($definition)) {
      throw new \RuntimeException('The Blog view configuration must be a YAML mapping.');
    }

    unset($definition['uuid']);
    View::create($definition)->save();
    \Drupal::service('router.builder')->rebuild();

    Node::create([
      'type' => 'article',
      'title' => 'Oldest published article',
      'status' => 1,
      'created' => 1000,
    ])->save();

    for ($index = 2; $index <= 10; $index++) {
      Node::create([
        'type' => 'article',
        'title' => sprintf('Published article %02d', $index),
        'status' => 1,
        'created' => 1000 + $index,
      ])->save();
    }

    Node::create([
      'type' => 'article',
      'title' => 'Draft article must stay hidden',
      'status' => 0,
      'created' => 2000,
    ])->save();

    $this->drupalGet('/blog');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.view-blog');
    $this->assertSession()->pageTextContains('Published article 10');
    $this->assertSession()->pageTextNotContains('Oldest published article');
    $this->assertSession()->pageTextNotContains('Draft article must stay hidden');
    $this->assertSession()->elementExists('css', '.pager');

    $this->drupalGet('/blog', ['query' => ['page' => 1]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Oldest published article');
    $this->assertSession()->pageTextNotContains('Draft article must stay hidden');
  }

}
