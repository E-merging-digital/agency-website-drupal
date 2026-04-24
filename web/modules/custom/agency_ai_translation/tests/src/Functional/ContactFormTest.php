<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_ai_translation\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Couvre le formulaire de contact public (fallback si Webform absent).
 *
 * @group agency_ai_translation
 */
#[RunTestsInSeparateProcesses]
final class ContactFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'contact',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Vérifie affichage, cas invalide et cas valide.
   */
  public function testContactFormValidationAndSubmit(): void {
    $this->drupalGet('/contact');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->fieldExists('mail');
    $this->assertSession()->fieldExists('subject[0][value]');
    $this->assertSession()->fieldExists('message[0][value]');

    $this->submitForm([
      'name' => 'Test Contact',
      'mail' => 'email-invalide',
      'subject[0][value]' => 'Sujet test',
      'message[0][value]' => 'Message test',
    ], 'Send message');
    $this->assertSession()->pageTextContains('mail is not valid');

    $this->submitForm([
      'name' => 'Test Contact',
      'mail' => 'contact@example.com',
      'subject[0][value]' => 'Sujet valide',
      'message[0][value]' => 'Message valide',
    ], 'Send message');
    $this->assertSession()->pageTextContains('Your message has been sent.');
  }

}

