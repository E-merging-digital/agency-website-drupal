<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the reused #980 PROD primitive for #982/#995/#1301.
 *
 * @group agency_project_tests
 * @group prod_config_sync_runtime_diagnostic_980
 */
final class ProdConfigSyncRuntimeDiagnostic980WorkflowTest extends TestCase {

  private const WORKFLOW =
    '.github/workflows/prod-config-sync-runtime-diagnostic.yml';

  private const RUNNER =
    'scripts/runner/run-prod-config-sync-runtime-diagnostic-980.sh';

  private const FILTER = 'scripts/runner/filter-config-status-metadata.php';

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const LEGACY_PROD_HEALTH_DOC =
    'docs/operations/production-health-diagnostic.md';

  /**
   * Proves #982/#995/#1301 PROD binding and exact command.
   */
  public function testWorkflowIsBoundToIssues982And995And1301AndExactCommand(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);

    self::assertArrayHasKey('on', $workflow);
    $on = $workflow['on'];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayHasKey('pull_request', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertStringContainsString(
      '(github.event.issue.number == 982 || github.event.issue.number == 995 || github.event.issue.number == 1301)',
      $source,
    );
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-sync-prod-runtime diagnose'",
      $source,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'982\' || "$ISSUE_NUMBER" == \'995\' || "$ISSUE_NUMBER" == \'1301\' ]]',
      $source,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $source,
    );
    self::assertStringContainsString("== 'open'", $source);
    self::assertStringContainsString('5528251064', $source);
    self::assertStringContainsString('5529562346', $source);
    self::assertStringContainsString('PROJECT_LEAD_DIAGNOSTIC_AUTHORITY_1301_R', $source);
    self::assertStringContainsString('gh api --paginate --slurp', $source);
    self::assertStringContainsString('.id < $command_id', $source);
    self::assertStringContainsString('sort_by(.id)', $source);
    self::assertStringContainsString('last // empty', $source);
    self::assertStringContainsString('LIVE_MAIN =\\n', $source);
    self::assertStringContainsString('AUTHORIZED HUMAN COMMAND =\\n', $source);
    self::assertStringContainsString('.author_association == "OWNER"', $source);
    self::assertStringContainsString('.performed_via_github_app == null', $source);
    self::assertStringContainsString('EVENT_DEFAULT_SHA', $source);
    self::assertStringContainsString(
      'JIT revalidate live main before PROD identity',
      $source,
    );

    $secrets = $on['workflow_call']['secrets'] ?? [];
    self::assertSame(
      ['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'],
      array_keys($secrets),
    );
    self::assertArrayNotHasKey('inputs', $on['workflow_call']);
  }

  /**
   * Proves pull-request validation cannot reach PROD or PREPROD runtime.
   */
  public function testPullRequestValidationIsNonOperational(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $static = $workflow['jobs']['static-validation'] ?? NULL;
    self::assertIsArray($static);
    self::assertSame(
      '${{ github.event_name == \'pull_request\' }}',
      $static['if'] ?? NULL,
    );
    self::assertArrayNotHasKey('secrets', $static);

    $surface = json_encode($static, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $surface);
    self::assertStringNotContainsString('SERVER_HOST', $surface);
    self::assertStringNotContainsString('SERVER_USER', $surface);
    self::assertStringNotContainsString('ssh ', $surface);
    self::assertStringNotContainsString('scp ', $surface);
  }

  /**
   * Proves historical PROD trust, identity and paths remain intact.
   */
  public function testRunnerIsFixedToProdAndReusesPinnedTrust(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'980\' || "$ISSUE_NUMBER" == \'982\' || "$ISSUE_NUMBER" == \'995\' || "$ISSUE_NUMBER" == \'1301\' ]]',
      $runner,
    );
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency'", $runner);
    self::assertStringContainsString('$SERVER_USER@$SERVER_HOST', $runner);
    self::assertStringContainsString(
      "PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'",
      $runner,
    );
    self::assertStringContainsString(
      'SERVER_HOST="$SERVER_HOST" bash "$PROD_TRUST" PROVISION',
      $runner,
    );
    self::assertStringContainsString(
      'SERVER_HOST="$SERVER_HOST" bash "$PROD_TRUST" VERIFY_ONLY',
      $runner,
    );
    self::assertStringContainsString('StrictHostKeyChecking=yes', $runner);
    self::assertStringContainsString('/var/www/agency/current', $runner);
    self::assertStringContainsString(
      '/var/www/agency/shared/settings/settings.php',
      $runner,
    );

    self::assertStringNotContainsString('/var/www/agency-preprod', $runner);
    self::assertStringNotContainsString('PREPROD_SERVER_HOST', $runner);
    self::assertStringNotContainsString('PREPROD_SSH_PRIVATE_KEY', $runner);
    self::assertStringNotContainsString('TARGET=', $runner);
    self::assertStringNotContainsString('bash -s', $runner);
    self::assertStringNotContainsString('scp ', $runner);
    self::assertStringNotContainsString('ssh-keyscan', $runner);
    self::assertStringNotContainsString('StrictHostKeyChecking=no', $runner);
    self::assertStringNotContainsString('accept-new', $runner);
  }

  /**
   * Proves #1301 is metadata-only while historical #995 stays canvas-only.
   */
  public function testIssue1301MetadataProfileAndHistoricalProfiles(): void {
    $workflow = $this->source(self::WORKFLOW);
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      'DIAGNOSTIC_PROFILE: ${{ github.event.issue.number == 995 && \'canvas_paths\' || \'metadata\' }}',
      $workflow,
    );
    self::assertStringContainsString(
      'if [[ "$ISSUE_NUMBER" == \'995\' ]]; then',
      $runner,
    );
    self::assertStringContainsString(
      '#980/#982/#1301 require the metadata diagnostic profile.',
      $runner,
    );
  }

  /**
   * Proves fresh #1301 authority selection and direct-owner provenance.
   */
  public function testIssue1301FreshAuthoritySelectionAndDirectOwnerProvenance(): void {
    $workflow = $this->source(self::WORKFLOW);
    $dispatcher = $this->source(self::DISPATCHER);

    foreach ([
      'PROJECT_LEAD_DIAGNOSTIC_AUTHORITY_1301_R',
      '.id < $command_id',
      '.user.login == "E-merging-digital"',
      '.author_association == "OWNER"',
      'contains("LIVE_MAIN =\\n" + $main)',
      'contains("AUTHORIZED HUMAN COMMAND =\\n" + $command)',
      'sort_by(.id)',
      'last // empty',
      '[[ "$authority_comment_id" -lt "$TRIGGER_COMMENT_ID" ]]',
      '.performed_via_github_app == null',
    ] as $required) {
      self::assertStringContainsString($required, $workflow, $required);
    }

    self::assertStringContainsString(
      "'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC': ('982', '995', '1301')",
      $dispatcher,
    );
    self::assertStringContainsString("issue == '1301'", $dispatcher);
    self::assertStringContainsString(
      "comment_author_association != 'OWNER'",
      $dispatcher,
    );
    self::assertStringContainsString('or comment_from_app', $dispatcher);

    self::assertStringContainsString("authority_comment='5528251064'", $workflow);
    self::assertStringContainsString("authority_comment='5529562346'", $workflow);
  }

  /**
   * Proves stale, wrong and out-of-order #1301 authorities are rejected.
   */
  public function testIssue1301AuthoritySelectionMatrix(): void {
    $main = str_repeat('a', 40);
    $otherMain = str_repeat('b', 40);
    $command = '/agency-config-sync-prod-runtime diagnose';
    $commandId = 500;

    $comments = [
      $this->authorityComment(100, $otherMain, $command),
      $this->authorityComment(200, $main, '/wrong-command'),
      $this->authorityComment(300, $main, $command, 'CONTRIBUTOR'),
      $this->authorityComment(400, $main, $command),
      $this->authorityComment(450, $main, $command),
      $this->authorityComment(600, $main, $command),
    ];

    self::assertSame(
      450,
      $this->selectIssue1301Authority($comments, $commandId, $main, $command),
    );
    self::assertNull(
      $this->selectIssue1301Authority($comments, 400, $main, $command),
    );
    self::assertNull(
      $this->selectIssue1301Authority($comments, $commandId, $otherMain, '/absent'),
    );
  }

  /**
   * Proves historical SHA construction remains nounset-safe and exact.
   */
  public function testSettingsShaCommandSurvivesNounset(): void {
    $runner = $this->source(self::RUNNER);
    $matches = array_values(array_filter(
      preg_split('/\R/', $runner) ?: [],
      static fn(string $line): bool => str_contains(
        $line,
        'sha256sum \'$EXPECTED_SETTINGS\' | awk',
      ),
    ));

    self::assertCount(1, $matches);
    $expression = trim($matches[0]);
    self::assertStringStartsWith('"set -euo pipefail;', $expression);
    self::assertStringEndsWith('")"', $expression);
    $expression = substr($expression, 0, -2);

    $bash = implode("\n", [
      'set -u',
      "EXPECTED_SETTINGS='/var/www/agency/shared/settings/settings.php'",
      'remote_command=' . $expression,
      'printf \'%s\\n\' "$remote_command"',
    ]);
    $process = new Process(['bash', '-c', $bash]);
    $process->run();

    self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    self::assertSame(
      'set -euo pipefail; sha256sum ' .
      '\'/var/www/agency/shared/settings/settings.php\' | ' .
      'awk \'{print $1}\'' . "\n",
      $process->getOutput(),
    );
  }

  /**
   * Proves the Drupal getter and config status remain strictly read-only.
   */
  public function testDrupalGetterResolutionAndConfigStatusAreReadOnly(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      "\\Drupal\\Core\\Site\\Settings::get('config_sync_directory')",
      $runner,
    );
    self::assertStringContainsString('base64_encode(DRUPAL_ROOT)', $runner);
    self::assertStringContainsString(
      'vendor/bin/drush status --field=bootstrap',
      $runner,
    );
    self::assertStringContainsString('vendor/bin/drush php:eval', $runner);
    self::assertStringContainsString(
      'vendor/bin/drush config:status --format=json',
      $runner,
    );
    self::assertStringContainsString(
      'realpath -m -- "$drupal_root/$effective_config_sync"',
      $runner,
    );

    foreach ([
      'vendor/bin/drush cim',
      'vendor/bin/drush cex',
      'vendor/bin/drush config:import',
      'vendor/bin/drush config:export',
      'vendor/bin/drush cr',
      'vendor/bin/drush updb',
      'vendor/bin/drush deploy',
      'vendor/bin/drush pm:enable',
      'vendor/bin/drush config:set',
      'state:set',
      'sql:query',
      'sql:dump',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner, $forbidden);
    }
  }

  /**
   * Restores historical evidence/security assertions and adds #982 metadata.
   */
  public function testEvidenceAndExistingRoutesRemainBounded(): void {
    $runner = $this->source(self::RUNNER);
    $workflow = $this->source(self::WORKFLOW);
    $filter = $this->source(self::FILTER);
    $dispatcher = $this->source(self::DISPATCHER);
    $legacyHealth = $this->source(self::LEGACY_PROD_HEALTH_DOC);

    foreach ([
      'CURRENT_RELEASE',
      'CURRENT_SYMLINK_TARGET',
      'DRUPAL_ROOT',
      'SETTINGS_SYMLINK_TARGET',
      'SHARED_SETTINGS_SHA256',
      'EFFECTIVE_CONFIG_SYNC_DIRECTORY',
      'RESOLVED_CONFIG_SYNC_PATH',
      'RESOLVED_PATH_EXISTS',
      'CONFIG_SYNC_ENTRY_COUNT',
      'DRUSH_BOOTSTRAP',
      'DRUSH_CONFIG_STATUS',
      'DRUPAL_STATUS_CONFIG_SYNC_WARNING',
    ] as $field) {
      self::assertStringContainsString(
        $field,
        strtoupper($workflow . $runner),
        $field,
      );
    }

    self::assertStringContainsString('sha256sum', $runner);
    self::assertStringContainsString('"NOT_OBSERVABLE"', $runner);
    self::assertStringContainsString('prod_access: "READ_ONLY"', $runner);
    self::assertStringContainsString('prod_mutation: "NONE"', $runner);
    self::assertStringContainsString('prod_write: "NONE"', $runner);
    self::assertStringContainsString('preprod_access: "NONE"', $runner);
    self::assertStringContainsString('preprod_write: "NONE"', $runner);

    self::assertStringContainsString(
      'php "$CONFIG_STATUS_FILTER" PROD',
      $runner,
    );
    self::assertStringContainsString('runtime_config_metadata', $runner);
    self::assertStringContainsString('config_values_exposed', $runner . $filter);
    self::assertStringContainsString(
      'environment + config_name + operation/state',
      $filter . $workflow,
    );
    self::assertStringContainsString('public-result.json', $workflow);
    self::assertStringContainsString("jq '.runtime_config_metadata'", $workflow);

    foreach ([$runner, $workflow, $filter] as $surface) {
      self::assertStringNotContainsString('DB_PASSWORD', $surface);
      self::assertStringNotContainsString('DATABASE_URL', $surface);
      self::assertStringNotContainsString('runtime.env', $surface);
      self::assertStringNotContainsString('cat settings.php', $surface);
      self::assertStringNotContainsString('cat "$EXPECTED_SETTINGS"', $surface);
      self::assertStringNotContainsString('source "$EXPECTED_SETTINGS"', $surface);
      self::assertStringNotContainsString(
        'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
        $surface,
      );
    }

    self::assertStringContainsString(
      '/agency-production-health diagnose',
      $legacyHealth,
    );
    self::assertStringContainsString('/var/www/agency/current', $legacyHealth);
    self::assertStringContainsString('existing production SSH channel', $legacyHealth);
    self::assertStringContainsString(
      '/agency-config-sync-runtime diagnose',
      $dispatcher,
    );
    self::assertStringContainsString(
      '/agency-config-sync-prod-runtime diagnose',
      $dispatcher,
    );
  }

  /**
   * Publishes #1301 path evidence while preserving #982/#995 schemas.
   */
  public function testIssue1301PublishesBoundedPathEvidenceOnly(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString(
      "elif [[ \"\$ISSUE_NUMBER\" == '1301' ]]; then",
      $workflow,
    );

    foreach ([
      'TARGET: .target',
      'CURRENT_RELEASE: .current_release',
      'CURRENT_SYMLINK_TARGET: .current_symlink_target',
      'DRUPAL_ROOT: .drupal_root',
      'SETTINGS_SYMLINK_TARGET: .settings_symlink_target',
      'SHARED_SETTINGS_SHA256: .shared_settings_sha256',
      'EFFECTIVE_CONFIG_SYNC_DIRECTORY: .effective_config_sync_directory',
      'RESOLVED_CONFIG_SYNC_PATH: .resolved_config_sync_path',
      'RESOLVED_PATH_EXISTS: .resolved_path_exists',
      'CONFIG_SYNC_ENTRY_COUNT: .config_sync_entry_count',
      'DRUSH_BOOTSTRAP: .drush_bootstrap',
      'DRUSH_CONFIG_STATUS: .drush_config_status',
      'DRUPAL_STATUS_CONFIG_SYNC_WARNING: .drupal_status_config_sync_warning',
      'PROD_ACCESS: .prod_access',
      'PROD_MUTATION: .prod_mutation',
      'PROD_WRITE: .prod_write',
      'PREPROD_ACCESS: .preprod_access',
      'PREPROD_WRITE: .preprod_write',
      'runtime_config_metadata: .runtime_config_metadata',
    ] as $mapping) {
      self::assertStringContainsString($mapping, $workflow, $mapping);
    }

    foreach ([
      '.TARGET == "PROD"',
      'test("^[A-Za-z0-9._-]+$")',
      '.CURRENT_SYMLINK_TARGET == ("/var/www/agency/releases/" + .CURRENT_RELEASE)',
      '.DRUPAL_ROOT == (.CURRENT_SYMLINK_TARGET + "/web")',
      '.SETTINGS_SYMLINK_TARGET == "/var/www/agency/shared/settings/settings.php"',
      'test("^[0-9a-f]{64}$")',
      '.EFFECTIVE_CONFIG_SYNC_DIRECTORY',
      '. != "UNOBSERVABLE"',
      '.RESOLVED_CONFIG_SYNC_PATH',
      '.RESOLVED_PATH_EXISTS == "YES"',
      '.CONFIG_SYNC_ENTRY_COUNT | type == "number" and . >= 0',
      '.DRUSH_BOOTSTRAP == "SUCCESS"',
      '.DRUSH_CONFIG_STATUS == "CLEAN"',
      '.DRUPAL_STATUS_CONFIG_SYNC_WARNING',
      '.PROD_ACCESS == "READ_ONLY"',
      '.PROD_MUTATION == "NONE"',
      '.PROD_WRITE == "NONE"',
      '.PREPROD_ACCESS == "NONE"',
      '.PREPROD_WRITE == "NONE"',
      '.runtime_config_metadata.config_values_exposed == false',
    ] as $contract) {
      self::assertStringContainsString($contract, $workflow, $contract);
    }

    self::assertSame(
      1,
      substr_count($workflow, "jq '.runtime_config_metadata' \"\$result\" > \"\$public\""),
      '#982 metadata-only publication must remain unique and unchanged.',
    );
    self::assertSame(
      1,
      substr_count($workflow, "jq '.runtime_canvas_paths' \"\$result\" > \"\$public\""),
      '#995 canvas_paths publication must remain unique and unchanged.',
    );
  }

  /**
   * Publishes only approved #1301 evidence in the bot comment.
   */
  public function testIssue1301CommentEvidenceIsBounded(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString(
      '### Agency #1301 PROD config diagnostic PASS',
      $workflow,
    );
    foreach ([
      'TARGET=${target}',
      'CURRENT_RELEASE=${current_release}',
      'CURRENT_SYMLINK_TARGET=${current_target}',
      'DRUPAL_ROOT=${drupal_root}',
      'SETTINGS_SYMLINK_TARGET=${settings_target}',
      'SHARED_SETTINGS_SHA256=${settings_sha}',
      'EFFECTIVE_CONFIG_SYNC_DIRECTORY=${effective_sync}',
      'RESOLVED_CONFIG_SYNC_PATH=${resolved_sync}',
      'RESOLVED_PATH_EXISTS=${resolved_exists}',
      'CONFIG_SYNC_ENTRY_COUNT=${entry_count}',
      'DRUSH_BOOTSTRAP=${bootstrap}',
      'DRUSH_CONFIG_STATUS=${config_status}',
      'DRUPAL_STATUS_CONFIG_SYNC_WARNING=${config_warning}',
      'CONFIG_VALUES_EXPOSED=NO',
      'PROD_ACCESS=${prod_access}',
      'PROD_MUTATION=${prod_mutation}',
      'PROD_WRITE=${prod_write}',
      'PREPROD_ACCESS=${preprod_access}',
      'PREPROD_WRITE=${preprod_write}',
    ] as $field) {
      self::assertStringContainsString($field, $workflow, $field);
    }

    foreach ([
      'settings.php contents',
      'DATABASE_URL',
      'DB_PASSWORD',
      'SSH_PRIVATE_KEY=',
      'SERVER_HOST=',
      'SERVER_USER=',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow, $forbidden);
    }
  }

  /**
   * Keeps the existing remote diagnostic path single and read-only.
   */
  public function testIssue1308DoesNotAddRemoteExecutionOrMutationPrimitives(): void {
    $workflow = $this->source(self::WORKFLOW);
    $runner = $this->source(self::RUNNER);

    self::assertSame(
      1,
      substr_count(
        $workflow,
        'run: bash scripts/runner/run-prod-config-sync-runtime-diagnostic-980.sh',
      ),
    );

    foreach ([
      'vendor/bin/drush cim',
      'vendor/bin/drush cex',
      'vendor/bin/drush cr',
      'vendor/bin/drush updb',
      'vendor/bin/drush deploy',
      'vendor/bin/drush config:set',
      'state:set',
      'sql:query',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner, $forbidden);
      self::assertStringNotContainsString($forbidden, $workflow, $forbidden);
    }
  }

  /**
   * Builds one synthetic Project Lead authority comment.
   */
  private function authorityComment(
    int $id,
    string $main,
    string $command,
    string $association = 'OWNER',
  ): array {
    return [
      'id' => $id,
      'user' => ['login' => 'E-merging-digital'],
      'author_association' => $association,
      'body' => "PROJECT_LEAD_DIAGNOSTIC_AUTHORITY_1301_R1\n"
      . "LIVE_MAIN =\n{$main}\n"
      . "AUTHORIZED HUMAN COMMAND =\n{$command}\n",
    ];
  }

  /**
   * Mirrors the bounded workflow selector for deterministic fixture proof.
   */
  private function selectIssue1301Authority(
    array $comments,
    int $commandId,
    string $main,
    string $command,
  ): ?int {
    $valid = array_filter(
      $comments,
      static fn(array $comment): bool =>
        is_int($comment['id'] ?? NULL)
        && $comment['id'] < $commandId
        && ($comment['user']['login'] ?? NULL) === 'E-merging-digital'
        && ($comment['author_association'] ?? NULL) === 'OWNER'
        && str_starts_with(
          (string) ($comment['body'] ?? ''),
          'PROJECT_LEAD_DIAGNOSTIC_AUTHORITY_1301_R',
        )
        && str_contains(
          (string) $comment['body'],
          "LIVE_MAIN =\n{$main}",
        )
        && str_contains(
          (string) $comment['body'],
          "AUTHORIZED HUMAN COMMAND =\n{$command}",
        ),
    );
    if ($valid === []) {
      return NULL;
    }
    usort(
      $valid,
      static fn(array $left, array $right): int => $left['id'] <=> $right['id'],
    );
    $last = end($valid);
    return is_array($last) ? (int) $last['id'] : NULL;
  }

  /**
   * Parses one repository workflow structurally.
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads one repository source file as text.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}
