<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Couvre le vrai Webform de contact public du projet.
 *
 * @group agency_project_tests
 * @group contact_form
 */
#[RunTestsInSeparateProcesses]
#[Group('contact_form')]
final class ContactFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Webform Contact construit depuis la configuration versionnée du projet.
   */
  private Webform $contactWebform;

  /**
   * Libellé de soumission porté par la configuration canonique testée.
   */
  private string $submitLabel;

  /**
   * Première ligne du message de confirmation canonique testé.
   */
  private string $confirmationText;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config_file = dirname(DRUPAL_ROOT) . '/config/sync/webform.webform.contact.yml';
    self::assertFileExists($config_file);

    $configuration = Yaml::parseFile($config_file);
    self::assertIsArray($configuration);

    $elements = DrupalYaml::decode((string) ($configuration['elements'] ?? ''));
    self::assertIsArray($elements);
    $submit_label = $elements['actions']['#submit__label'] ?? NULL;
    self::assertIsString($submit_label);
    self::assertNotSame('', trim($submit_label));
    $this->submitLabel = $submit_label;

    $confirmation_message = $configuration['settings']['confirmation_message'] ?? NULL;
    self::assertIsString($confirmation_message);
    $confirmation_line = trim((string) strtok(
      str_replace(["\r\n", "\r"], "\n", $confirmation_message),
      "\n",
    ));
    self::assertNotSame('', $confirmation_line);
    $this->confirmationText = $confirmation_line;

    // Les handlers email appartiennent au comportement de production. Le test
    // fonctionnel vérifie le formulaire et la persistance sans envoyer d'email.
    foreach ($configuration['handlers'] ?? [] as &$handler) {
      $handler['status'] = FALSE;
    }
    unset($handler);

    $existing_webform = Webform::load('contact');
    if ($existing_webform) {
      $existing_webform->delete();
    }

    $this->contactWebform = Webform::create($configuration);
    $this->contactWebform->save();
  }

  /**
   * Vérifie affichage, validation invalide et soumission valide.
   */
  public function testContactFormValidationAndSubmit(): void {
    $this->drupalGet($this->contactWebform->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->fieldExists('email');
    $this->assertSession()->fieldExists('subject');
    $this->assertSession()->fieldExists('message');
    $this->assertSession()->fieldExists('rgpd_consent');
    $this->assertSession()->buttonExists($this->submitLabel);

    $this->submitForm([
      'name' => 'Test Contact',
      'email' => 'email-invalide',
      'subject' => 'Sujet test',
      'message' => 'Message test',
      'rgpd_consent' => '1',
    ], $this->submitLabel);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '[role="alert"]');
    self::assertSame(0, $this->countContactSubmissions());

    $this->submitForm([
      'name' => 'Test Contact',
      'email' => 'contact@example.com',
      'subject' => 'Sujet valide',
      'message' => 'Message valide',
      'rgpd_consent' => '1',
    ], $this->submitLabel);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->confirmationText);
    self::assertSame(1, $this->countContactSubmissions());
  }

  /**
   * Compte les soumissions persistées du Webform Contact.
   */
  private function countContactSubmissions(): int {
    return (int) \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', 'contact')
      ->count()
      ->execute();
  }

}
