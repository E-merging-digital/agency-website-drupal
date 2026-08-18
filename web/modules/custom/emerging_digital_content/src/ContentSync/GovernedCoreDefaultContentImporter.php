<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync;

use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;

/**
 * Imports repository-owned core Default Content used by governed composition.
 */
final class GovernedCoreDefaultContentImporter {

  private const CANVAS_PAGE_UUID = '52600000-0000-4000-8000-000000000001';

  private const CANVAS_COMPONENT_VERSIONS = [
    'sdc.emerging_digital.hero' => 'f6ebbdd7632bea80',
    'sdc.emerging_digital.trust-list' => '61b25a47f367765e',
    'sdc.emerging_digital.cta' => 'b4f165563eb02357',
  ];

  public function __construct(
    private readonly Importer $importer,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly string $appRoot,
  ) {
  }

  /**
   * Imports governed core Default Content and verifies the Canvas baseline.
   *
   * @return string[]
   *   Audit messages for the Governed Content report.
   */
  public function import(): array {
    $directory = $this->appRoot
      . '/modules/custom/emerging_digital_content/core_default_content';
    $finder = new Finder($directory);

    if ($finder->data === []) {
      throw new \RuntimeException(sprintf(
        'Governed core Default Content is empty at "%s".',
        $directory,
      ));
    }

    try {
      $this->importer->importContent($finder, Existing::Skip);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException(
        'Governed core Default Content import failed: '
        . $exception->getMessage(),
        0,
        $exception,
      );
    }

    $page = $this->entityRepository->loadEntityByUuid(
      'canvas_page',
      self::CANVAS_PAGE_UUID,
    );
    if (!$page instanceof ContentEntityInterface) {
      throw new \RuntimeException(
        'Governed Canvas proof page was not imported by Drupal core.',
      );
    }

    $violations = $page->validate();
    if (count($violations) > 0) {
      throw new \RuntimeException(sprintf(
        'Governed Canvas proof page is invalid after import: %s',
        (string) $violations,
      ));
    }

    $resolved = [];
    foreach ($page->get('components')->getValue() as $component) {
      $component_id = (string) ($component['component_id'] ?? '');
      $component_version = (string) ($component['component_version'] ?? '');
      if ($component_id !== '') {
        $resolved[$component_id] = $component_version;
      }
    }

    if ($resolved !== self::CANVAS_COMPONENT_VERSIONS) {
      throw new \RuntimeException(sprintf(
        'Canvas did not resolve the governed component versions as expected: %s',
        json_encode($resolved, JSON_THROW_ON_ERROR),
      ));
    }

    return [
      'core default content: governed Canvas proof page imported or retained',
      'core default content: Canvas component versions resolved from approved catalog',
    ];
  }

}
