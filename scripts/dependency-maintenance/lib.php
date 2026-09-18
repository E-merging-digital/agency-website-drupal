<?php

declare(strict_types=1);

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

function dm_classify_update(?string $installed, ?string $available): string
{
    if ($installed === null || $available === null) {
        return 'UNKNOWN';
    }
    $installed = ltrim(trim($installed), 'v');
    $available = ltrim(trim($available), 'v');
    if ($installed === $available) {
        return 'NONE';
    }
    $pattern = '/^(\d+)\.(\d+)(?:\.(\d+))?([+-].*)?$/';
    if (!preg_match($pattern, $installed, $from) || !preg_match($pattern, $available, $to)) {
        return 'UNKNOWN';
    }
    if ((int) $from[1] !== (int) $to[1]) {
        return 'MAJOR';
    }
    if ((int) $from[2] !== (int) $to[2]) {
        return 'MINOR';
    }
    if ((int) ($from[3] ?? 0) !== (int) ($to[3] ?? 0)) {
        return 'PATCH';
    }
    if (($from[4] ?? '') !== ($to[4] ?? '')) {
        return 'PRERELEASE';
    }
    return 'UNKNOWN';
}

function dm_is_newer(string $candidate, string $installed): bool
{
    try {
        return Comparator::greaterThan(ltrim($candidate, 'v'), ltrim($installed, 'v'));
    }
    catch (Throwable) {
        return false;
    }
}

function dm_stability_rank(string $version): int
{
    return match (VersionParser::parseStability($version)) {
        'stable' => 4,
        'RC' => 3,
        'beta' => 2,
        'alpha' => 1,
        default => 0,
    };
}

function dm_collect_constraints(array $composer, array $lock, string $package): array
{
    $constraints = [];
    foreach (['require' => 'production', 'require-dev' => 'dev'] as $section => $scope) {
        if (isset($composer[$section][$package])) {
            $constraints[] = [
                'source' => 'root',
                'source_version' => null,
                'scope' => $scope,
                'constraint' => (string) $composer[$section][$package],
            ];
        }
    }
    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $locked) {
            if (isset($locked['require'][$package])) {
                $constraints[] = [
                    'source' => (string) $locked['name'],
                    'source_version' => (string) ($locked['version'] ?? ''),
                    'scope' => 'dependency',
                    'constraint' => (string) $locked['require'][$package],
                ];
            }
        }
    }
    return $constraints;
}

function dm_constraint_satisfied(string $version, array $constraint): bool
{
    if (($constraint['constraint'] ?? '') === 'self.version') {
        $sourceVersion = (string) ($constraint['source_version'] ?? '');
        if ($sourceVersion === '') {
            return false;
        }
        try {
            return Comparator::equalTo(ltrim($version, 'v'), ltrim($sourceVersion, 'v'));
        }
        catch (Throwable) {
            return false;
        }
    }
    try {
        return Semver::satisfies(ltrim($version, 'v'), (string) $constraint['constraint']);
    }
    catch (Throwable) {
        return false;
    }
}

function dm_satisfies_all(string $version, array $constraints): bool
{
    foreach ($constraints as $constraint) {
        if (!dm_constraint_satisfied($version, $constraint)) {
            return false;
        }
    }
    return true;
}

function dm_external_blockers(string $version, array $constraints): array
{
    $blocked = [];
    foreach ($constraints as $constraint) {
        if ($constraint['scope'] !== 'dependency') {
            continue;
        }
        if (!dm_constraint_satisfied($version, $constraint)) {
            $blocked[] = [
                'source' => $constraint['source'],
                'constraint' => $constraint['constraint'],
            ];
        }
    }
    return $blocked;
}

function dm_root_constraint_change_required(string $version, array $constraints): bool
{
    foreach ($constraints as $constraint) {
        if ($constraint['source'] !== 'root') {
            continue;
        }
        if (!dm_constraint_satisfied($version, $constraint)) {
            return true;
        }
    }
    return false;
}

function dm_compatible_version(string $installed, string $latest, array $constraints, array $versions): string
{
    if (dm_satisfies_all($latest, $constraints)) {
        return $latest;
    }
    $minimumStability = dm_stability_rank($installed);
    $best = $installed;
    foreach ($versions as $candidate) {
        if (!is_string($candidate) || str_contains($candidate, 'dev')) {
            continue;
        }
        if (dm_stability_rank($candidate) < $minimumStability || !dm_satisfies_all($candidate, $constraints)) {
            continue;
        }
        if (dm_is_newer($candidate, $best)) {
            $best = $candidate;
        }
    }
    return $best;
}

function dm_advisories_by_package(array $audit): array
{
    $result = [];
    foreach (($audit['advisories'] ?? []) as $package => $items) {
        if (is_string($package) && is_array($items)) {
            $result[$package] = array_values($items);
        }
        elseif (is_array($items)) {
            $name = $items['packageName'] ?? $items['package'] ?? null;
            if (is_string($name)) {
                $result[$name][] = $items;
            }
        }
    }
    return $result;
}

function dm_abandoned_by_package(array $audit): array
{
    $result = [];
    foreach (($audit['abandoned'] ?? []) as $package => $replacement) {
        if (is_string($package)) {
            $result[$package] = is_string($replacement) ? $replacement : null;
        }
        elseif (is_string($replacement)) {
            $result[$replacement] = null;
        }
    }
    return $result;
}

function dm_health(string $updateClass, int $securityCount, bool $abandoned, array $externalBlockers, bool $unknown = false): string
{
    if ($securityCount > 0) {
        return 'SECURITY';
    }
    if ($abandoned) {
        return 'ABANDONED';
    }
    if ($externalBlockers !== []) {
        return 'BLOCKED';
    }
    if ($unknown || $updateClass === 'UNKNOWN') {
        return 'UNKNOWN';
    }
    return $updateClass === 'NONE' ? 'OK' : 'UPDATE_AVAILABLE';
}

function dm_recommended_action(string $health, string $updateClass): string
{
    return match ($health) {
        'SECURITY' => 'SECURITY_UPDATE_REQUIRED',
        'ABANDONED' => 'REPLACE_ABANDONED_PACKAGE',
        'BLOCKED' => 'WAIT_FOR_CONSTRAINT_COMPATIBILITY',
        'UNKNOWN' => 'INVESTIGATE_UNKNOWN_STATE',
        'UPDATE_AVAILABLE' => match ($updateClass) {
            'PATCH', 'PRERELEASE' => 'REVIEW_PATCH_UPDATE',
            'MINOR' => 'REVIEW_MINOR_UPDATE',
            'MAJOR' => 'REVIEW_MAJOR_UPDATE',
            default => 'INVESTIGATE_UNKNOWN_STATE',
        },
        default => 'NONE',
    };
}

function dm_impact(string $health): string
{
    return match ($health) {
        'SECURITY' => 'Installed dependency is affected by a security advisory.',
        'ABANDONED' => 'Installed dependency is reported abandoned by Composer.',
        'BLOCKED' => 'A newer version exists but another locked project dependency blocks it.',
        'UPDATE_AVAILABLE' => 'A newer version is visible for human review.',
        'UNKNOWN' => 'Source metadata was insufficient for a deterministic conclusion.',
        default => 'No maintenance action is currently indicated.',
    };
}

function dm_overall_health(array $components): string
{
    $priority = ['OK' => 0, 'UNKNOWN' => 1, 'UPDATE_AVAILABLE' => 2, 'BLOCKED' => 3, 'ABANDONED' => 4, 'SECURITY' => 5];
    $overall = 'OK';
    foreach ($components as $component) {
        $health = $component['health'] ?? 'UNKNOWN';
        if (($priority[$health] ?? 1) > ($priority[$overall] ?? 0)) {
            $overall = $health;
        }
    }
    return $overall;
}

function dm_validate_snapshot(array $snapshot): void
{
    foreach (['schema_version', 'project', 'repository', 'repository_sha', 'collected_at_utc', 'overall', 'execution_capability', 'components'] as $key) {
        if (!array_key_exists($key, $snapshot)) {
            throw new RuntimeException("Missing snapshot key: {$key}");
        }
    }
    if ($snapshot['schema_version'] !== 1 || $snapshot['execution_capability'] !== 'read_only') {
        throw new RuntimeException('Invalid snapshot contract.');
    }
    if (!preg_match('/^[0-9a-f]{40}$/', (string) $snapshot['repository_sha']) || !is_array($snapshot['components'])) {
        throw new RuntimeException('Invalid snapshot repository identity/components.');
    }
    $required = ['component', 'ecosystem', 'installed_version', 'available_version', 'compatible_available_version', 'latest_available_version', 'update_class', 'health', 'source', 'impact', 'recommended_action', 'execution_capability'];
    foreach ($snapshot['components'] as $component) {
        foreach ($required as $key) {
            if (!array_key_exists($key, $component)) {
                throw new RuntimeException("Missing component key: {$key}");
            }
        }
        if ($component['execution_capability'] !== 'read_only') {
            throw new RuntimeException('Component execution capability must be read_only.');
        }
    }
}

function dm_secret_scan(array $snapshot): void
{
    $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $patterns = [
        '/COMPOSER_AUTH/i',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/github_pat_[A-Za-z0-9_]+/',
        '/gh[pousr]_[A-Za-z0-9]+/',
        '/\bsk-[A-Za-z0-9]{16,}\b/',
        '/(?:mysql|mariadb|postgres(?:ql)?):\/\//i',
        '/\b(?:password|passwd|secret|api[_-]?key|private[_-]?key)\b\s*[:=]/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $json)) {
            throw new RuntimeException("Sensitive material pattern detected: {$pattern}");
        }
    }
}
