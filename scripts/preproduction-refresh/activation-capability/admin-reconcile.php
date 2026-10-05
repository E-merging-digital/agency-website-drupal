<?php

declare(strict_types=1);

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

if (PHP_SAPI !== 'cli') {
  throw new RuntimeException('CLI only.');
}

$name = 'preprod-admin';
$role_id = 'administrator';
$password = getenv('DRUPAL_ADMIN_PASSWORD');
if (!is_string($password) || $password === '') {
  throw new RuntimeException('Server-owned PREPROD admin secret unavailable.');
}
if (Role::load($role_id) === NULL) {
  throw new RuntimeException('Required PREPROD administrative role unavailable.');
}

$storage = \Drupal::entityTypeManager()->getStorage('user');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('name', $name)
  ->execute();
if (count($ids) > 1) {
  throw new RuntimeException('Fixed PREPROD admin identity is ambiguous.');
}

if ($ids === []) {
  $account = User::create([
    'name' => $name,
    'status' => 1,
  ]);
}
else {
  $account = $storage->load(reset($ids));
  if (!$account instanceof User) {
    throw new RuntimeException('Fixed PREPROD admin could not be loaded.');
  }
  $account->activate();
}

$account->setPassword($password);
if (!$account->hasRole($role_id)) {
  $account->addRole($role_id);
}
$account->save();

// Re-read and prove the fixed non-secret identity/access result.  The password
// is deliberately never printed or persisted outside Drupal's normal hash.
$reloaded = user_load_by_name($name);
if (!$reloaded instanceof User || !$reloaded->isActive() || !$reloaded->hasRole($role_id)) {
  throw new RuntimeException('Fixed PREPROD admin reconciliation proof failed.');
}
