<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the #914 Agency sanitizer on synthetic Drupal user rows.
 *
 * @group agency_project_tests
 * @group preproduction_data_refresh
 * @group development_seed
 */
#[RunTestsInSeparateProcesses]
final class PreproductionAgencySanitizationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Exact username derivation remains deterministic and fail-closed.
   */
  public function testExactUsernameSanitizationContract(): void {
    $db = \Drupal::database();
    $first = User::create([
      'name' => 'synthetic-unsanitized-user',
      'mail' => 'synthetic-first@example.invalid',
      'init' => 'synthetic-first@example.invalid',
      'status' => 1,
      'pass' => '',
    ]);
    $first->save();
    $firstUid = (int) $first->id();
    self::assertGreaterThan(0, $firstUid);

    $second = User::create([
      'name' => 'synthetic-second-user',
      'mail' => 'synthetic-second@example.invalid',
      'init' => 'synthetic-second@example.invalid',
      'status' => 1,
      'pass' => '',
    ]);
    $second->save();
    $secondUid = (int) $second->id();
    self::assertGreaterThan($firstUid, $secondUid);

    $db->update('users_field_data')
      ->fields(['name' => "preprod-user-{$secondUid}"])
      ->condition('uid', $secondUid)
      ->execute();
    $firstRow = $db->select('users_field_data', 'u')
      ->fields('u')
      ->condition('uid', $firstUid)
      ->condition('default_langcode', 1)
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($firstRow);
    $translatedRow = $firstRow;
    $translatedRow['langcode'] = 'fr';
    $translatedRow['default_langcode'] = 0;
    $translatedRow['name'] = 'synthetic-french-user';
    $translatedRow['mail'] = 'synthetic-first-fr@example.invalid';
    $translatedRow['init'] = 'synthetic-first-fr@example.invalid';
    $db->insert('users_field_data')->fields($translatedRow)->execute();

    $uidZeroName = 'synthetic-uid-zero-boundary';
    $uidZeroCount = (int) $db->select('users_field_data', 'u')
      ->condition('uid', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($uidZeroCount === 0) {
      $db->insert('users_field_data')->fields([
        'uid' => 0,
        'langcode' => 'en',
        'name' => $uidZeroName,
        'created' => 0,
        'access' => 123,
        'login' => 456,
        'default_langcode' => 1,
      ])->execute();
    }
    else {
      $db->update('users_field_data')
        ->fields([
          'name' => $uidZeroName,
          'access' => 123,
          'login' => 456,
        ])
        ->condition('uid', 0)
        ->execute();
    }

    $db->update('users_field_data')
      ->fields(['access' => 123, 'login' => 456])
      ->expression('mail', "CONCAT('synthetic-user+', uid, '@example.invalid')")
      ->expression('init', "CONCAT('synthetic-user+', uid, '@example.invalid')")
      ->condition('uid', 0, '>')
      ->execute();

    $script = dirname(DRUPAL_ROOT)
      . '/scripts/preproduction-refresh/governed-successor/agency-sanitize.php';
    self::assertFileExists($script);
    include $script;

    $exactBad = (int) $db->query(
      "SELECT COUNT(*) FROM {users_field_data} WHERE uid > 0 AND name <> CONCAT('preprod-user-', uid)",
    )->fetchField();
    self::assertSame(0, $exactBad);

    $mailBad = (int) $db->query(
      "SELECT COUNT(*) FROM {users_field_data} WHERE uid > 0 AND mail NOT LIKE '%@example.invalid'",
    )->fetchField();
    self::assertSame(0, $mailBad);

    $positiveAccessLoginBad = (int) $db->query(
      'SELECT COUNT(*) FROM {users_field_data} WHERE uid > 0 AND (access <> 0 OR login <> 0)',
    )->fetchField();
    self::assertSame(0, $positiveAccessLoginBad);

    self::assertSame(
      2,
      (int) $db->select('users_field_data', 'u')
        ->condition('uid', $firstUid)
        ->countQuery()
        ->execute()
        ->fetchField(),
    );

    $uidZero = $db->select('users_field_data', 'u')
      ->fields('u', ['name', 'access', 'login'])
      ->condition('uid', 0)
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($uidZero);
    self::assertSame($uidZeroName, $uidZero['name']);
    self::assertSame(123, (int) $uidZero['access']);
    self::assertSame(456, (int) $uidZero['login']);

    $db->update('users_field_data')
      ->fields(['name' => 'preprod-user-synthetic-wrong'])
      ->condition('uid', $firstUid)
      ->condition('langcode', 'fr')
      ->execute();

    $exactBadAfterCorruption = (int) $db->query(
      "SELECT COUNT(*) FROM {users_field_data} WHERE uid > 0 AND name <> CONCAT('preprod-user-', uid)",
    )->fetchField();
    self::assertSame(1, $exactBadAfterCorruption);
  }

}
