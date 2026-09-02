<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed editor-owned Article feature-image route.
 *
 * @group agency_project_tests
 * @group governed_editorial_feature_image
 */
final class GovernedEditorialFeatureImageWorkflowTest extends TestCase {

  /**
   * The control surface must stay issue-bound, actor-bound and main-trusted.
   */
  public function testWorkflowControlSurfaceIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-editorial-feature-image.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    foreach ([
      '/agency-editorial-image inspect',
      '/agency-editorial-image dry-run',
      '/agency-editorial-image apply',
    ] as $command) {
      self::assertStringContainsString($command, $workflow);
    }
    self::assertStringContainsString("GITHUB_ACTOR\" == 'E-merging-digital'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString("401) target='PROD'", $workflow);
    self::assertStringContainsString("958) target='PREPROD'", $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString(
      "always() && steps.request.outcome == 'success'",
      $workflow,
    );
    self::assertStringContainsString('Apply requires a bot-authored image dry-run PASS', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Asset transport stays exact, deterministic and repository-owned.
   */
  public function testProfileAndAssetAreClosedAndHashBound(): void {
    $root = dirname(DRUPAL_ROOT);
    $profilePath = $root . '/scripts/runner/editorial-feature-image-profiles.json';
    $profile = json_decode(
      (string) file_get_contents($profilePath),
      TRUE,
      16,
      JSON_THROW_ON_ERROR,
    );
    self::assertSame(1, $profile['schema_version']);
    self::assertSame([401, 958], array_keys($profile['profiles']));

    $issue = $profile['profiles']['401'];
    self::assertSame('article', $issue['bundle']);
    self::assertSame('field_feature_image', $issue['field_name']);
    self::assertSame('image/png', $issue['asset']['mime']);
    self::assertSame(1200, $issue['asset']['width']);
    self::assertSame(630, $issue['asset']['height']);
    self::assertStringStartsWith('assets/editorial/', $issue['asset']['path']);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issue['asset']['sha256']);
    self::assertSame(
      '70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898',
      $issue['asset']['sha256'],
    );

    $assetPath = $this->governedAssetPath();
    self::assertSame($issue['asset']['sha256'], hash_file('sha256', $assetPath));
    self::assertLessThanOrEqual($issue['asset']['max_bytes'], filesize($assetPath));
    self::assertSame('Checklist de préparation avant la refonte d’un site web', $issue['alt']['fr']);
    self::assertSame('Website redesign preparation checklist', $issue['alt']['en']);

    $preprod = $profile['profiles']['958'];
    self::assertSame('article', $preprod['bundle']);
    self::assertSame('field_feature_image', $preprod['field_name']);
    self::assertSame(
      '2e92228480ee6ae7410c028eab2b88c7d7db1534668477f6eafbc236668cb700',
      $preprod['article_payload_sha256'],
    );
    self::assertSame('image/png', $preprod['asset']['mime']);
    self::assertSame(1200, $preprod['asset']['width']);
    self::assertSame(630, $preprod['asset']['height']);
    self::assertSame(
      '3a705624b08ac2c21a10db0e8573841ff8c4cecf087409cd8782953e3684c6f4',
      $preprod['asset']['sha256'],
    );
    self::assertSame(
      'Préparation d’une mise à niveau Drupal 10 vers Drupal 11',
      $preprod['alt']['fr'],
    );
    self::assertSame(
      'Planning a Drupal 10 to Drupal 11 upgrade',
      $preprod['alt']['en'],
    );
    $preprodAssetPath = $this->governedAssetPath(958);
    self::assertSame(
      $preprod['asset']['sha256'],
      hash_file('sha256', $preprodAssetPath),
    );
    self::assertLessThanOrEqual(
      $preprod['asset']['max_bytes'],
      filesize($preprodAssetPath),
    );

    foreach ([401, 958] as $issueNumber) {
      $generator = $root . "/scripts/runner/generate-editorial-feature-image-{$issueNumber}.py";
      self::assertFileExists($generator);
      $generatorSource = (string) file_get_contents($generator);
      foreach (['urllib', 'requests', 'http://', 'https://', 'subprocess'] as $forbidden) {
        self::assertStringNotContainsString($forbidden, $generatorSource);
      }
      $asset = $profile['profiles'][(string) $issueNumber]['asset'];
      $trackedOutput = [];
      $trackedExit = 0;
      exec(
        'git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch '
        . escapeshellarg($asset['path']) . ' 2>/dev/null',
        $trackedOutput,
        $trackedExit,
      );
      self::assertNotSame(
        0,
        $trackedExit,
        sprintf('Generated #%d PNG must not be committed as opaque binary bytes.', $issueNumber),
      );
    }

    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-feature-image.yml',
    );
    foreach (['curl ', 'wget ', 'http://', 'https://'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
    self::assertStringContainsString('generate-editorial-feature-image-401.py', $workflow);
    self::assertStringContainsString('generate-editorial-feature-image-958.py', $workflow);
    self::assertStringContainsString('imagecreatefrompng', $workflow);
    self::assertStringContainsString('hashlib.sha256', $workflow);
    self::assertStringContainsString('asset_sha256', $workflow);
    self::assertStringContainsString('profile_sha256', $workflow);
  }

  /**
   * Generated PNG assets must have a structurally valid chunk stream.
   */
  public function testGovernedEditorialAssetHasValidPngStructure(): void {
    $assetPath = $this->governedAssetPath();
    $bytes = file_get_contents($assetPath);
    self::assertIsString($bytes);
    self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $bytes, 'PNG signature is invalid.');

    $offset = 8;
    $length = strlen($bytes);
    $chunks = [];
    $sawIdat = FALSE;
    $sawIend = FALSE;

    while ($offset < $length) {
      self::assertGreaterThanOrEqual(
        $offset + 12,
        $length,
        sprintf('PNG chunk header/trailer is truncated at byte %d.', $offset),
      );

      $chunkLength = unpack('Nlength', substr($bytes, $offset, 4));
      self::assertIsArray($chunkLength);
      $dataLength = $chunkLength['length'];
      $type = substr($bytes, $offset + 4, 4);
      self::assertMatchesRegularExpression(
        '/^[A-Za-z]{4}$/D',
        $type,
        sprintf('Invalid PNG chunk type at byte %d.', $offset),
      );

      $chunkEnd = $offset + 12 + $dataLength;
      self::assertLessThanOrEqual(
        $length,
        $chunkEnd,
        sprintf('PNG chunk %s at byte %d is truncated (declared length %d).', $type, $offset, $dataLength),
      );

      $data = substr($bytes, $offset + 8, $dataLength);
      $storedCrc = substr($bytes, $offset + 8 + $dataLength, 4);
      $computedCrc = pack('N', crc32($type . $data));
      self::assertSame(
        bin2hex($computedCrc),
        bin2hex($storedCrc),
        sprintf('PNG chunk %s at byte %d has an invalid CRC.', $type, $offset),
      );

      $chunks[] = $type;
      if ($type === 'IDAT') {
        $sawIdat = TRUE;
      }
      if ($type === 'IEND') {
        self::assertSame(0, $dataLength, 'PNG IEND chunk must be empty.');
        $sawIend = TRUE;
        $offset = $chunkEnd;
        break;
      }
      $offset = $chunkEnd;
    }

    self::assertSame('IHDR', $chunks[0] ?? NULL, 'PNG IHDR must be the first chunk.');
    self::assertTrue($sawIdat, 'PNG must contain at least one IDAT chunk.');
    self::assertTrue($sawIend, 'PNG must terminate with an IEND chunk.');
    self::assertSame($length, $offset, 'PNG contains trailing bytes after IEND.');
  }

  /**
   * Generated editorial PNGs must remain GD-decodable.
   */
  public function testGovernedEditorialAssetIsGdDecodable(): void {
    foreach ([401, 958] as $issueNumber) {
      $assetPath = $this->governedAssetPath($issueNumber);

      self::assertTrue(
        function_exists('imagecreatefrompng'),
        'The governed editorial-image validation environment must provide GD PNG decoding.',
      );

      $warning = NULL;
      set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = $message;
        return TRUE;
      });
      try {
        $image = imagecreatefrompng($assetPath);
      }
      finally {
        restore_error_handler();
      }

      self::assertInstanceOf(
        \GdImage::class,
        $image,
        $warning ?? 'GD returned false while decoding the generated editorial PNG asset.',
      );
      self::assertSame(1200, imagesx($image));
      self::assertSame(630, imagesy($image));
      imagedestroy($image);
    }
  }

  /**
   * The runner may transport only the trusted profile and asset.
   */
  public function testRunnerIsFixedAndSyntaxValid(): void {
    $root = dirname(DRUPAL_ROOT);
    $shellPath = $root . '/scripts/runner/run-editorial-feature-image.sh';
    $phpPath = $root . '/scripts/runner/editorial-feature-image.php';
    self::assertFileExists($shellPath);
    self::assertFileExists($phpPath);

    $shell = (string) file_get_contents($shellPath);
    $php = (string) file_get_contents($phpPath);
    $combined = $shell . "\n" . $php;
    self::assertStringContainsString("TARGET='PROD'", $shell);
    self::assertStringContainsString("TARGET='PREPROD'", $shell);
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency'", $shell);
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency-preprod'", $shell);
    self::assertStringContainsString("SERVER_USER='agency-preprod'", $shell);
    self::assertStringContainsString('scripts/preproduction/validate-runtime.sh', $shell);
    self::assertStringContainsString('vendor/bin/drush sql:dump', $shell);
    self::assertStringContainsString('vendor/bin/drush php:script', $shell);
    self::assertStringContainsString('scp "${ssh_opts[@]}" "$ASSET_FILE"', $shell);
    self::assertStringContainsString("private const FIELD_NAME = 'field_feature_image'", $php);
    self::assertStringContainsString("private const DESTINATION_DIRECTORY = 'public://articles'", $php);
    self::assertStringContainsString("\$container->get('file_system')", $php);
    self::assertStringContainsString('prepareDirectory(', $php);
    self::assertStringContainsString('FileSystemInterface::CREATE_DIRECTORY', $php);
    self::assertStringContainsString('FileSystemInterface::MODIFY_PERMISSIONS', $php);
    self::assertStringContainsString('FileExists::Error', $php);
    self::assertStringContainsString('agency_editorial.issue.', $php);

    foreach ([
      'drush cim',
      'drush updb',
      'emerging:governed-content',
      'deploy-production.sh',
      'composer require',
      'curl ',
      'wget ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $combined);
    }

    $output = [];
    $exitCode = 0;
    exec('bash -n ' . escapeshellarg($shellPath) . ' 2>&1', $output, $exitCode);
    self::assertSame(0, $exitCode, implode("\n", $output));

    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($phpPath) . ' 2>&1', $output, $exitCode);
    self::assertSame(0, $exitCode, implode("\n", $output));
  }

  /**
   * The workflow reuses only the existing bounded SSH secret surface.
   */
  public function testWorkflowReusesExistingSshSecrets(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-feature-image.yml',
    );
    self::assertStringContainsString('${{ secrets.SSH_PRIVATE_KEY }}', $workflow);
    self::assertStringContainsString('${{ secrets.SERVER_HOST }}', $workflow);
    self::assertStringContainsString('${{ secrets.SERVER_USER }}', $workflow);
    self::assertStringContainsString(
      'test -f scripts/preproduction-ssh-trust/manage-known-host.sh',
      $workflow,
    );
    self::assertStringContainsString(
      'test -f scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh',
      $workflow,
    );
    self::assertStringNotContainsString(
      'test -x scripts/preproduction-ssh-trust/manage-known-host.sh',
      $workflow,
    );
    self::assertStringNotContainsString(
      'test -x scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh',
      $workflow,
    );
    self::assertStringContainsString(
      'bash scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION',
      $workflow,
    );
    self::assertStringContainsString(
      'bash scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh',
      $workflow,
    );

    $candidate = (string) file_get_contents(
      $root . '/scripts/runner/run-editorial-preprod-candidate.sh',
    );
    self::assertStringContainsString(
      'bash "$PREPROD_TRUST_PROVISION" PROVISION',
      $candidate,
    );
    self::assertStringContainsString('bash "$PREPROD_TRUST_VERIFY"', $candidate);

    $runner = (string) file_get_contents(
      $root . '/scripts/runner/run-editorial-feature-image.sh',
    );
    self::assertStringContainsString('StrictHostKeyChecking=yes', $runner);
    self::assertStringContainsString('manage-known-host.sh PROVISION', $workflow);
    self::assertStringContainsString('verify-preprod-pinned-trust.sh', $workflow);
    self::assertStringNotContainsString('password:', $workflow);
    self::assertStringNotContainsString('settings.php', $workflow);
  }

  /**
   * Generates one exact closed asset from repository-owned source code.
   */
  private function governedAssetPath(int $issueNumber = 401): string {
    $root = dirname(DRUPAL_ROOT);
    $profile = json_decode(
      (string) file_get_contents($root . '/scripts/runner/editorial-feature-image-profiles.json'),
      TRUE,
      16,
      JSON_THROW_ON_ERROR,
    );
    $asset = $profile['profiles'][(string) $issueNumber]['asset'];
    $assetPath = $root . '/' . $asset['path'];
    $generator = $root . "/scripts/runner/generate-editorial-feature-image-{$issueNumber}.py";

    $output = [];
    $exitCode = 0;
    exec(
      'python3 ' . escapeshellarg($generator) . ' --output ' . escapeshellarg($assetPath) . ' 2>&1',
      $output,
      $exitCode,
    );
    self::assertSame(0, $exitCode, implode("\n", $output));
    self::assertFileExists($assetPath);
    self::assertSame($asset['sha256'], hash_file('sha256', $assetPath));

    return $assetPath;
  }

}