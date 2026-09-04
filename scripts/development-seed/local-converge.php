<?php

declare(strict_types=1);

/**
 * Local-only post-import access creation and side-effect assertions.
 */

use Drupal\Core\Site\Settings;
use Drupal\user\UserInterface;

if (PHP_SAPI !== 'cli') {
  throw new RuntimeException('CLI only.');
}
if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('Development Seed convergence is DDEV-only.');
}
if (Settings::get('agency_external_ai_egress_enabled', FALSE) !== FALSE) {
  throw new RuntimeException('External AI/provider egress must remain disabled locally.');
}
if ((bool) \Drupal::config('config_split.config_split.production')->get('status') !== FALSE) {
  throw new RuntimeException('Production Config Split is active locally.');
}
if ((bool) \Drupal::config('config_split.config_split.preproduction')->get('status') !== FALSE) {
  throw new RuntimeException('PREPROD Config Split is active locally.');
}
if ((bool) \Drupal::config('google_tag.container.default')->get('status') !== FALSE) {
  throw new RuntimeException('Analytics container is active locally.');
}
$mail = \Drupal::config('system.mail')->get('mailer_dsn');
if (!is_array($mail)
  || ($mail['scheme'] ?? NULL) !== 'native'
  || ($mail['host'] ?? NULL) !== 'default'
  || ($mail['user'] ?? NULL) !== NULL
  || ($mail['password'] ?? NULL) !== NULL) {
  throw new RuntimeException('Local mail transport is not the secret-free DDEV/native baseline.');
}

$db = \Drupal::database();
$schema = $db->schema();
foreach (['sessions', 'webform_submission', 'webform_submission_data', 'flood', 'watchdog', 'queue'] as $table) {
  if ($schema->tableExists($table)) {
    $count = (int) $db->select($table, 't')->countQuery()->execute()->fetchField();
    if ($count !== 0) {
      throw new RuntimeException("Unsafe imported runtime state survived locally: {$table}");
    }
  }
}

$storage = \Drupal::entityTypeManager()->getStorage('user');
$existing = $storage->loadByProperties(['name' => 'agency-local-admin']);
foreach ($existing as $account) {
  if ($account instanceof UserInterface) {
    $account->delete();
  }
}

/** @var \Drupal\user\UserInterface $admin */
$admin = $storage->create([
  'name' => 'agency-local-admin',
  'mail' => 'agency-local-admin@example.invalid',
  'status' => 1,
  'pass' => bin2hex(random_bytes(32)),
]);
$admin->addRole('administrator');
$admin->save();
if (!$admin->isActive() || !$admin->hasRole('administrator')) {
  throw new RuntimeException('Local administrator creation failed.');
}

fwrite(STDOUT, "LOCAL_ADMIN=LOCAL_ONLY\n");
fwrite(STDOUT, "MAIL_SAFE=PASS\n");
fwrite(STDOUT, "ANALYTICS_SAFE=PASS\n");
fwrite(STDOUT, "PROVIDER_EGRESS_SAFE=PASS\n");
fwrite(STDOUT, "EXTERNAL_ACTION_STATE=PASS\n");
