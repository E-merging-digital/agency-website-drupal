<?php

declare(strict_types=1);

/**
 * Thin development-only sanitization after Drush sql:sanitize + #914 sanitizer.
 */

use Drupal\Core\Database\Connection;

if (PHP_SAPI !== 'cli') {
  throw new RuntimeException('CLI only.');
}

/** @var Connection $db */
$db = \Drupal::database();
$schema = $db->schema();

$truncate = static function (string $table) use ($db, $schema): void {
  if ($schema->tableExists($table)) {
    $db->truncate($table)->execute();
  }
};

// Development distribution is stricter than PREPROD: imported users keep no
// roles, picture references, private users_data rows, or read-history state.
foreach (['user__roles', 'user__user_picture', 'users_data', 'history'] as $table) {
  $truncate($table);
}

// PREPROD keeps a bounded subset of runtime state for fidelity. A distributable
// development seed carries no imported runtime state collection at all.
if ($schema->tableExists('key_value')) {
  $db->delete('key_value')
    ->condition('collection', 'state')
    ->execute();
}

if ($schema->tableExists('users_field_data')) {
  $db->update('users_field_data')
    ->expression('name', "CONCAT('dev-user-', uid)")
    ->expression('mail', "CONCAT('dev-user+', uid, '@example.invalid')")
    ->expression('init', "CONCAT('dev-user+', uid, '@example.invalid')")
    ->fields(['access' => 0, 'login' => 0])
    ->condition('uid', 0, '>')
    ->execute();
}

// Drush + #914 own the generic/Agency sanitization. Development only asserts
// that those inherited guarantees still hold after its stricter pass.
foreach (['sessions', 'webform_submission', 'webform_submission_data', 'flood', 'watchdog', 'queue'] as $table) {
  if ($schema->tableExists($table)) {
    $count = (int) $db->select($table, 't')->countQuery()->execute()->fetchField();
    if ($count !== 0) {
      throw new RuntimeException("Inherited sensitive table is not empty: {$table}");
    }
  }
}

foreach (['user__roles', 'user__user_picture', 'users_data', 'history'] as $table) {
  if ($schema->tableExists($table)) {
    $count = (int) $db->select($table, 't')->countQuery()->execute()->fetchField();
    if ($count !== 0) {
      throw new RuntimeException("Development user state survived: {$table}");
    }
  }
}

if ($schema->tableExists('users_field_data')) {
  $bad = (int) $db->query(
    "SELECT COUNT(*) FROM {users_field_data} WHERE uid > 0 AND (name NOT REGEXP '^dev-user-[0-9]+$' OR mail NOT REGEXP '^dev-user\\+[0-9]+@example\\.invalid$' OR init NOT REGEXP '^dev-user\\+[0-9]+@example\\.invalid$' OR access <> 0 OR login <> 0)"
  )->fetchField();
  if ($bad !== 0) {
    throw new RuntimeException('Development user minimization assertion failed.');
  }
  $localAdmin = (int) $db->select('users_field_data', 'u')
    ->condition('name', 'agency-local-admin')
    ->countQuery()
    ->execute()
    ->fetchField();
  if ($localAdmin !== 0) {
    throw new RuntimeException('Local administrator must never be transported in a seed.');
  }
}

if ($schema->tableExists('config')) {
  $credentials = (int) $db->query(
    "SELECT COUNT(*) FROM {config} WHERE name='ai_provider_openai.settings' OR name LIKE 'key.key.%'"
  )->fetchField();
  if ($credentials !== 0) {
    throw new RuntimeException('Known credential-bearing configuration survived.');
  }
}

if ($schema->tableExists('key_value')) {
  $state = (int) $db->select('key_value', 'k')
    ->condition('collection', 'state')
    ->countQuery()
    ->execute()
    ->fetchField();
  if ($state !== 0) {
    throw new RuntimeException('Imported runtime state survived development sanitization.');
  }
}

fwrite(STDOUT, "DEVELOPMENT_SANITIZATION=PASS\n");
fwrite(STDOUT, "DRUSH_GENERIC_SANITIZATION=REUSED\n");
fwrite(STDOUT, "AGENCY_PREPROD_SANITIZER=REUSED\n");
fwrite(STDOUT, "LOCAL_ADMIN_IN_SEED=NONE\n");
