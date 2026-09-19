<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Protects the bounded active Nginx identity diagnostic for Agency #1250.
 *
 * @group agency_project_tests
 */
final class PreprodCockpitTransport1250ActiveNginxIdentityTest extends TestCase {

  private const ACTIVE = 'scripts/preproduction-cockpit-transport-1218/nginx-active-config-identity.py';

  private const ROUTE = 'scripts/preproduction-cockpit-transport-1218/nginx-effective-route-diagnostic.py';

  private const TEMPLATE = 'scripts/preproduction/nginx-agency-preprod.conf.template';

  private const PLAN = 'scripts/preproduction-cockpit-transport-1218/remote-plan.sh';

  private const WORKFLOW = '.github/workflows/preprod-cockpit-transport-1218.yml';

  private const SOURCE_FIXTURE = 'web/modules/custom/agency_project_tests/tests/fixtures/nginx-1248/certbot-shaped.conf';

  private const HOSTNAME = 'preprod.emergingdigital.be';

  private const MACHINE_PATH = '/api/agency-operations/v1/environment-data-state';

  /**
   * Synthetic filesystem root used by deterministic Nginx fixtures.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $base = tempnam(sys_get_temp_dir(), 'agency-1250-');
    self::assertIsString($base);
    @unlink($base);
    self::assertTrue(mkdir($base, 0700));
    $this->root = $base;
    $this->mkdir('/etc/nginx/sites-available');
    $this->mkdir('/etc/nginx/sites-enabled');
    $this->mkdir('/etc/nginx/conf.d');

    $source = file_get_contents($this->repositoryPath(self::SOURCE_FIXTURE));
    self::assertIsString($source);
    $this->write('/etc/nginx/sites-available/agency-preprod', $source);
    $this->writeMain('include /etc/nginx/sites-enabled/*;');
    self::assertTrue(symlink(
      '../sites-available/agency-preprod',
      $this->physical('/etc/nginx/sites-enabled/agency-preprod'),
    ));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $process = new Process(['rm', '-rf', '--', $this->root]);
    $process->run();
    parent::tearDown();
  }

  /**
   * Proves canonical, enabled and loaded identities bind to one TLS source.
   */
  public function testCanonicalSymlinkIdentityAndCandidateBindingPass(): void {
    $diagnostic = $this->diagnose();

    self::assertSame('PASS', $diagnostic['status']);
    self::assertSame('/etc/nginx/nginx.conf', $diagnostic['nginx_main_config']);
    self::assertSame('REGULAR', $diagnostic['canonical_site']['type']);
    self::assertFalse($diagnostic['canonical_site']['is_symlink']);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $diagnostic['canonical_site']['sha256']);
    self::assertSame('SYMLINK', $diagnostic['enabled_site']['type']);
    self::assertTrue($diagnostic['enabled_site']['is_symlink']);
    self::assertSame('/etc/nginx/sites-available/agency-preprod', $diagnostic['enabled_site']['resolved_path']);
    self::assertSame('CANONICAL_SYMLINK', $diagnostic['enabled_site']['classification']);
    self::assertTrue($diagnostic['canonical_equals_enabled']);
    self::assertTrue($diagnostic['enabled_equals_loaded']);
    self::assertSame('PROVEN', $diagnostic['include_chain']['status']);
    self::assertTrue($diagnostic['include_chain']['sites_enabled_included']);
    self::assertSame(['/etc/nginx/sites-enabled/*'], $diagnostic['include_chain']['directives']);
    self::assertSame(1, $diagnostic['loaded_preprod_tls_match_count']);
    self::assertSame(1, $diagnostic['loaded_preprod_http_match_count']);
    self::assertSame('NONE', $diagnostic['duplicate_tls']);
    self::assertSame('BOUND', $diagnostic['candidate']['status']);
    self::assertSame($diagnostic['enabled_site']['sha256'], $diagnostic['candidate']['source_sha']);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $diagnostic['candidate']['candidate_sha']);
    self::assertSame('server-1', $diagnostic['candidate']['target_server']);
    self::assertTrue($diagnostic['candidate']['target_tls']);
  }

  /**
   * Proves a regular enabled copy with equal bytes is explicitly equivalent.
   */
  public function testEnabledRegularCopyWithEqualShaIsEquivalent(): void {
    $this->replaceEnabledWithCanonicalCopy();
    $diagnostic = $this->diagnose();

    self::assertSame('PASS', $diagnostic['status']);
    self::assertSame('REGULAR', $diagnostic['enabled_site']['type']);
    self::assertFalse($diagnostic['enabled_site']['is_symlink']);
    self::assertSame('EQUIVALENT_COPY', $diagnostic['enabled_site']['classification']);
    self::assertTrue($diagnostic['canonical_equals_enabled']);
    self::assertSame('BOUND', $diagnostic['candidate']['status']);
  }

  /**
   * Proves divergent enabled bytes cannot be used for canonical simulation.
   */
  public function testEnabledRegularCopyWithDifferentShaIsDivergent(): void {
    $this->replaceEnabledWithCanonicalCopy();
    file_put_contents(
      $this->physical('/etc/nginx/sites-enabled/agency-preprod'),
      "\n# deterministic divergence\n",
      FILE_APPEND,
    );

    $diagnostic = $this->diagnose();
    self::assertSame('DIVERGENT', $diagnostic['enabled_site']['classification']);
    self::assertFalse($diagnostic['canonical_equals_enabled']);
    self::assertSame('CANDIDATE_SOURCE_MISMATCH', $diagnostic['status']);
    self::assertSame('CANDIDATE_SOURCE_MISMATCH', $diagnostic['candidate']['status']);
    self::assertSame('UNPROVEN', $diagnostic['candidate']['candidate_sha']);
  }

  /**
   * Proves an unexpected enabled symlink target fails closed.
   */
  public function testUnexpectedEnabledSymlinkTargetFailsClosed(): void {
    @unlink($this->physical('/etc/nginx/sites-enabled/agency-preprod'));
    $this->write('/etc/nginx/sites-available/unexpected', "server { listen 443 ssl; server_name example.invalid; }\n");
    self::assertTrue(symlink(
      '../sites-available/unexpected',
      $this->physical('/etc/nginx/sites-enabled/agency-preprod'),
    ));

    $diagnostic = $this->diagnose();
    self::assertSame('UNEXPECTED_SYMLINK_TARGET', $diagnostic['enabled_site']['classification']);
    self::assertSame('FAIL_CLOSED', $diagnostic['status']);
    self::assertSame('CANDIDATE_SOURCE_MISMATCH', $diagnostic['candidate']['status']);
  }

  /**
   * Proves a missing enabled site is explicit and fail closed.
   */
  public function testMissingEnabledSiteFailsClosed(): void {
    @unlink($this->physical('/etc/nginx/sites-enabled/agency-preprod'));

    $diagnostic = $this->diagnose();
    self::assertSame('ABSENT', $diagnostic['enabled_site']['presence']);
    self::assertSame('ABSENT', $diagnostic['enabled_site']['classification']);
    self::assertSame('FAIL_CLOSED', $diagnostic['status']);
    self::assertFalse($diagnostic['include_chain']['sites_enabled_included']);
  }

  /**
   * Proves canonical site is not claimed loaded when the include is absent.
   */
  public function testIncludeChainAbsentIsUnproven(): void {
    $this->writeMain('include /etc/nginx/conf.d/*.conf;');

    $diagnostic = $this->diagnose();
    self::assertSame('UNPROVEN', $diagnostic['status']);
    self::assertSame('NOT_LOADED', $diagnostic['include_chain']['status']);
    self::assertFalse($diagnostic['include_chain']['sites_enabled_included']);
    self::assertFalse($diagnostic['enabled_equals_loaded']);
  }

  /**
   * Proves one bounded nested include is followed deterministically.
   */
  public function testNestedIncludeChainIsSupported(): void {
    $this->writeMain('include /etc/nginx/conf.d/*.conf;');
    $this->write('/etc/nginx/conf.d/preprod-loader.conf', "include /etc/nginx/sites-enabled/*;\n");

    $diagnostic = $this->diagnose();
    self::assertSame('PASS', $diagnostic['status']);
    self::assertSame('PROVEN', $diagnostic['include_chain']['status']);
    self::assertSame([
      '/etc/nginx/conf.d/*.conf',
      '/etc/nginx/sites-enabled/*',
    ], $diagnostic['include_chain']['directives']);
  }

  /**
   * Proves multiple loaded TLS hostname matches remain ambiguous.
   */
  public function testDuplicateLoadedTlsHostnameFailsClosedAsAmbiguous(): void {
    $this->writeMain(
      "include /etc/nginx/sites-enabled/*;\ninclude /etc/nginx/conf.d/*.conf;",
    );
    $source = file_get_contents($this->repositoryPath(self::SOURCE_FIXTURE));
    self::assertIsString($source);
    $this->write('/etc/nginx/conf.d/duplicate-preprod.conf', $source);

    $diagnostic = $this->diagnose();
    self::assertSame('AMBIGUOUS', $diagnostic['status']);
    self::assertSame(2, $diagnostic['loaded_preprod_tls_match_count']);
    self::assertSame('AMBIGUOUS', $diagnostic['duplicate_tls']);
    self::assertSame('AMBIGUOUS_TLS', $diagnostic['candidate']['status']);
    self::assertFalse($diagnostic['candidate']['target_tls']);
  }

  /**
   * Proves runtime -c wins and compiled conf-path is the bounded fallback.
   */
  public function testMainConfigIdentityUsesRuntimeOverrideThenCompiledDefault(): void {
    self::assertSame(
      '/etc/nginx/custom.conf',
      $this->mainConfig(
        'nginx: master process /usr/sbin/nginx -c /etc/nginx/custom.conf -g daemon on;',
        'nginx version: nginx/1.26 --conf-path=/etc/nginx/nginx.conf',
      ),
    );
    self::assertSame(
      '/etc/nginx/nginx.conf',
      $this->mainConfig(
        'nginx: master process /usr/sbin/nginx -g daemon on;',
        'configure arguments: --prefix=/usr/share/nginx --conf-path=/etc/nginx/nginx.conf',
      ),
    );
  }

  /**
   * Proves local HTTP(S) diagnostics bypass proxies and verify loopback.
   */
  public function testPlanRequiresNoProxyResolveAndRemoteIpProof(): void {
    $plan = file_get_contents($this->repositoryPath(self::PLAN));
    $workflow = file_get_contents($this->repositoryPath(self::WORKFLOW));
    self::assertIsString($plan);
    self::assertIsString($workflow);

    self::assertStringContainsString("--noproxy '*' --resolve \"\$HOSTNAME:443:127.0.0.1\"", $plan);
    self::assertStringContainsString("--noproxy '*' --resolve \"\$HOSTNAME:80:127.0.0.1\"", $plan);
    self::assertSame(2, substr_count($plan, "--noproxy '*'"));
    self::assertStringContainsString("%{http_code}|%{remote_ip}", $plan);
    self::assertStringContainsString("HTTP remote IP is not loopback", $plan);
    self::assertStringContainsString("HTTPS remote IP is not loopback", $plan);
    self::assertStringContainsString('nginx-active-config-identity.py', $workflow);
    self::assertStringContainsString('.STATE.http.local_https.remote_ip == "127.0.0.1"', $workflow);
    self::assertStringContainsString('.STATE.http.local_http.remote_ip == "127.0.0.1"', $workflow);

    foreach (['nginx -T', 'find /etc/nginx', 'find /', 'env |', 'printenv'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $plan);
    }
  }

  /**
   * Runs the active identity helper and returns decoded output.
   *
   * @return array<string, mixed>
   *   Bounded diagnostic.
   */
  private function diagnose(): array {
    [$exitCode, $stdout, $stderr] = $this->runHelper([
      'diagnose',
      $this->root,
      '/etc/nginx/nginx.conf',
      '/etc/nginx/sites-available/agency-preprod',
      '/etc/nginx/sites-enabled/agency-preprod',
      $this->repositoryPath(self::ROUTE),
      $this->repositoryPath(self::TEMPLATE),
      self::HOSTNAME,
      self::MACHINE_PATH,
    ]);
    self::assertSame(0, $exitCode, $stderr);
    $decoded = json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Resolves a bounded main config identity from runtime/compiler metadata.
   */
  private function mainConfig(string $master, string $version): string {
    [$exitCode, $stdout, $stderr] = $this->runHelper([
      'main-config',
      $master,
      $version,
    ]);
    self::assertSame(0, $exitCode, $stderr);
    return trim($stdout);
  }

  /**
   * Runs the #1250 Python helper.
   *
   * @param list<string> $arguments
   *   Helper arguments.
   *
   * @return array{0:int,1:string,2:string}
   *   Exit code, stdout and stderr.
   */
  private function runHelper(array $arguments): array {
    $process = new Process(
      array_merge(['python3', $this->repositoryPath(self::ACTIVE)], $arguments),
      $this->repositoryRoot(),
    );
    $exitCode = $process->run();
    return [$exitCode, $process->getOutput(), $process->getErrorOutput()];
  }

  /**
   * Replaces the enabled symlink with a regular byte-identical copy.
   */
  private function replaceEnabledWithCanonicalCopy(): void {
    @unlink($this->physical('/etc/nginx/sites-enabled/agency-preprod'));
    self::assertTrue(copy(
      $this->physical('/etc/nginx/sites-available/agency-preprod'),
      $this->physical('/etc/nginx/sites-enabled/agency-preprod'),
    ));
  }

  /**
   * Writes the top-level fixture config.
   */
  private function writeMain(string $insideHttp): void {
    $this->write(
      '/etc/nginx/nginx.conf',
      "events {}\nhttp {\n  " . str_replace("\n", "\n  ", $insideHttp) . "\n}\n",
    );
  }

  /**
   * Writes a fixture file under the bounded synthetic root.
   */
  private function write(string $virtual, string $content): void {
    $path = $this->physical($virtual);
    file_put_contents($path, $content);
  }

  /**
   * Creates a fixture directory.
   */
  private function mkdir(string $virtual): void {
    self::assertTrue(mkdir($this->physical($virtual), 0700, TRUE));
  }

  /**
   * Maps a virtual absolute path into the synthetic test root.
   */
  private function physical(string $virtual): string {
    return $this->root . $virtual;
  }

  /**
   * Returns the repository root.
   */
  private function repositoryRoot(): string {
    return dirname(DRUPAL_ROOT);
  }

  /**
   * Returns an absolute repository path.
   */
  private function repositoryPath(string $relative): string {
    return $this->repositoryRoot() . '/' . $relative;
  }

}
