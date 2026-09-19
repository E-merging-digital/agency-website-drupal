<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Protects the structural Nginx route diagnostic for Agency #1248.
 *
 * @group agency_project_tests
 */
final class PreprodCockpitTransport1248NginxDiagnosticTest extends TestCase {

  private const DIAGNOSTIC = 'scripts/preproduction-cockpit-transport-1218/nginx-effective-route-diagnostic.py';

  private const TEMPLATE = 'scripts/preproduction/nginx-agency-preprod.conf.template';

  private const APPLY = 'scripts/preproduction-cockpit-transport-1218/remote-apply-root.sh';

  private const APPLY_WORKFLOW = '.github/workflows/preprod-cockpit-transport-1218-apply.yml';

  private const FIXTURES = 'web/modules/custom/agency_project_tests/tests/fixtures/nginx-1248';

  private const HOSTNAME = 'preprod.emergingdigital.be';

  private const MACHINE_PATH = '/api/agency-operations/v1/environment-data-state';

  /**
   * Proves server-block identification is independent from file order.
   */
  public function testTlsAndHttpServerIdentificationIsOrderIndependent(): void {
    $tlsFirst = $this->diagnose('tls-first.conf');
    self::assertSame('server-1', $tlsFirst['effective_preprod_tls_server_block']);
    self::assertSame('server-2', $tlsFirst['effective_preprod_http_server_block']);
    self::assertTrue($tlsFirst['legacy_first_root_insertion_is_tls']);
    self::assertSame('server-1', $tlsFirst['corrected_candidate_server_block']);

    $httpFirst = $this->diagnose('http-first.conf');
    self::assertSame('server-2', $httpFirst['effective_preprod_tls_server_block']);
    self::assertSame('server-1', $httpFirst['effective_preprod_http_server_block']);
    self::assertFalse($httpFirst['legacy_first_root_insertion_is_tls']);
    self::assertSame('server-1', $httpFirst['legacy_first_root_insertion_server_block']);
    self::assertSame('server-2', $httpFirst['corrected_candidate_server_block']);
    self::assertTrue($httpFirst['corrected_candidate_is_tls']);
  }

  /**
   * Proves the old first-root algorithm can target the HTTP server.
   */
  public function testLegacyFirstRootInsertionDefectIsReproducedBeforeCorrection(): void {
    $diagnostic = $this->diagnose('http-first.conf');

    self::assertSame('server-1', $diagnostic['legacy_first_root_insertion_server_block']);
    self::assertFalse($diagnostic['legacy_first_root_insertion_is_tls']);
    self::assertSame('server-2', $diagnostic['effective_preprod_tls_server_block']);
    self::assertSame('SIMULATED', $diagnostic['candidate_simulation_status']);
    self::assertSame(0, $diagnostic['simulated_candidate_route_counts']['server-1']);
    self::assertSame(1, $diagnostic['simulated_candidate_route_counts']['server-2']);
  }

  /**
   * Proves the simulated canonical route is structurally complete in TLS.
   */
  public function testSimulatedCandidateContractIsBoundedAndComplete(): void {
    $diagnostic = $this->diagnose('certbot-shaped.conf');
    $contract = $diagnostic['simulated_candidate_contract'];

    self::assertSame('server-1', $diagnostic['effective_preprod_tls_server_block']);
    self::assertSame('server-2', $diagnostic['effective_preprod_http_server_block']);
    self::assertSame('ON', $diagnostic['server_blocks'][0]['server_auth_basic']);
    self::assertTrue($diagnostic['server_blocks'][1]['redirects']);
    self::assertTrue($diagnostic['server_blocks'][1]['fixed_status']);
    self::assertSame('404', $diagnostic['server_blocks'][1]['return_status']);
    self::assertSame('SERVER_AND_IF', $diagnostic['server_blocks'][1]['redirect_source']);
    self::assertTrue($contract['auth_basic_off']);
    self::assertSame('OFF', $contract['location_auth_basic']);
    self::assertTrue($contract['http_authorization_forwarded']);
    self::assertSame('EXPECTED', $contract['script_filename']);
    self::assertSame('EXPECTED', $contract['fastcgi_pass']);

    $encoded = json_encode($diagnostic, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('/etc/letsencrypt', $encoded);
    self::assertStringNotContainsString('Agency PREPROD', $encoded);
    self::assertStringNotContainsString('.htpasswd', $encoded);
  }

  /**
   * Proves structural insertion writes only into the intended TLS server.
   */
  public function testStructuralInsertionTargetsTlsServerWhenHttpComesFirst(): void {
    $temporary = $this->temporaryFixture('http-first.conf');

    try {
      [$exitCode] = $this->runHelper([
        'insert',
        $temporary,
        $this->repositoryPath(self::TEMPLATE),
        self::HOSTNAME,
        self::MACHINE_PATH,
      ]);
      self::assertSame(0, $exitCode);

      $diagnostic = $this->diagnosePath($temporary);
      self::assertSame(0, $diagnostic['server_blocks'][0]['exact_machine_route_count']);
      self::assertSame(1, $diagnostic['server_blocks'][1]['exact_machine_route_count']);
      self::assertSame('OFF', $diagnostic['machine_location_contracts']['server-2']['location_auth_basic']);
      self::assertTrue($diagnostic['machine_location_contracts']['server-2']['http_authorization_forwarded']);
    }
    finally {
      @unlink($temporary);
    }
  }

  /**
   * Proves APPLY is wired to structural TLS insertion, not first-root regex.
   */
  public function testApplyUsesStructuralTlsInsertionHelper(): void {
    $apply = file_get_contents($this->repositoryPath(self::APPLY));
    $workflow = file_get_contents($this->repositoryPath(self::APPLY_WORKFLOW));
    self::assertIsString($apply);
    self::assertIsString($workflow);

    self::assertStringContainsString('python3 "$NGINX_DIAGNOSTIC" insert', $apply);
    self::assertStringContainsString('nginx-effective-route-diagnostic.py', $workflow);
    self::assertStringNotContainsString("marker = re.search(r'(?m)^\\s*location\\s+/\\s*\\{'", $apply);
    self::assertStringNotContainsString('live[:marker.start()] + block + live[marker.start():]', $apply);
  }

  /**
   * Proves deterministic machine-location classifications.
   */
  public function testMachineLocationClassificationsAreDeterministic(): void {
    $absent = $this->diagnose('tls-first.conf');
    self::assertSame(0, $absent['server_blocks'][0]['exact_machine_route_count']);

    $canonical = $this->insertedTlsFixture();
    try {
      $present = $this->diagnosePath($canonical);
      self::assertSame(1, $present['server_blocks'][0]['exact_machine_route_count']);
      self::assertSame('OFF', $present['machine_location_contracts']['server-1']['location_auth_basic']);

      $config = file_get_contents($canonical);
      self::assertIsString($config);
      $route = $this->machineRoute($config);

      $duplicate = $this->temporaryContent(str_replace($route, $route . "\n" . $route, $config));
      try {
        $diagnostic = $this->diagnosePath($duplicate);
        self::assertSame(2, $diagnostic['server_blocks'][0]['exact_machine_route_count']);
        self::assertSame(2, $diagnostic['machine_location_contracts']['server-1']['count']);
      }
      finally {
        @unlink($duplicate);
      }

      $inheritedRoute = preg_replace('/^\s*auth_basic\s+off;\s*$/m', '', $route, 1);
      self::assertIsString($inheritedRoute);
      $inherited = $this->temporaryContent(str_replace($route, $inheritedRoute, $config));
      try {
        $diagnostic = $this->diagnosePath($inherited);
        self::assertSame('ON', $diagnostic['server_blocks'][0]['server_auth_basic']);
        self::assertSame('ABSENT', $diagnostic['machine_location_contracts']['server-1']['location_auth_basic']);
        self::assertFalse($diagnostic['machine_location_contracts']['server-1']['auth_basic_off']);
      }
      finally {
        @unlink($inherited);
      }

      $missingAuthorizationRoute = preg_replace(
        '/^\s*fastcgi_param\s+HTTP_AUTHORIZATION\s+\$http_authorization;\s*$/m',
        '',
        $route,
        1,
      );
      self::assertIsString($missingAuthorizationRoute);
      $missingAuthorization = $this->temporaryContent(str_replace($route, $missingAuthorizationRoute, $config));
      try {
        $diagnostic = $this->diagnosePath($missingAuthorization);
        self::assertFalse($diagnostic['machine_location_contracts']['server-1']['http_authorization_forwarded']);
      }
      finally {
        @unlink($missingAuthorization);
      }
    }
    finally {
      @unlink($canonical);
    }

    $wrongHttp = $this->temporaryFixture('http-first.conf');
    try {
      $config = file_get_contents($wrongHttp);
      self::assertIsString($config);
      $route = $this->canonicalRoute();
      $wrong = preg_replace('/(?m)^\s*location\s+\/\s*\{/', $route . "\n\n    location / {", $config, 1);
      self::assertIsString($wrong);
      file_put_contents($wrongHttp, $wrong);
      $diagnostic = $this->diagnosePath($wrongHttp);
      self::assertSame(1, $diagnostic['server_blocks'][0]['exact_machine_route_count']);
      self::assertSame(0, $diagnostic['server_blocks'][1]['exact_machine_route_count']);
    }
    finally {
      @unlink($wrongHttp);
    }
  }

  /**
   * Proves redirect metadata is normalized without query-string leakage.
   */
  public function testRedirectClassificationIsBoundedAndDeterministic(): void {
    self::assertEquals(
      ['status' => '301', 'location_header_kind' => 'scheme', 'normalized_path' => '/route'],
      $this->redirect('301', 'https://preprod.emergingdigital.be/route?token=secret', 'http'),
    );
    self::assertEquals(
      ['status' => '301', 'location_header_kind' => 'language-prefix', 'normalized_path' => '/fr/route'],
      $this->redirect('301', '/fr/route?token=secret', 'https'),
    );
    self::assertEquals(
      ['status' => '301', 'location_header_kind' => 'same-host', 'normalized_path' => '/other'],
      $this->redirect('301', 'https://preprod.emergingdigital.be/other?token=secret', 'https'),
    );
    self::assertEquals(
      ['status' => '301', 'location_header_kind' => 'host-change', 'normalized_path' => '/other'],
      $this->redirect('301', 'https://example.invalid/other?token=secret', 'https'),
    );
  }

  /**
   * Proves a TLS server return deterministically classifies Nginx redirect.
   */
  public function testRedirectOriginDetectsTlsServerReturn(): void {
    $source = file_get_contents($this->fixturePath('tls-first.conf'));
    self::assertIsString($source);
    $modified = preg_replace(
      '/server_name preprod\.emergingdigital\.be;/',
      "server_name preprod.emergingdigital.be;\n    return 301 /fr/;",
      $source,
      1,
      $count,
    );
    self::assertSame(1, $count);
    self::assertIsString($modified);

    $temporary = $this->temporaryContent($modified);
    try {
      [$exitCode, $stdout, $stderr] = $this->runHelper([
        'origin',
        $temporary,
        self::HOSTNAME,
        self::MACHINE_PATH,
        '301',
      ]);
      self::assertSame(0, $exitCode, $stderr);
      self::assertSame('NGINX_REDIRECT', trim($stdout));
    }
    finally {
      @unlink($temporary);
    }
  }

  /**
   * Proves redirect origin stays unknown without structural evidence.
   */
  public function testRedirectOriginRemainsUnknownWithoutStructuralReturn(): void {
    [$exitCode, $stdout] = $this->runHelper([
      'origin',
      $this->fixturePath('certbot-shaped.conf'),
      self::HOSTNAME,
      self::MACHINE_PATH,
      '301',
    ]);
    self::assertSame(0, $exitCode);
    self::assertSame('UNKNOWN', trim($stdout));
  }

  /**
   * Returns decoded structural diagnostic output for a fixture.
   *
   * @return array<string, mixed>
   *   Diagnostic object.
   */
  private function diagnose(string $fixture): array {
    return $this->diagnosePath($this->fixturePath($fixture));
  }

  /**
   * Returns decoded structural diagnostic output for a path.
   *
   * @return array<string, mixed>
   *   Diagnostic object.
   */
  private function diagnosePath(string $path): array {
    [$exitCode, $stdout, $stderr] = $this->runHelper([
      'diagnose',
      $path,
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
   * Runs one redirect classification.
   *
   * @return array<string, string>
   *   Bounded redirect metadata.
   */
  private function redirect(string $status, string $location, string $scheme): array {
    [$exitCode, $stdout, $stderr] = $this->runHelper([
      'redirect',
      $status,
      $location,
      self::HOSTNAME,
      $scheme,
    ]);
    self::assertSame(0, $exitCode, $stderr);
    $decoded = json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Creates a temporary copy of a deterministic Nginx fixture.
   */
  private function temporaryFixture(string $fixture): string {
    $source = file_get_contents($this->fixturePath($fixture));
    self::assertIsString($source);
    return $this->temporaryContent($source);
  }

  /**
   * Creates a temporary Nginx file from provided contents.
   */
  private function temporaryContent(string $content): string {
    $path = tempnam(sys_get_temp_dir(), 'agency-1248-');
    self::assertIsString($path);
    file_put_contents($path, $content);
    return $path;
  }

  /**
   * Returns a temporary fixture with the canonical route inserted in TLS.
   */
  private function insertedTlsFixture(): string {
    $path = $this->temporaryFixture('tls-first.conf');
    [$exitCode, , $stderr] = $this->runHelper([
      'insert',
      $path,
      $this->repositoryPath(self::TEMPLATE),
      self::HOSTNAME,
      self::MACHINE_PATH,
    ]);
    self::assertSame(0, $exitCode, $stderr);
    return $path;
  }

  /**
   * Extracts the canonical exact machine route from the template.
   */
  private function canonicalRoute(): string {
    $template = file_get_contents($this->repositoryPath(self::TEMPLATE));
    self::assertIsString($template);
    $matched = preg_match(
      '~(?ms)^\s*location\s*=\s*/api/agency-operations/v1/environment-data-state\s*\{.*?^\s*\}~',
      $template,
      $matches,
    );
    self::assertSame(1, $matched);
    return trim($matches[0]);
  }

  /**
   * Extracts the exact machine route from candidate config.
   */
  private function machineRoute(string $config): string {
    $matched = preg_match(
      '~(?ms)^\s*location\s*=\s*/api/agency-operations/v1/environment-data-state\s*\{.*?^\s*\}~',
      $config,
      $matches,
    );
    self::assertSame(1, $matched);
    return $matches[0];
  }

  /**
   * Runs the Python diagnostic helper.
   *
   * @param list<string> $arguments
   *   Helper arguments.
   *
   * @return array{0:int,1:string,2:string}
   *   Exit code, stdout and stderr.
   */
  private function runHelper(array $arguments): array {
    $process = new Process(
      array_merge(['python3', $this->repositoryPath(self::DIAGNOSTIC)], $arguments),
      $this->repositoryRoot(),
    );
    $exitCode = $process->run();
    return [$exitCode, $process->getOutput(), $process->getErrorOutput()];
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

  /**
   * Returns an absolute fixture path.
   */
  private function fixturePath(string $fixture): string {
    return $this->repositoryPath(self::FIXTURES . '/' . $fixture);
  }

}
