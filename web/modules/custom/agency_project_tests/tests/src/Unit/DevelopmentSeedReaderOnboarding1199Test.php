<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers the bounded long-lived Development Seed reader onboarding contract.
 */
final class DevelopmentSeedReaderOnboarding1199Test extends TestCase {

  /**
   * Ensures onboarding cannot broaden the proven read-only reader boundary.
   */
  public function testReaderOnboardingRemainsBounded(): void {
    $root = dirname(__DIR__, 7);
    $script = file_get_contents($root . '/scripts/development-seed/remote-long-lived-reader.sh');
    $workflow = file_get_contents($root . '/.github/workflows/development-seed-reader-onboard.yml');

    self::assertIsString($script);
    self::assertIsString($workflow);
    self::assertStringContainsString("[[ \"$(id -un)\" == 'agency-preprod' ]]", $script);
    self::assertStringContainsString('read-only-scp.sh', $script);
    self::assertStringContainsString('restrict,command=', $script);
    self::assertStringContainsString('reader_seed_write=NONE', $script);
    self::assertStringContainsString('reader_general_shell=NONE', $script);
    self::assertStringContainsString('reader_port_forwarding=NONE', $script);
    self::assertStringContainsString('reader_pty=NONE', $script);
    self::assertStringContainsString("github.event_name == 'workflow_dispatch'", $workflow);
    self::assertStringContainsString("github.actor == 'E-merging-digital'", $workflow);
    self::assertStringContainsString("github.ref == 'refs/heads/main'", $workflow);
    self::assertStringContainsString('PREPROD_SSH_PRIVATE_KEY', $workflow);
    self::assertStringContainsString('StrictHostKeyChecking=yes', $workflow);
    self::assertStringNotContainsString('actions/upload-artifact', $workflow);
    self::assertStringNotContainsString('secrets.PROD_', $workflow);
  }

}
