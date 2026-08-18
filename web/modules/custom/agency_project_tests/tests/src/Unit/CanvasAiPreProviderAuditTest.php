<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Audits the exact installed Canvas AI defaults before provider-backed proof.
 *
 * @group agency_project_tests
 * @group canvas_ai_pre_provider
 */
final class CanvasAiPreProviderAuditTest extends TestCase {

  /**
   * Canvas AI upstream and the Agency proof policy must agree fail closed.
   */
  public function testInstalledCanvasAiDefaultsMatchBoundedProofPolicy(): void {
    $projectRoot = dirname(DRUPAL_ROOT);
    $policyPath = $projectRoot . '/docs/ai/canvas-ai-proof-policy.yml';
    self::assertFileExists($policyPath);
    $policy = Yaml::parseFile($policyPath);
    self::assertIsArray($policy);
    self::assertSame('pre_provider', $policy['status'] ?? NULL);
    self::assertSame('forbidden', $policy['runtime']['provider_call'] ?? NULL);
    self::assertSame(
      'derive_from_installed_info_yml',
      $policy['runtime']['dependency_closure'] ?? NULL,
    );
    self::assertSame(
      'forbidden_until_pre_provider_gate',
      $policy['proof']['provider_call'] ?? NULL,
    );

    self::assertTrue(InstalledVersions::isInstalled('drupal/canvas'));
    self::assertTrue(InstalledVersions::isInstalled('drupal/ai_agents'));
    self::assertTrue(InstalledVersions::isInstalled('drupal/modeler_api'));
    $canvasVersion = InstalledVersions::getPrettyVersion('drupal/canvas');
    $agentsVersion = InstalledVersions::getPrettyVersion('drupal/ai_agents');
    $modelerVersion = InstalledVersions::getPrettyVersion('drupal/modeler_api');
    self::assertNotNull($canvasVersion);
    self::assertNotNull($agentsVersion);
    self::assertNotNull($modelerVersion);
    self::assertMatchesRegularExpression(
      '/^1\.10\./',
      ltrim($canvasVersion, 'v'),
    );
    self::assertMatchesRegularExpression(
      '/^1\.3\./',
      ltrim($agentsVersion, 'v'),
    );
    self::assertMatchesRegularExpression(
      '/^1\.1\./',
      ltrim($modelerVersion, 'v'),
    );

    $canvasRoot = DRUPAL_ROOT . '/modules/contrib/canvas';
    self::assertDirectoryExists($canvasRoot);
    $agentsRoot = InstalledVersions::getInstallPath('drupal/ai_agents');
    self::assertNotNull($agentsRoot);
    self::assertDirectoryExists($agentsRoot);

    $coreExtensionPath = $projectRoot . '/config/sync/core.extension.yml';
    self::assertFileExists($coreExtensionPath);
    $coreExtension = Yaml::parseFile($coreExtensionPath);
    self::assertIsArray($coreExtension);
    $enabledModules = $coreExtension['module'] ?? [];
    self::assertIsArray($enabledModules);
    foreach ($policy['runtime']['enabled_modules'] as $module) {
      self::assertArrayHasKey(
        $module,
        $enabledModules,
        sprintf('Required PRE-PROVIDER module %s is not canonical.', $module),
      );
    }

    $aiAgentsInfoPath = $agentsRoot . '/ai_agents.info.yml';
    self::assertFileExists($aiAgentsInfoPath);
    $aiAgentsInfo = Yaml::parseFile($aiAgentsInfoPath);
    self::assertIsArray($aiAgentsInfo);
    $aiAgentsDependencies = $aiAgentsInfo['dependencies'] ?? [];
    self::assertIsArray($aiAgentsDependencies);
    $this->assertDrupalDependenciesEnabled(
      $aiAgentsDependencies,
      $enabledModules,
      'AI Agents',
    );

    $agentConfigs = $this->findAgentConfigs($canvasRoot);
    self::assertNotEmpty(
      $agentConfigs,
      'Canvas AI agent defaults were not found.',
    );

    $byLabel = [];
    foreach ($agentConfigs as $path => $config) {
      $label = $config['label'] ?? NULL;
      if (is_string($label) && $label !== '') {
        $byLabel[$label] = [
          'path' => $path,
          'config' => $config,
        ];
      }
    }

    foreach ($policy['execution']['allowed_agent_labels'] as $label) {
      self::assertArrayHasKey($label, $byLabel);
    }
    foreach ($policy['execution']['forbidden_agent_labels'] as $label) {
      self::assertArrayHasKey(
        $label,
        $byLabel,
        sprintf(
          'Expected upstream agent %s was not found for exclusion.',
          $label,
        ),
      );
    }

    $pageBuilderLabel = $policy['execution']['allowed_agent_labels'][0];
    $pageBuilder = $byLabel[$pageBuilderLabel]['config'];
    $pageBuilderScalars = $this->flattenScalars($pageBuilder);
    foreach ($policy['execution']['required_mutation_tools'] as $tool) {
      self::assertContains(
        $tool,
        $pageBuilderScalars,
        sprintf(
          'Required upstream Page Builder tool %s is unavailable.',
          $tool,
        ),
      );
    }

    $catalogPath = $projectRoot . '/docs/design-system/component-catalog.yml';
    self::assertFileExists($catalogPath);
    $catalog = Yaml::parseFile($catalogPath);
    self::assertIsArray($catalog);
    $catalogComponents = $catalog['components'] ?? [];
    self::assertIsArray($catalogComponents);

    $canvasAllowlist = [];
    foreach ($policy['components']['allowlist'] as $component) {
      self::assertIsArray($component);
      $canvasId = $component['canvas_id'] ?? NULL;
      $catalogId = $component['catalog_id'] ?? NULL;
      self::assertIsString($canvasId);
      self::assertIsString($catalogId);
      self::assertStringStartsWith('sdc.', $canvasId);
      self::assertArrayHasKey($catalogId, $catalogComponents);
      self::assertSame(
        $policy['components']['required_catalog_status'],
        $catalogComponents[$catalogId]['status'] ?? NULL,
      );
      self::assertSame(
        $policy['components']['required_ai_composable'],
        $catalogComponents[$catalogId]['approved_for_ai_composition'] ?? NULL,
      );
      $canvasAllowlist[] = $canvasId;
    }
    self::assertSame($canvasAllowlist, $policy['proof']['expected_order']);

    $canvasAiInfo = $this->findCanvasAiInfo($canvasRoot);
    self::assertFileExists($canvasAiInfo);
    $info = Yaml::parseFile($canvasAiInfo);
    self::assertIsArray($info);
    $canvasAiDependencies = $info['dependencies'] ?? [];
    self::assertIsArray($canvasAiDependencies);
    self::assertTrue(
      $this->dependencyListContainsModule($canvasAiDependencies, 'ai_agents'),
      'Canvas AI no longer declares AI Agents as a dependency.',
    );
    $this->assertDrupalDependenciesEnabled(
      $canvasAiDependencies,
      $enabledModules,
      'Canvas AI',
    );

    $audit = [
      'canvas_version' => $canvasVersion,
      'ai_agents_version' => $agentsVersion,
      'modeler_api_package_version' => $modelerVersion,
      'canonical_runtime_modules' => $policy['runtime']['enabled_modules'],
      'ai_agents_dependencies' => $aiAgentsDependencies,
      'canvas_ai_dependencies' => $canvasAiDependencies,
      'canvas_ai_info' => $this->relativePath($projectRoot, $canvasAiInfo),
      'agents' => [],
    ];
    foreach ($byLabel as $label => $agent) {
      $config = $agent['config'];
      $audit['agents'][] = [
        'id' => $config['id'] ?? NULL,
        'label' => $label,
        'path' => $this->relativePath($projectRoot, $agent['path']),
        'canvas_ai_tools' => array_values(array_unique(array_filter(
          $this->flattenScalars($config),
          static fn (mixed $value): bool => is_string($value)
            && str_starts_with($value, 'canvas_ai:'),
        ))),
        'hostname_settings' => $this->findMatchingKeys($config, 'hostname'),
      ];
    }

    fwrite(
      STDERR,
      'CANVAS_AI_PRE_PROVIDER_AUDIT='
      . json_encode(
        $audit,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
      )
      . PHP_EOL,
    );
  }

  /**
   * Finds every Canvas AI agent default configuration file.
   *
   * @return array<string, array<string, mixed>>
   *   Configurations keyed by absolute file path.
   */
  private function findAgentConfigs(string $canvasRoot): array {
    $configs = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($canvasRoot),
    );
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      if (!str_starts_with($file->getFilename(), 'ai_agents.ai_agent.')) {
        continue;
      }
      if ($file->getExtension() !== 'yml') {
        continue;
      }
      $parsed = Yaml::parseFile($file->getPathname());
      if (is_array($parsed)) {
        $configs[$file->getPathname()] = $parsed;
      }
    }
    return $configs;
  }

  /**
   * Finds the Canvas AI module info file.
   */
  private function findCanvasAiInfo(string $canvasRoot): string {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($canvasRoot),
    );
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo
        && $file->isFile()
        && $file->getFilename() === 'canvas_ai.info.yml') {
        return $file->getPathname();
      }
    }
    return $canvasRoot . '/canvas_ai.info.yml';
  }

  /**
   * Ensures every declared Drupal module dependency is canonical.
   *
   * @param array<int|string, mixed> $dependencies
   *   Drupal info dependencies.
   * @param array<string, mixed> $enabledModules
   *   Canonically enabled modules from core.extension.yml.
   * @param string $owner
   *   Human-readable module owner for assertion messages.
   */
  private function assertDrupalDependenciesEnabled(
    array $dependencies,
    array $enabledModules,
    string $owner,
  ): void {
    foreach ($dependencies as $dependency) {
      if (!is_string($dependency)) {
        continue;
      }
      $module = $this->dependencyModuleName($dependency);
      self::assertArrayHasKey(
        $module,
        $enabledModules,
        sprintf(
          '%s dependency %s is not enabled canonically.',
          $owner,
          $module,
        ),
      );
    }
  }

  /**
   * Checks a Drupal info dependency list for a module machine name.
   */
  private function dependencyListContainsModule(
    array $dependencies,
    string $module,
  ): bool {
    foreach ($dependencies as $dependency) {
      if (is_string($dependency)
        && $this->dependencyModuleName($dependency) === $module) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts a module machine name from a Drupal info dependency string.
   */
  private function dependencyModuleName(string $dependency): string {
    $withoutConstraint = trim(
      (string) preg_replace('/\s+\(.+$/', '', $dependency),
    );
    $parts = explode(':', $withoutConstraint, 2);
    return count($parts) === 2 ? $parts[1] : $parts[0];
  }

  /**
   * Flattens nested configuration values to scalars.
   *
   * @return list<mixed>
   *   Scalar values discovered recursively.
   */
  private function flattenScalars(mixed $value): array {
    if (!is_array($value)) {
      return [$value];
    }
    $values = [];
    foreach ($value as $child) {
      array_push($values, ...$this->flattenScalars($child));
    }
    return $values;
  }

  /**
   * Finds nested configuration keys containing a case-insensitive needle.
   *
   * @return array<string, mixed>
   *   Matching values keyed by dot-separated configuration path.
   */
  private function findMatchingKeys(
    mixed $value,
    string $needle,
    string $path = '',
  ): array {
    if (!is_array($value)) {
      return [];
    }
    $matches = [];
    foreach ($value as $key => $child) {
      $childPath = $path === '' ? (string) $key : $path . '.' . $key;
      if (str_contains(strtolower((string) $key), strtolower($needle))) {
        $matches[$childPath] = $child;
      }
      $matches += $this->findMatchingKeys($child, $needle, $childPath);
    }
    return $matches;
  }

  /**
   * Converts an absolute repository path into a relative path.
   */
  private function relativePath(string $root, string $path): string {
    $prefix = rtrim($root, '/') . '/';
    return str_starts_with($path, $prefix)
      ? substr($path, strlen($prefix))
      : $path;
  }

}
