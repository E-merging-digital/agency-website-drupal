<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Drush\Commands;

use Drupal\emerging_digital_content\ContentSync\ContentSyncManager;
use Drupal\emerging_digital_content\ContentSync\ContentSyncReleaseManager;
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
    #[Autowire(service: 'emerging_digital_content.content_sync_release_manager')]
    private readonly ContentSyncReleaseManager $contentSyncReleaseManager,
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
    try {
      $report = $this->contentSyncManager->validateCatalog();
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

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
  #[CLI\Option(name: 'prune', description: 'Prune mode for --all. Supported value: unpublish.')]
  #[CLI\Usage(
    name: 'drush emerging:content-sync --all --dry-run',
    description: 'Preview every catalog content item without saving content.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync --all',
    description: 'Create or update every managed content item declared in the catalog.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync --all --prune=unpublish --dry-run',
    description: 'Preview managed nodes absent from the catalog that would be unpublished.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync --all --prune=unpublish',
    description: 'Unpublish managed nodes absent from the catalog after applying the catalog.',
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
      'prune' => '',
    ],
  ): int {
    $all = (bool) ($options['all'] ?? FALSE);
    $dry_run = (bool) ($options['dry-run'] ?? FALSE);
    $raw_prune = $options['prune'] ?? '';
    $prune = is_string($raw_prune) ? $raw_prune : (string) $raw_prune;

    try {
      $report = $this->contentSyncManager->sync($content_id, $dry_run, $all, $prune);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->printReport(
      $report,
      $this->reportTitle($dry_run, $all, $prune),
    );

    return $this->reportList($report, 'errors') === []
      ? self::EXIT_SUCCESS
      : self::EXIT_FAILURE;
  }

  /**
   * Releases one grandfathered mapping from Git governance.
   */
  #[CLI\Command(name: 'emerging:content-sync:release')]
  #[CLI\Argument(name: 'content_id', description: 'Grandfathered Content Sync business identifier to release.')]
  #[CLI\Option(name: 'dry-run', description: 'Explicitly preview the release. This is also the safe default.')]
  #[CLI\Option(name: 'apply', description: 'Apply the release mapping transition. Required for any mutation.')]
  #[CLI\Usage(
    name: 'drush emerging:content-sync:release services/drupal --dry-run',
    description: 'Preview release of a grandfathered mapping without changing Drupal or its mapping.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync:release services/drupal --apply',
    description: 'Mark the mapping released without deleting, unpublishing or rewriting its Drupal entity.',
  )]
  public function release(
    string $content_id,
    array $options = [
      'dry-run' => FALSE,
      'apply' => FALSE,
    ],
  ): int {
    try {
      $dry_run = $this->lifecycleDryRun($options);
      $report = $this->contentSyncReleaseManager->release($content_id, $dry_run);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->printLifecycleReport($report, 'Content Sync governed release');

    return $this->reportList($report, 'errors') === []
      ? self::EXIT_SUCCESS
      : self::EXIT_FAILURE;
  }

  /**
   * Explicitly readmits one released mapping after catalog restoration.
   */
  #[CLI\Command(name: 'emerging:content-sync:readmit')]
  #[CLI\Argument(name: 'content_id', description: 'Released Content Sync business identifier to readmit.')]
  #[CLI\Option(name: 'dry-run', description: 'Explicitly preview the readmission. This is also the safe default.')]
  #[CLI\Option(name: 'apply', description: 'Apply the readmission mapping transition. Required for any mutation.')]
  #[CLI\Usage(
    name: 'drush emerging:content-sync:readmit services/drupal --dry-run',
    description: 'Preview explicit readmission after restoring the content to the catalog.',
  )]
  #[CLI\Usage(
    name: 'drush emerging:content-sync:readmit services/drupal --apply',
    description: 'Return a released mapping to active after the content has been explicitly restored to the catalog.',
  )]
  public function readmit(
    string $content_id,
    array $options = [
      'dry-run' => FALSE,
      'apply' => FALSE,
    ],
  ): int {
    try {
      $dry_run = $this->lifecycleDryRun($options);
      $report = $this->contentSyncReleaseManager->readmit($content_id, $dry_run);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->printLifecycleReport($report, 'Content Sync explicit readmission');

    return $this->reportList($report, 'errors') === []
      ? self::EXIT_SUCCESS
      : self::EXIT_FAILURE;
  }

  /**
   * Returns the command report title.
   */
  private function reportTitle(bool $dry_run, bool $all, string $prune): string {
    if ($prune !== '') {
      return $dry_run
        ? 'Content Sync prune unpublish read-only dry-run'
        : 'Content Sync prune unpublish apply';
    }

    if ($dry_run) {
      return $all
        ? 'Content Sync full catalog read-only dry-run'
        : 'Content Sync catalog read-only dry-run';
    }

    return $all ? 'Content Sync full catalog apply' : 'Content Sync targeted apply';
  }

  /**
   * Resolves lifecycle command safety options.
   *
   * No option means dry-run. Mutation always requires --apply.
   *
   * @param array<string, mixed> $options
   *   Drush command options.
   */
  private function lifecycleDryRun(array $options): bool {
    $explicit_dry_run = (bool) ($options['dry-run'] ?? FALSE);
    $apply = (bool) ($options['apply'] ?? FALSE);

    if ($explicit_dry_run && $apply) {
      throw new \InvalidArgumentException('Choose either --dry-run or --apply, not both.');
    }

    return !$apply;
  }

  /**
   * Prints a compact mapping lifecycle report.
   *
   * @param array<string, mixed> $report
   *   Structured lifecycle report.
   * @param string $title
   *   Report title.
   */
  private function printLifecycleReport(array $report, string $title): void {
    $this->logger()->notice($title);
    $this->logger()->notice(sprintf('Content ID: %s', (string) ($report['content_id'] ?? '')));
    $this->logger()->notice(sprintf('Dry-run: %s', ($report['dry_run'] ?? FALSE) ? 'true' : 'false'));
    $this->logger()->notice(sprintf('Mapping status: %s', (string) ($report['mapping_status'] ?? 'unknown')));

    if ((string) ($report['mapped_entity'] ?? '') !== '') {
      $this->logger()->notice(sprintf('Mapped entity: %s', (string) $report['mapped_entity']));
    }
    if ((string) ($report['mapped_uuid'] ?? '') !== '') {
      $this->logger()->notice(sprintf('Mapped UUID: %s', (string) $report['mapped_uuid']));
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

    foreach ($this->reportList($report, 'content_reports') as $content_report) {
      if (!is_array($content_report)) {
        continue;
      }

      $translations = $content_report['translations'] ?? [];
      if (isset($content_report['planned_operation'])) {
        $this->logger()->notice(sprintf(
          ' - content report: %s | %s:%s | %s | mapping %s%s | translations %s | hash %s',
          (string) ($content_report['id'] ?? ''),
          (string) ($content_report['entity_type'] ?? ''),
          (string) ($content_report['bundle'] ?? ''),
          (string) $content_report['planned_operation'],
          (string) ($content_report['mapping_status'] ?? ''),
          (string) ($content_report['mapped_entity'] ?? '') !== ''
            ? ' ' . (string) $content_report['mapped_entity']
            : '',
          is_array($translations) ? implode(', ', $translations) : '',
          (string) ($content_report['catalog_hash'] ?? ''),
        ));
        continue;
      }

      $this->logger()->notice(sprintf(
        ' - content report: %s | actions %d | warnings %d | errors %d',
        (string) ($content_report['id'] ?? ''),
        count($this->reportList($content_report, 'actions')),
        count($this->reportList($content_report, 'warnings')),
        count($this->reportList($content_report, 'errors')),
      ));
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

    $this->printSummary($report);
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

  /**
   * Prints the final structured summary at the end of the report.
   *
   * @param array<string, mixed> $report
   *   Structured report.
   */
  private function printSummary(array $report): void {
    if (!isset($report['summary']) || !is_array($report['summary'])) {
      return;
    }

    $summary = $report['summary'];
    $this->logger()->notice('Final summary:');
    foreach ($summary as $key => $value) {
      $this->logger()->notice(sprintf(
        ' - %s: %s',
        (string) $key,
        $this->formatSummaryValue($value),
      ));
    }
  }

  /**
   * Formats a scalar summary value for CLI output.
   */
  private function formatSummaryValue(mixed $value): string {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }

    if (is_scalar($value)) {
      return (string) $value;
    }

    return json_encode($value) ?: '';
  }

}
