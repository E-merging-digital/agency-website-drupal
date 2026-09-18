<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/lib.php';

$tests = 0;

function dm_assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $tests;
    $tests++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

dm_assert_same('NONE', dm_classify_update('1.3.1', '1.3.1'), 'CURRENT/NONE classification');
dm_assert_same('PATCH', dm_classify_update('1.3.1', '1.3.2'), 'PATCH classification');
dm_assert_same('MINOR', dm_classify_update('1.3.2', '1.4.0'), 'MINOR classification');
dm_assert_same('MAJOR', dm_classify_update('8.3.31', '9.0.1'), 'MAJOR classification');
dm_assert_same(
    'PRERELEASE',
    dm_classify_update('1.5.0-rc1', '1.5.0-rc4'),
    'PRERELEASE classification'
);
dm_assert_same('UNKNOWN', dm_classify_update('dev-main', '1.0.0'), 'UNKNOWN classification');
dm_assert_same('OK', dm_health('NONE', 0, false, []), 'CURRENT/OK health');
dm_assert_same(
    true,
    dm_constraint_satisfied('11.4.7', [
        'constraint' => 'self.version',
        'source_version' => '11.4.7',
    ]),
    'Composer self.version matches source package version'
);
dm_assert_same(
    false,
    dm_constraint_satisfied('11.4.8', [
        'constraint' => 'self.version',
        'source_version' => '11.4.7',
    ]),
    'Composer self.version blocks a mismatched source package version'
);

$coder = json_decode(
    file_get_contents(__DIR__ . '/fixtures/coder-blocked.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$constraints = dm_collect_constraints($coder['composer'], $coder['lock'], 'drupal/coder');
$blockers = dm_external_blockers($coder['latest'], $constraints);
$compatible = dm_compatible_version(
    $coder['installed'],
    $coder['latest'],
    $constraints,
    $coder['versions']
);
dm_assert_same('8.3.31', $compatible, 'Coder compatible version remains 8.3.31');
dm_assert_same('drupal/core-dev', $blockers[0]['source'] ?? null, 'Coder external blocker');
dm_assert_same('^8.3.30', $blockers[0]['constraint'] ?? null, 'Coder blocker constraint');
dm_assert_same(
    'BLOCKED',
    dm_health('MAJOR', 0, false, $blockers),
    'Coder 9 is BLOCKED_MAJOR equivalent'
);
dm_assert_same(
    'WAIT_FOR_CONSTRAINT_COMPATIBILITY',
    dm_recommended_action('BLOCKED', 'MAJOR'),
    'Coder 9 is not auto-actionable'
);

$audit = json_decode(
    file_get_contents(__DIR__ . '/fixtures/audit-security-abandoned.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$advisories = dm_advisories_by_package($audit);
$abandoned = dm_abandoned_by_package($audit);
dm_assert_same(
    1,
    count($advisories['vendor/security-package'] ?? []),
    'Security advisory parser'
);
dm_assert_same(
    'SECURITY',
    dm_health('NONE', 1, false, []),
    'SECURITY health precedence'
);
dm_assert_same(
    'vendor/replacement',
    $abandoned['vendor/abandoned-package'] ?? null,
    'Abandoned parser'
);
dm_assert_same(
    'ABANDONED',
    dm_health('NONE', 0, true, []),
    'ABANDONED health'
);
dm_assert_same(
    'UNKNOWN',
    dm_health('UNKNOWN', 0, false, [], true),
    'UNKNOWN health'
);

$synthetic = [
    'schema_version' => 1,
    'project' => 'agency-website',
    'repository' => 'E-merging-digital/agency-website-drupal',
    'repository_sha' => str_repeat('a', 40),
    'collected_at_utc' => '2026-09-18T00:00:00Z',
    'overall' => 'OK',
    'execution_capability' => 'read_only',
    'components' => [[
        'component' => 'drupal/core-recommended',
        'ecosystem' => 'drupal-composer',
        'installed_version' => '11.4.7',
        'available_version' => '11.4.7',
        'compatible_available_version' => '11.4.7',
        'latest_available_version' => '11.4.7',
        'update_class' => 'NONE',
        'health' => 'OK',
        'source' => 'fixture',
        'impact' => 'none',
        'recommended_action' => 'NONE',
        'execution_capability' => 'read_only',
    ]],
];
dm_validate_snapshot($synthetic);
dm_secret_scan($synthetic);
$tests += 2;

$schema = json_decode(
    file_get_contents(dirname(__DIR__) . '/schema-v1.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$missingSchemaKeys = array_diff($schema['required'] ?? [], array_keys($synthetic));
dm_assert_same([], array_values($missingSchemaKeys), 'Schema required keys match validated snapshot');

printf("DEPENDENCY_MAINTENANCE_TESTS=PASS tests=%d\n", $tests);
