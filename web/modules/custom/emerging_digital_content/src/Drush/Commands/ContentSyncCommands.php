<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Drush\Commands;

use Drupal\emerging_digital_content\ContentSync\ContentSyncManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for targeted content synchronization.
 */
final class ContentSyncCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'emerging_digital_content.content_sync_manager')]
    private readonly ContentSyncManager $contentSyncManager,
  ) {
    parent::__construct();
  }

  /**
   * Creates or updates one versioned content item by business identifier.
   */
  #[CLI\Command(name: 'emerging:content-sync')]
  #[CLI\Argument(name: 'content_id', description: 'Stable business identifier, for example agence-drupal-belgique.')]
  #[CLI\Option(name: 'dry-run', description: 'Preview actions without saving content.')]
  #[CLI\Usage(name: 'drush emerging:content-sync agence-drupal-belgique --dry-run', description: 'Preview the synchronization.')]
  #[CLI\Usage(name: 'drush emerging:content-sync agence-drupal-belgique', description: 'Apply the synchronization.')]
  public function sync(string $content_id, array $options = ['dry-run' => FALSE]): int {
    try {
      $report = $this->contentSyncManager->sync($content_id, (bool) $options['dry-run']);
    }
    catch (\InvalidArgumentException $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->logger()->notice(sprintf(
      '%s content sync for "%s".',
      $report['dry_run'] ? 'Dry-run' : 'Applied',
      $report['content_id'],
    ));

    foreach ($report['actions'] as $action) {
      $this->logger()->notice(' - ' . $action);
    }

    foreach ($report['warnings'] as $warning) {
      $this->logger()->warning(' - ' . $warning);
    }

    if (!empty($report['node_id'])) {
      $this->logger()->notice(sprintf('Target node: nid %s, uuid %s.', $report['node_id'], $report['node_uuid']));
    }

    if ($report['menus_touched'] === FALSE) {
      $this->logger()->notice('Menu entities untouched.');
    }

    return self::EXIT_SUCCESS;
  }

}
