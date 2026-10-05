<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Locked dependencies are required. Run composer install first.\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/lib.php';

$options = getopt('', ['output:']);
$output = $options['output'] ?? null;
if (!is_string($output) || $output === '') {
    fwrite(STDERR, "Usage: php collect.php --output=/path/to/snapshot.json\n");
    exit(2);
}

function dm_run(array $command, string $cwd, bool $allowNonZero = false): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 && !$allowNonZero) {
        throw new RuntimeException(sprintf(
            "Command failed (%d): %s\n%s",
            $code,
            implode(' ', $command),
            trim($stderr)
        ));
    }
    return [$code, $stdout, $stderr];
}

function dm_run_json(array $command, string $cwd, bool $allowNonZero = false): array
{
    [, $stdout] = dm_run($command, $cwd, $allowNonZero);
    $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Machine-readable command did not return an object/array.');
    }
    return $data;
}

function dm_composer_command(string $root): array
{
    $projectComposer = $root . '/vendor/composer/composer/bin/composer';
    return is_file($projectComposer) ? [PHP_BINARY, $projectComposer] : ['composer'];
}

function dm_lock_map(array $lock): array
{
    $map = [];
    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            $map[$package['name']] = $package;
        }
    }
    return $map;
}

function dm_direct_packages(array $composer): array
{
    $direct = [];
    foreach (['require' => 'production', 'require-dev' => 'dev'] as $section => $scope) {
        foreach ($composer[$section] ?? [] as $name => $constraint) {
            if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
                continue;
            }
            $direct[$name] = ['scope' => $scope, 'declared_constraint' => (string) $constraint];
        }
    }
    ksort($direct);
    return $direct;
}

function dm_metadata_versions(array $composerCommand, string $root, string $package): array
{
    $data = dm_run_json(
        [...$composerCommand, 'show', $package, '--all', '--format=json', '--no-interaction'],
        $root
    );
    return array_values(array_filter($data['versions'] ?? [], 'is_string'));
}

function dm_public_advisories(array $items): array
{
    $result = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $result[] = array_filter([
            'advisory_id' => $item['advisoryId'] ?? $item['advisory_id'] ?? null,
            'cve' => $item['cve'] ?? null,
            'title' => $item['title'] ?? null,
        ], static fn ($value) => is_string($value) && $value !== '');
    }
    return $result;
}

function dm_component(
    string $name,
    string $ecosystem,
    bool $direct,
    string $scope,
    ?string $declared,
    ?string $installed,
    ?string $compatible,
    ?string $latest,
    string $updateClass,
    string $health,
    bool $rootChange,
    array $blockers,
    array $security,
    ?string $abandonedReplacement,
    string $source
): array {
    return [
        'component' => $name,
        'ecosystem' => $ecosystem,
        'direct' => $direct,
        'scope' => $scope,
        'declared_constraint' => $declared,
        'installed_version' => $installed,
        'available_version' => $compatible,
        'compatible_available_version' => $compatible,
        'latest_available_version' => $latest,
        'update_class' => $updateClass,
        'compatible_update_class' => dm_classify_update($installed, $compatible),
        'health' => $health,
        'support_status' => 'UNKNOWN',
        'root_constraint_change_required' => $rootChange,
        'constraint_blockers' => $blockers,
        'security_advisories' => dm_public_advisories($security),
        'abandoned_replacement' => $abandonedReplacement,
        'source' => $source,
        'impact' => dm_impact($health),
        'recommended_action' => dm_recommended_action($health, $updateClass),
        'execution_capability' => 'read_only',
    ];
}

$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$packageJson = json_decode(file_get_contents($root . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
$packageLock = json_decode(file_get_contents($root . '/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);

$composerCommand = dm_composer_command($root);
$outdated = dm_run_json([
    ...$composerCommand,
    'outdated',
    '--locked',
    '--direct',
    '--format=json',
    '--no-interaction',
    '--ignore-platform-reqs',
], $root);
$audit = dm_run_json([
    ...$composerCommand,
    'audit',
    '--locked',
    '--format=json',
    '--no-interaction',
], $root, true);

$outdatedMap = [];
foreach ($outdated['locked'] ?? [] as $item) {
    if (isset($item['name'], $item['latest'])) {
        $outdatedMap[$item['name']] = $item;
    }
}
$advisories = dm_advisories_by_package($audit);
$abandoned = dm_abandoned_by_package($audit);
$lockMap = dm_lock_map($lock);
$components = [];

foreach (dm_direct_packages($composer) as $name => $directInfo) {
    $locked = $lockMap[$name] ?? null;
    $installed = is_array($locked) ? (string) ($locked['version'] ?? '') : null;
    $installed = $installed === '' ? null : $installed;
    $latest = isset($outdatedMap[$name]['latest'])
        ? (string) $outdatedMap[$name]['latest']
        : $installed;
    $constraints = dm_collect_constraints($composer, $lock, $name);
    $versions = [];
    if ($installed !== null && $latest !== null && !dm_satisfies_all($latest, $constraints)) {
        $versions = dm_metadata_versions($composerCommand, $root, $name);
    }
    $compatible = $installed;
    if ($installed !== null && $latest !== null) {
        $compatible = dm_compatible_version($installed, $latest, $constraints, $versions);
    }
    $updateClass = dm_classify_update($installed, $latest);
    $blockers = $latest !== null ? dm_external_blockers($latest, $constraints) : [];
    $securityItems = $advisories[$name] ?? [];
    $isAbandoned = array_key_exists($name, $abandoned);
    $health = dm_health(
        $updateClass,
        count($securityItems),
        $isAbandoned,
        $blockers,
        $installed === null || $latest === null
    );
    $components[] = dm_component(
        $name,
        str_starts_with($name, 'drupal/') ? 'drupal-composer' : 'composer',
        true,
        $directInfo['scope'],
        $directInfo['declared_constraint'],
        $installed,
        $compatible,
        $latest,
        $updateClass,
        $health,
        $latest !== null ? dm_root_constraint_change_required($latest, $constraints) : false,
        $blockers,
        $securityItems,
        $abandoned[$name] ?? null,
        'composer.lock + Composer metadata + composer audit'
    );
}

$directNames = array_column($components, 'component');
foreach (array_unique(array_merge(array_keys($advisories), array_keys($abandoned))) as $name) {
    if (in_array($name, $directNames, true)) {
        continue;
    }
    $locked = $lockMap[$name] ?? null;
    $installed = is_array($locked) ? (string) ($locked['version'] ?? '') : null;
    $securityItems = $advisories[$name] ?? [];
    $isAbandoned = array_key_exists($name, $abandoned);
    $health = dm_health('NONE', count($securityItems), $isAbandoned, [], $installed === null);
    $components[] = dm_component(
        $name,
        str_starts_with($name, 'drupal/') ? 'drupal-composer' : 'composer',
        false,
        'transitive-material-finding',
        null,
        $installed,
        $installed,
        $installed,
        'NONE',
        $health,
        false,
        [],
        $securityItems,
        $abandoned[$name] ?? null,
        'composer.lock + composer audit'
    );
}

$nodeDirect = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
foreach ($nodeDirect as $name => $declared) {
    $lockKey = 'node_modules/' . $name;
    $installed = $packageLock['packages'][$lockKey]['version']
        ?? $packageLock['packages']['']['devDependencies'][$name]
        ?? $packageLock['packages']['']['dependencies'][$name]
        ?? null;
    [, $npmStdout] = dm_run(['npm', 'view', $name, 'version', '--json'], $root, true);
    $npmDecoded = json_decode($npmStdout, true);
    $latest = is_string($npmDecoded) ? $npmDecoded : null;
    if (is_array($npmDecoded) && array_is_list($npmDecoded)) {
        $last = end($npmDecoded);
        $latest = is_string($last) ? $last : null;
    }
    $isExact = is_string($declared)
        && preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $declared) === 1;
    $compatible = $isExact ? $installed : ($latest ?? $installed);
    $updateClass = dm_classify_update(
        is_string($installed) ? $installed : null,
        $latest
    );
    $health = dm_health(
        $updateClass,
        0,
        false,
        [],
        $latest === null || !is_string($installed)
    );
    $components[] = dm_component(
        $name,
        'npm',
        true,
        isset($packageJson['devDependencies'][$name]) ? 'dev' : 'production',
        is_string($declared) ? $declared : null,
        is_string($installed) ? $installed : null,
        is_string($compatible) ? $compatible : null,
        $latest,
        $updateClass,
        $health,
        $isExact && $latest !== null && $latest !== $installed,
        [],
        [],
        null,
        'package-lock.json + npm metadata'
    );
}

usort(
    $components,
    static fn (array $a, array $b): int =>
        [$a['ecosystem'], $a['component']] <=> [$b['ecosystem'], $b['component']]
);

[, $sha] = dm_run(['git', 'rev-parse', 'HEAD'], $root);
$sha = trim($sha);
$securityCount = array_sum(array_map(
    static fn (array $component): int => count($component['security_advisories']),
    $components
));
$abandonedCount = count(array_filter(
    $components,
    static fn (array $component): bool => $component['health'] === 'ABANDONED'
));

$snapshot = [
    'schema_version' => 1,
    'project' => 'agency-website',
    'repository' => 'E-merging-digital/agency-website-drupal',
    'repository_sha' => $sha,
    'collected_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'overall' => dm_overall_health($components),
    'execution_capability' => 'read_only',
    'security' => [
        'advisory_count' => $securityCount,
        'state' => $securityCount > 0 ? 'SECURITY' : 'OK',
    ],
    'abandoned' => [
        'package_count' => $abandonedCount,
        'state' => $abandonedCount > 0 ? 'ABANDONED' : 'OK',
    ],
    'components' => $components,
];

dm_validate_snapshot($snapshot);
dm_secret_scan($snapshot);

$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException("Unable to create output directory: {$directory}");
}
file_put_contents(
    $output,
    json_encode(
        $snapshot,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n"
);

printf("SNAPSHOT_SCHEMA_VERSION=1\n");
printf("REPOSITORY_SHA=%s\n", $sha);
printf("OVERALL=%s\n", $snapshot['overall']);
printf("SECURITY_ADVISORIES=%d\n", $securityCount);
printf("ABANDONED_PACKAGES=%d\n", $abandonedCount);
printf("SECRET_SCAN=PASS\n");
