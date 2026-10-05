<?php

declare(strict_types=1);

namespace Drupal\user\Entity {
  final class Role {
    public static function load(string $id): ?self {
      return $id === 'administrator' ? new self() : NULL;
    }
  }

  class User {
    public string $name = '';
    public bool $active = FALSE;
    public array $roles = [];
    public string $password = '';

    public static function create(array $values): self {
      $u = new self();
      $u->name = (string) ($values['name'] ?? '');
      $u->active = ((int) ($values['status'] ?? 0)) === 1;
      $GLOBALS['agency915_created'] = TRUE;
      return $u;
    }
    public function activate(): void { $this->active = TRUE; }
    public function setPassword(string $password): void { $this->password = $password; }
    public function hasRole(string $role): bool { return in_array($role, $this->roles, TRUE); }
    public function addRole(string $role): void { if (!$this->hasRole($role)) { $this->roles[] = $role; } }
    public function save(): void { $GLOBALS['agency915_saved_user'] = $this; }
    public function isActive(): bool { return $this->active; }
  }
}

namespace {
  use Drupal\user\Entity\User;

  final class Agency915Query {
    public function accessCheck(bool $value): self { return $this; }
    public function condition(string $field, string $value): self { return $this; }
    public function execute(): array {
      return $GLOBALS['agency915_mode'] === 'present' ? [1 => 1] : [];
    }
  }
  final class Agency915Storage {
    public function getQuery(): Agency915Query { return new Agency915Query(); }
    public function load(int|string $id): ?User { return $GLOBALS['agency915_existing_user'] ?? NULL; }
  }
  final class Agency915EntityTypeManager {
    public function getStorage(string $type): Agency915Storage {
      if ($type !== 'user') { throw new RuntimeException('Unexpected storage type'); }
      return new Agency915Storage();
    }
  }
  final class Drupal {
    public static function entityTypeManager(): Agency915EntityTypeManager { return new Agency915EntityTypeManager(); }
  }
  function user_load_by_name(string $name): ?User {
    $u = $GLOBALS['agency915_saved_user'] ?? NULL;
    return $u instanceof User && $u->name === $name ? $u : NULL;
  }

  $mode = $argv[1] ?? '';
  if (!in_array($mode, ['absent', 'present'], TRUE)) { exit(64); }
  $GLOBALS['agency915_mode'] = $mode;
  $GLOBALS['agency915_created'] = FALSE;
  if ($mode === 'present') {
    $existing = new User();
    $existing->name = 'preprod-admin';
    $existing->active = FALSE;
    $existing->roles = [];
    $existing->password = 'old-value';
    $GLOBALS['agency915_existing_user'] = $existing;
  }
  putenv('DRUPAL_ADMIN_PASSWORD=synthetic-preprod-only-secret');
  ob_start();
  require __DIR__ . '/admin-reconcile.php';
  $output = ob_get_clean();
  if ($output !== '') { throw new RuntimeException('Admin reconcile emitted output'); }
  $saved = $GLOBALS['agency915_saved_user'] ?? NULL;
  if (!$saved instanceof User) { throw new RuntimeException('User was not saved'); }
  if ($saved->name !== 'preprod-admin' || !$saved->isActive() || !$saved->hasRole('administrator')) {
    throw new RuntimeException('Fixed PREPROD admin invariants not reconciled');
  }
  if ($saved->password !== 'synthetic-preprod-only-secret') { throw new RuntimeException('Password not reconciled'); }
  if ($mode === 'absent' && $GLOBALS['agency915_created'] !== TRUE) { throw new RuntimeException('Absent account not created'); }
  if ($mode === 'present' && $GLOBALS['agency915_created'] !== FALSE) { throw new RuntimeException('Present account unexpectedly recreated'); }
  echo $mode === 'absent' ? "ADMIN_ABSENT_RECONCILIATION=PASS\n" : "ADMIN_PRESENT_RECONCILIATION=PASS\n";
  echo "ADMIN_FIXED_IDENTITY=preprod-admin\n";
  echo "ADMIN_SECRET_LOGGING=NONE\n";
}
