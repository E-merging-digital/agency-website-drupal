<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_ai_translation\Functional;

use Drupal\contact\Entity\ContactForm;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
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
   * Utilisateur autorisé à accéder au formulaire de contact global.
   *
   * @var \Drupal\user\UserInterface
   */
  private UserInterface $contactUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!ContactForm::load('feedback')) {
      ContactForm::create([
        'id' => 'feedback',
        'label' => 'Website feedback',
        'recipients' => ['contact@example.com'],
        'reply' => '',
        'weight' => 0,
      ])->save();
    }

    $this->contactUser = $this->drupalCreateUser([
      'access site-wide contact form',
      'access user profiles',
    ]);
  }

  /**
   * Vérifie affichage, cas invalide et cas valide.
   */
  public function testContactFormValidationAndSubmit(): void {
    $this->drupalLogin($this->contactUser);

    $this->drupalGet('/contact/feedback');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('subject[0][value]');
    $this->assertSession()->fieldExists('message[0][value]');
    $this->assertSession()->buttonExists('Send message');

    $this->submitForm([
      'subject[0][value]' => '',
      'message[0][value]' => 'Message test',
    ], 'Send message');
    $this->assertSession()->pageTextContains('Subject field is required.');

    $this->submitForm([
      'subject[0][value]' => 'Sujet valide',
      'message[0][value]' => 'Message valide',
    ], 'Send message');
    $this->assertSession()->pageTextContains('Your message has been sent.');
  }

}
