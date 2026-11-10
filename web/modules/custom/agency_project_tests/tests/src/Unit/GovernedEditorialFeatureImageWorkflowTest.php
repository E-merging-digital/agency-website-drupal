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
    self::assertStringContainsString("1491) target='PREPROD'", $workflow);
    self::assertStringContainsString("1512) target='PREPROD'", $workflow);
    self::assertStringContainsString("1404) target='PREPROD'", $workflow);
    self::assertStringContainsString("issue == '1404' and 'verdict: `RELINK_REQUIRED`'", $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString(
      "always() && steps.request.outcome == 'success'",
      $workflow,
    );
    self::assertStringContainsString('Apply requires a bot-authored image dry-run PASS', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Issue #1404 uses only the PREPROD SSH bindings for its governed route.
   */
  public function testIssue1404InspectSelectsOnlyPreprodConnection(): void {
    $root = dirname(DRUPAL_ROOT);
    $dispatcher = Yaml::parseFile(
      $root . '/.github/workflows/agency-command-dispatch.yml',
    );
    self::assertIsArray($dispatcher);
    $job = $dispatcher['jobs']['editorial-feature-image'] ?? [];
    self::assertSame(
      './.github/workflows/trusted-editorial-feature-image.yml',
      $job['uses'] ?? NULL,
    );
    $secrets = $job['secrets'] ?? [];

    foreach ([
      'SSH_PRIVATE_KEY' => ['PREPROD_SSH_PRIVATE_KEY', 'SSH_PRIVATE_KEY'],
      'SERVER_HOST' => ['PREPROD_SERVER_HOST', 'SERVER_HOST'],
    ] as $name => [$preprodSecret, $prodSecret]) {
      $binding = $secrets[$name] ?? NULL;
      self::assertIsString($binding);
      self::assertStringContainsString(
        " || (github.event.issue.number == 1404 && startsWith("
        . "github.event.comment.body, '/agency-editorial-image '))) "
        . "&& secrets.{$preprodSecret} || secrets.{$prodSecret}",
        $binding,
      );
      self::assertStringNotContainsString(
        "github.event.issue.number == 1404 && startsWith("
        . "github.event.comment.body, '/agency-editorial-image-prod ')",
        $binding,
      );
    }

    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-feature-image.yml',
    );
    self::assertStringContainsString("1404) target='PREPROD'", $workflow);
    self::assertStringNotContainsString("1404) target='PROD'", $workflow);
    self::assertStringContainsString(
      "issue == '1404' and 'verdict: `RELINK_REQUIRED`'",
      $workflow,
    );
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
    self::assertSame([401, 958, 1404, 1491, 1512], array_keys($profile['profiles']));

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

    $mcp = $profile['profiles']['1404'];
    self::assertSame('article', $mcp['bundle']);
    self::assertSame('field_feature_image', $mcp['field_name']);
    self::assertSame('dfeba8f86b6a51f02b7ba2d914aa3e94bdb4245e8f48785c34016421689eac07', $mcp['article_payload_sha256']);
    self::assertSame('assets/editorial/issue-1404-mcp-permissions-editorial-v1.webp', $mcp['asset']['path']);
    self::assertSame('agency-mcp-permissions-editorial-v1.webp', $mcp['asset']['filename']);
    self::assertSame('image/webp', $mcp['asset']['mime']);
    self::assertSame([1672, 941, 55408], [$mcp['asset']['width'], $mcp['asset']['height'], $mcp['asset']['max_bytes']]);
    self::assertSame('0c504f2f72d1fa9cb6569c404fd9d0ae0853d3cfdc49ae422076b19c3bcd24c6', $mcp['asset']['sha256']);
    self::assertSame('Une application métier communique avec un agent IA à travers une passerelle qui limite les autorisations accordées.', $mcp['alt']['fr']);
    self::assertSame('A business application connects to an AI agent through a gateway that limits the permissions granted.', $mcp['alt']['en']);
    $mcpAsset = $this->governedAssetPath(1404);
    self::assertSame($mcp['asset']['sha256'], hash_file('sha256', $mcpAsset));
    self::assertSame(55408, filesize($mcpAsset));
    $trackedMcp = [];
    $trackedMcpExit = 0;
    exec('git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch ' . escapeshellarg($mcp['asset']['path']) . ' 2>/dev/null', $trackedMcp, $trackedMcpExit);
    self::assertSame(0, $trackedMcpExit, 'Exact owner #1404 WebP must be tracked in Git.');

    $original = $profile['profiles']['1491'];
    self::assertSame('article', $original['bundle']);
    self::assertSame('field_feature_image', $original['field_name']);
    self::assertSame('6f53bb01fcc8dae15d73c39a77f62e6da9d663ca588d4c5c31bedced95df11fd', $original['article_payload_sha256']);
    self::assertSame('assets/editorial/issue-1491-lens-saint-servais-flamenc-2007.jpg', $original['asset']['path']);
    self::assertSame('8e1cf32c125973a6e3c0103d7b36f7f997fc6fa87b59bda5b835b4c13b5094a2', $original['asset']['sha256']);
    self::assertSame('image/jpeg', $original['asset']['mime']);
    self::assertSame('issue-1491-lens-saint-servais-flamenc-2007-8e1cf32c.jpg', $original['asset']['filename']);
    self::assertSame([3214, 2146], [$original['asset']['width'], $original['asset']['height']]);
    self::assertSame(4112433, $original['asset']['max_bytes']);
    self::assertSame('Prairie et arbres dans le paysage rural de Lens-Saint-Servais, en Hesbaye.', $original['alt']['fr']);
    self::assertSame('Meadow and trees in the rural landscape of Lens-Saint-Servais, Hesbaye, Belgium.', $original['alt']['en']);
    $originalPath = $root . '/' . $original['asset']['path'];
    self::assertFileExists($originalPath);
    self::assertSame($original['asset']['sha256'], hash_file('sha256', $originalPath));
    self::assertLessThanOrEqual($original['asset']['max_bytes'], filesize($originalPath));
    $tracked = [];
    $trackedExit = 0;
    exec('git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch '
      . escapeshellarg($original['asset']['path']) . ' 2>/dev/null', $tracked, $trackedExit);
    self::assertSame(0, $trackedExit, 'The exact owner-provided original must be tracked in Git.');

    $migration = $profile['profiles']['1512'];
    self::assertSame('49fb931ab701e4914fff1117f175ef794ca7abe4087ae66629a5cb473554eaf2', $migration['article_payload_sha256']);
    self::assertSame('article', $migration['bundle']);
    self::assertSame('field_feature_image', $migration['field_name']);
    self::assertSame('assets/editorial/issue-1512-wordpress-drupal-migration.png', $migration['asset']['path']);
    self::assertSame('9f16cb72d97ec7a911b4deda57bf8708ef66affeba8d7c4f55abbfe4fd50f038', $migration['asset']['sha256']);
    self::assertSame('image/png', $migration['asset']['mime']);
    self::assertSame([1672, 941], [$migration['asset']['width'], $migration['asset']['height']]);
    self::assertSame(2671578, $migration['asset']['max_bytes']);
    self::assertNotEmpty($migration['alt']['fr']);
    self::assertNotEmpty($migration['alt']['en']);
    $migrationPath = $root . '/' . $migration['asset']['path'];
    self::assertFileExists($migrationPath);
    self::assertSame($migration['asset']['sha256'], hash_file('sha256', $migrationPath));
    self::assertLessThanOrEqual($migration['asset']['max_bytes'], filesize($migrationPath));

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
    self::assertStringContainsString('imagecreatefromjpeg', $workflow);
    self::assertStringContainsString('imagecreatefromwebp', $workflow);
    self::assertStringContainsString('8e1cf32c125973a6e3c0103d7b36f7f997fc6fa87b59bda5b835b4c13b5094a2', $workflow);
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
    foreach ([401, 958, 1491, 1512, 1404] as $issueNumber) {
      $assetPath = $this->governedAssetPath($issueNumber);

      $decoder = match ($issueNumber) {
        1491 => 'imagecreatefromjpeg',
        1404 => 'imagecreatefromwebp',
        default => 'imagecreatefrompng',
      };
      self::assertTrue(
        function_exists($decoder),
        'The governed editorial-image validation environment must provide the exact GD decoder.',
      );

      $warning = NULL;
      set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = $message;
        return TRUE;
      });
      try {
        $image = $decoder($assetPath);
      }
      finally {
        restore_error_handler();
      }

      self::assertInstanceOf(
        \GdImage::class,
        $image,
        $warning ?? 'GD returned false while decoding the generated editorial PNG asset.',
      );
      self::assertSame(match ($issueNumber) {
        1491 => 3214,
        1512, 1404 => 1672,
        default => 1200,
      }, imagesx($image));
      self::assertSame(match ($issueNumber) {
        1491 => 2146,
        1512, 1404 => 941,
        default => 630,
      }, imagesy($image));
    }
  }

  /**
   * Issue #1404 Drush inspection failures yield only classified evidence.
   */
  public function testIssue1404InspectFailureEvidenceIsSanitized(): void {
    $root = dirname(DRUPAL_ROOT);
    $runner = (string) file_get_contents(
      $root . '/scripts/runner/run-editorial-feature-image.sh',
    );
    self::assertStringContainsString(
      'if ! remote_run inspect "$remote_result" >/dev/null 2>&1; then',
      $runner,
    );
    self::assertStringContainsString(
      'scp "${ssh_opts[@]}" "$remote_target:$remote_result" "$failure_raw"',
      $runner,
    );
    self::assertStringContainsString(
      'DRUSH_FAILED_NO_RESULT',
      $runner,
    );
    self::assertStringContainsString(
      'DRUSH_FAIL_CLOSED_FID_DIVERGENCE',
      $runner,
    );
    self::assertStringContainsString(
      'DRUSH_FAIL_CLOSED_MAPPING_MISSING',
      $runner,
    );
    self::assertStringContainsString(
      'DRUSH_FAIL_CLOSED_OTHER',
      $runner,
    );
    self::assertStringContainsString(
      'rm -f -- "$failure_raw"',
      $runner,
    );
    self::assertStringContainsString(
      "'{status:\"FAIL\", verdict:\$verdict, mode:\"inspect\", issue_number:\$issue, target:\"PREPROD\", prod_write:\"NONE\"}'",
      $runner,
    );
    self::assertStringNotContainsString(
      '> "$ARTIFACT_DIR/raw-result.json"',
      $runner,
    );
    self::assertStringContainsString('        exit 1', $runner);
  }

  /**
   * Issue #1404 may reuse only an existing File proven for both languages.
   */
  public function testIssue1404FidReconciliationRefusesAmbiguousEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    if (!defined('AGENCY_EDITORIAL_FEATURE_IMAGE_LIBRARY_ONLY')) {
      define('AGENCY_EDITORIAL_FEATURE_IMAGE_LIBRARY_ONLY', TRUE);
    }
    require_once $root . '/scripts/runner/editorial-feature-image.php';

    $profiles = json_decode(
      (string) file_get_contents($root . '/scripts/runner/editorial-feature-image-profiles.json'),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $profile = $profiles['profiles']['1404'];
    $sha = $profile['asset']['sha256'];
    $fr = ['fid' => 11, 'exists' => TRUE, 'sha256' => $sha, 'alt' => $profile['alt']['fr']];
    $en = ['fid' => 12, 'exists' => TRUE, 'sha256' => $sha, 'alt' => $profile['alt']['en']];

    $method = new \ReflectionMethod('AgencyEditorialFeatureImage', 'selectIssue1404ExistingFid');
    $selectFid = static fn (array $candidate, array $left, array $right): mixed => $method->invoke(NULL, $candidate, $left, $right);
    self::assertSame(11, $selectFid($profile, $fr, $en));

    $otherIssue = array_replace($profile, ['issue_number' => 1491]);
    $invalid = [
      'different FR SHA' => [$profile, array_replace($fr, ['sha256' => str_repeat('0', 64)]), $en],
      'different EN SHA' => [$profile, $fr, array_replace($en, ['sha256' => str_repeat('0', 64)])],
      'missing FR File' => [$profile, array_replace($fr, ['exists' => FALSE]), $en],
      'missing EN File' => [$profile, $fr, array_replace($en, ['exists' => FALSE])],
      'wrong FR ALT' => [$profile, array_replace($fr, ['alt' => 'incorrect']), $en],
      'wrong EN ALT' => [$profile, $fr, array_replace($en, ['alt' => 'incorrect'])],
      'no FR FID' => [$profile, array_replace($fr, ['fid' => 0]), $en],
      'shared FID already' => [$profile, $fr, array_replace($en, ['fid' => 11])],
      'wrong Article profile' => [$otherIssue, $fr, $en],
    ];
    foreach ($invalid as $label => [$candidate, $left, $right]) {
      try {
        $selectFid($candidate, $left, $right);
        self::fail($label . ' must fail closed.');
      }
      catch (\RuntimeException $exception) {
        self::assertSame(
          'Issue #1404 FID reconciliation evidence is incomplete or ambiguous.',
          $exception->getMessage(),
          $label,
        );
      }
    }

    $php = (string) file_get_contents($root . '/scripts/runner/editorial-feature-image.php');
    self::assertStringContainsString('$this->loadMappedNode($profile, $issueNumber)', $php);
    self::assertStringContainsString("($governedProdPromotion ? 'READY' : 'RELINK_REQUIRED')", $php);
    self::assertStringContainsString('Issue #1404 may materialize a File only for a staged unpublished PROD Article.', $php);
    self::assertStringContainsString('if (($profile[\'issue_number\'] ?? NULL) === 1404 && !$altsMatch)', $php);
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
    self::assertStringContainsString('1404:PREPROD)', $shell);
    self::assertStringContainsString('remote_asset="${remote_stem}-asset.webp"', $shell);
    self::assertStringContainsString('RELINK_REQUIRED', $shell);
    self::assertStringContainsString('DRUSH_FAIL_CLOSED_RELINK_EVIDENCE', $shell);
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
    self::assertStringContainsString("REPLACE_APPROVED", $php);
    self::assertStringContainsString("(int) \$node->id() === 44", $php);
    self::assertStringContainsString("(int) \$frFid === 4", $php);
    self::assertStringContainsString('eebc73921e28b181d8f97009b00cd5ca5deb988320a45f89d46c98287d73ee49', $php);
    self::assertStringContainsString('public://articles/issue-1491-lens-saint-servais-original-eebc7392.png', $php);
    self::assertStringContainsString('REPLACE_APPROVED', $shell);
    self::assertStringContainsString('vendor/bin/drush sql:dump', $shell);

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

    $trustVerifier = (string) file_get_contents(
      $root . '/scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh',
    );
    self::assertStringContainsString(
      'PREPROD_STRICT_HOST_KEY_CHECKING=YES',
      $trustVerifier,
    );
    self::assertStringContainsString(
      '[[ "${#entries[@]}" -eq 1 ]]',
      $trustVerifier,
    );
    self::assertStringContainsString(
      '[[ "$known_host_blob" == "$key_blob" ]]',
      $trustVerifier,
    );

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

    if (in_array($issueNumber, [1491, 1512, 1404], TRUE)) {
      self::assertFileExists($assetPath);
      self::assertSame($asset['sha256'], hash_file('sha256', $assetPath));
      return $assetPath;
    }

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
