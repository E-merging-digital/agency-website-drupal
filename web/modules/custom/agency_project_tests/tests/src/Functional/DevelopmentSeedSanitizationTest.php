<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Executes the real thin Development Seed sanitizer on synthetic Drupal data.
 *
 * Generic Drush/#914 sanitization is proven separately. This test models their
 * inherited safe baseline and proves only the additional development pass.
 *
 * @group agency_project_tests
 * @group development_seed
 */
#[RunTestsInSeparateProcesses]
final class DevelopmentSeedSanitizationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Development-only user/private/runtime state is removed deterministically.
   */
  public function testStricterDevelopmentSanitization(): void {
    $role = Role::create([
      'id' => 'seed_synthetic_role',
      'label' => 'Synthetic Development Seed Role',
    ]);
    $role->save();

    $account = User::create([
      'name' => 'agency-local-admin',
      'mail' => 'synthetic-person@example.invalid',
      'init' => 'synthetic-person@example.invalid',
      'status' => 1,
      // Model the inherited Drush/#914 guarantee: no transported password.
      'pass' => '',
    ]);
    $account->addRole('seed_synthetic_role');
    $account->save();
    $uid = (int) $account->id();
    self::assertGreaterThan(0, $uid);

    $db = \Drupal::database();
    $db->update('users_field_data')
      ->fields([
        'access' => 1700000000,
        'login' => 1700000001,
      ])
      ->condition('uid', $uid)
      ->execute();

    \Drupal::service('user.data')->set(
      'agency_seed_synthetic',
      $uid,
      'private_sentinel',
      'synthetic-private-value',
    );
    \Drupal::state()->set(
      'agency_seed.synthetic_runtime_state',
      'synthetic-runtime-value',
    );

    self::assertGreaterThan(
      0,
      (int) $db->select('user__roles', 'r')
        ->condition('entity_id', $uid)
        ->countQuery()
        ->execute()
        ->fetchField(),
    );
    self::assertGreaterThan(
      0,
      (int) $db->select('users_data', 'd')
        ->condition('uid', $uid)
        ->countQuery()
        ->execute()
        ->fetchField(),
    );
    self::assertGreaterThan(
      0,
      (int) $db->select('key_value', 'k')
        ->condition('collection', 'state')
        ->countQuery()
        ->execute()
        ->fetchField(),
    );

    $script = dirname(DRUPAL_ROOT) . '/scripts/development-seed/agency-development-sanitize.php';
    self::assertFileExists($script);
    include $script;

    self::assertSame(
      0,
      (int) $db->select('user__roles', 'r')
        ->countQuery()
        ->execute()
        ->fetchField(),
    );
    self::assertSame(
      0,
      (int) $db->select('users_data', 'd')
        ->countQuery()
        ->execute()
        ->fetchField(),
    );
    self::assertSame(
      0,
      (int) $db->select('key_value', 'k')
        ->condition('collection', 'state')
        ->countQuery()
        ->execute()
        ->fetchField(),
    );

    $row = $db->select('users_field_data', 'u')
      ->fields('u', ['name', 'mail', 'init', 'access', 'login'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($row);
    self::assertSame("dev-user-{$uid}", $row['name']);
    self::assertSame("dev-user+{$uid}@example.invalid", $row['mail']);
    self::assertSame("dev-user+{$uid}@example.invalid", $row['init']);
    self::assertSame(0, (int) $row['access']);
    self::assertSame(0, (int) $row['login']);

    self::assertSame(
      0,
      (int) $db->select('users_field_data', 'u')
        ->condition('name', 'agency-local-admin')
        ->countQuery()
        ->execute()
        ->fetchField(),
    );
  }

}
