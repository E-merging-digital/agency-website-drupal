<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Drush\Commands;

use Drupal\emerging_digital_content\ContentSync\ContentSyncManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Content Sync catalog checks and writes.
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
   * Validates the versioned Content Sync catalog.
   */
  #[CLI\Command(name: 'emerging:content-sync:validate')]
  #[CLI\Usage(
    name: 'drush emerging:content-sync:validate',
    description: 'Validate the Content Sync catalog without writing entities.',
  )]
  public function validate(): int {
    $report = $this->contentSyncManager->validateCatalog();
    $this->printReport($report, 'Content Sync catalog validation');

    return $this->reportList($report, 'errors') === [] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Synchronizes versioned content by business identifier or full catalog.
   */
  #[CLI\Command(name: 'emerging:content-sync')]
  #[CLI\Argument(name: 'content_id', description: 'Optional stable business identifier, for example agence-drupal-belgique.')]
  #[CLI\Option(name: 'all', description: 'Synchronize every content item declared in the catalog.')]
  #[CLI\Option(name: 'dry-run', description: 'Read and report only without writing entities or mappings.')]
  #[CLI\Usage(
    name: 'drush emerging:content-sync --all --dry-run',
    description: 'Preview every catalog content item without saving content.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync --all',
    description: 'Create or update every managed content item declared in the catalog.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync agence-drupal-belgique --dry-run',
    description: 'Preview catalog reading without saving content.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync agence-drupal-belgique',
    description: 'Create or update the targeted managed content item.',
  )]
  public function sync(
    string $content_id = '',
    array $options = [
      'all' => FALSE,
      'dry-run' => FALSE,
    ],
  ): int {
    $all = (bool) ($options['all'] ?? FALSE);
    $dry_run = (bool) ($options['dry-run'] ?? FALSE);

    try {
      $report = $this->contentSyncManager->sync($content_id, $dry_run, $all);
    }
    catch (\InvalidArgumentException $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->printReport(
      $report,
      $this->reportTitle($dry_run, $all),
    );

    return $this->reportList($report, 'errors') === []
      ? self::EXIT_SUCCESS
      : self::EXIT_FAILURE;
  }

  /**
   * Returns the command report title.
   */
  private function reportTitle(bool $dry_run, bool $all): string {
    if ($dry_run) {
      return $all
        ? 'Content Sync full catalog read-only dry-run'
        : 'Content Sync catalog read-only dry-run';
    }

    return $all ? 'Content Sync full catalog apply' : 'Content Sync targeted apply';
  }

  /**
   * Prints a compact structured report.
   *
   * @param array<string, mixed> $report
   *   Structured report.
   * @param string $title
   *   Report title.
   */
  private function printReport(array $report, string $title): void {
    $this->logger()->notice($title);
    $this->logger()->notice(sprintf('Contents found: %d', (int) $report['contents_found']));
    $this->logger()->notice(sprintf(
      'Valid contents: %d',
      count($this->reportList($report, 'valid_contents')),
    ));
    $this->logger()->notice(sprintf(
      'Invalid contents: %d',
      count($this->reportList($report, 'invalid_contents')),
    ));

    foreach ($this->reportList($report, 'valid_contents') as $content) {
      if (!is_array($content)) {
        continue;
      }

      $this->logger()->notice(sprintf(
        ' - valid: %s (%s:%s)',
        (string) ($content['id'] ?? ''),
        (string) ($content['entity_type'] ?? ''),
        (string) ($content['bundle'] ?? ''),
      ));
    }

    foreach ($this->reportList($report, 'actions') as $action) {
      $this->logger()->notice(' - ' . (string) $action);
    }

    foreach ($this->reportList($report, 'warnings') as $warning) {
      $this->logger()->warning(' - ' . (string) $warning);
    }

    foreach ($this->reportList($report, 'errors') as $error) {
      $this->logger()->error(' - ' . (string) $error);
    }

    if (($report['menus_touched'] ?? FALSE) === FALSE) {
      $this->logger()->notice('Menu entities untouched.');
    }
  }

  /**
   * Returns a report list safely.
   *
   * @param array<string, mixed> $report
   *   Structured report.
   * @param string $key
   *   Report list key.
   *
   * @return array<int|string, mixed>
   *   Report list.
   */
  private function reportList(array $report, string $key): array {
    return isset($report[$key]) && is_array($report[$key]) ? $report[$key] : [];
  }

}
