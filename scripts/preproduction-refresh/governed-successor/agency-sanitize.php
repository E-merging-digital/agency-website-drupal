<?php

declare(strict_types=1);

/**
 * Thin Agency-only extension after Drush sql:sanitize.
 * Drush owns generic email/password/session sanitization.
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

// Project-specific sensitive/runtime data not generically owned by Drush.
foreach ([
  'webform_submission_data', 'webform_submission', 'flood', 'watchdog',
  'queue', 'batch', 'semaphore', 'key_value_expire',
] as $table) {
  $truncate($table);
}

// Cache state is non-authoritative and may contain request/user-derived data.
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchCol() as $table) {
  if (is_string($table) && preg_match('/^cache_[A-Za-z0-9_]+$/', $table)) {
    $truncate($table);
  }
}

// Drush sanitizes email/password but intentionally does not rename accounts.
if ($schema->tableExists('users_field_data')) {
  $db->update('users_field_data')
    ->expression('name', "CONCAT('preprod-user-', uid)")
    ->fields(['access' => 0, 'login' => 0])
    ->condition('uid', 0, '>')
    ->execute();
}

// Remove known persisted provider/key material. PREPROD canonical config is
// re-imported later under server-owned settings overrides.
if ($schema->tableExists('config')) {
  $db->delete('config')->condition('name', 'ai_provider_openai.settings')->execute();
  $db->delete('config')->condition('name', 'key.key.%', 'LIKE')->execute();
}
if ($schema->tableExists('key_value')) {
  $q = $db->delete('key_value')->condition('collection', 'state');
  $group = $q->orConditionGroup()
    ->condition('name', 'system.cron_last')
    ->condition('name', 'update.last_check')
    ->condition('name', 'update.available_releases')
    ->condition('name', 'announcements_feed.%', 'LIKE')
    ->condition('name', 'linkchecker.%', 'LIKE');
  $q->condition($group)->execute();
}

// Fail closed. Sessions are asserted only: Drush owns their truncation.
foreach (['sessions', 'webform_submission', 'webform_submission_data', 'flood', 'watchdog', 'queue'] as $table) {
  if ($schema->tableExists($table)) {
    $count = (int) $db->select($table, 't')->countQuery()->execute()->fetchField();
    if ($count !== 0) {
      throw new RuntimeException("Sensitive table was not emptied: {$table}");
    }
  }
}
if ($schema->tableExists('users_field_data')) {
  $bad = (int) $db->query("SELECT COUNT(*) FROM {users_field_data} WHERE uid > 0 AND (name NOT REGEXP '^preprod-user-[0-9]+$' OR mail NOT LIKE '%@example.invalid')")->fetchField();
  if ($bad !== 0) {
    throw new RuntimeException('Drush/Agency user sanitization assertion failed.');
  }
}
if ($schema->tableExists('config')) {
  $credentials = (int) $db->query("SELECT COUNT(*) FROM {config} WHERE name='ai_provider_openai.settings' OR name LIKE 'key.key.%'")->fetchField();
  if ($credentials !== 0) {
    throw new RuntimeException('Persisted provider/key configuration survived sanitization.');
  }
}

fwrite(STDOUT, "AGENCY_CUSTOM_SANITIZATION=PASS\n");
fwrite(STDOUT, "DRUSH_GENERIC_SANITIZATION=REUSED\n");
fwrite(STDOUT, "SESSIONS_GENERIC_ASSERTION=PASS\n");
fwrite(STDOUT, "PERSISTED_PROVIDER_CREDENTIALS=ABSENT\n");
