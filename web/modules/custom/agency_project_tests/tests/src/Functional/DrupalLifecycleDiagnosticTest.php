<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use Drupal\Core\Form\FormState;
use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers the bounded Drupal lifecycle diagnostic MVP.
 *
 * @group agency_project_tests
 * @group drupal_lifecycle_diagnostic
 */
#[RunTestsInSeparateProcesses]
#[Group('drupal_lifecycle_diagnostic')]
final class DrupalLifecycleDiagnosticTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'honeypot',
    'webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Diagnostic Webform built from the repository configuration.
   */
  private Webform $diagnosticWebform;

  /**
   * Submit label from canonical configuration.
   */
  private string $submitLabel;

  /**
   * Confirmation text from canonical configuration.
   */
  private string $confirmationText;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $configuration = $this->loadProjectYaml(
      'config/sync/webform.webform.drupal_lifecycle_diagnostic.yml',
    );
    $elements = DrupalYaml::decode((string) ($configuration['elements'] ?? ''));
    self::assertIsArray($elements);

    $submit_label = $elements['actions']['#submit__label'] ?? NULL;
    self::assertIsString($submit_label);
    self::assertNotSame('', trim($submit_label));
    $this->submitLabel = $submit_label;

    $confirmation_message = $configuration['settings']['confirmation_message'] ?? NULL;
    self::assertIsString($confirmation_message);
    self::assertNotSame('', trim($confirmation_message));
    $this->confirmationText = trim($confirmation_message);

    // Internal mail delivery is configuration behavior, not part of the
    // browser submission proof.
    foreach ($configuration['handlers'] ?? [] as &$handler) {
      $handler['status'] = FALSE;
    }
    unset($handler);

    $existing_webform = Webform::load('drupal_lifecycle_diagnostic');
    if ($existing_webform) {
      $existing_webform->delete();
    }

    $this->diagnosticWebform = Webform::create($configuration);
    $this->diagnosticWebform->save();
  }

  /**
   * Proves config contract, translations, block reuse and bounded visibility.
   */
  public function testConfigurationContract(): void {
    $configuration = $this->loadProjectYaml(
      'config/sync/webform.webform.drupal_lifecycle_diagnostic.yml',
    );
    self::assertSame('drupal_lifecycle_diagnostic', $configuration['id']);
    self::assertSame('open', $configuration['status']);

    $elements = DrupalYaml::decode((string) $configuration['elements']);
    self::assertIsArray($elements);
    self::assertSame([
      'intro',
      'website_url',
      'organization',
      'name',
      'email',
      'message',
      'audit_note',
      'rgpd_consent',
      'actions',
    ], array_keys($elements));

    foreach (['website_url', 'name', 'email', 'message', 'rgpd_consent'] as $required) {
      self::assertTrue((bool) ($elements[$required]['#required'] ?? FALSE));
    }
    self::assertArrayNotHasKey('#required', $elements['organization']);
    foreach (['drupal_version', 'budget', 'timeline', 'organization_type', 'phone', 'score'] as $forbidden) {
      self::assertArrayNotHasKey($forbidden, $elements);
    }
    self::assertStringContainsString('/en/privacy-policy', (string) $elements['rgpd_consent']['#description']);

    self::assertSame('page', $configuration['settings']['confirmation_type']);
    self::assertTrue((bool) $configuration['settings']['confirmation_exclude_query']);
    self::assertTrue((bool) $configuration['settings']['confirmation_exclude_token']);

    self::assertSame(['email_notification'], array_keys($configuration['handlers']));
    $notification = $configuration['handlers']['email_notification'];
    self::assertTrue((bool) $notification['status']);
    self::assertSame('contact@emergingdigital.be', $notification['settings']['to_mail']);
    foreach (['password', 'secret', 'api_key', 'access_token'] as $secret_key) {
      self::assertArrayNotHasKey($secret_key, $notification['settings']);
    }

    $fr = $this->loadProjectYaml(
      'config/sync/language/fr/webform.webform.drupal_lifecycle_diagnostic.yml',
    );
    $fr_elements = DrupalYaml::decode((string) $fr['elements']);
    self::assertIsArray($fr_elements);
    self::assertSame('URL du site', $fr_elements['website_url']['#title']);
    self::assertSame('Demander un diagnostic Drupal', $fr_elements['actions']['#submit__label']);
    self::assertStringContainsString(
      '/fr/politique-de-confidentialite',
      (string) $fr_elements['rgpd_consent']['#description'],
    );

    $definition = $this->container
      ->get('plugin.manager.block')
      ->getDefinition('webform_block');
    self::assertSame('webform_block', $definition['id']);

    $block = $this->loadProjectYaml(
      'config/sync/block.block.emerging_digital_drupal_lifecycle_diagnostic.yml',
    );
    self::assertSame('webform_block', $block['plugin']);
    self::assertSame('content', $block['region']);
    self::assertSame('drupal_lifecycle_diagnostic', $block['settings']['webform_id']);
    self::assertFalse((bool) $block['settings']['redirect']);
    self::assertFalse((bool) $block['settings']['lazy']);

    $main_block = $this->loadProjectYaml(
      'config/sync/block.block.emerging_digital_content.yml',
    );
    self::assertGreaterThan((int) $main_block['weight'], (int) $block['weight']);

    $visibility = $block['visibility']['request_path'];
    self::assertFalse((bool) $visibility['negate']);
    $paths = array_values(array_filter(array_map(
      'trim',
      preg_split('/\R/', (string) $visibility['pages']) ?: [],
    )));
    self::assertSame([
      '/fr/audit-drupal',
      '/fr/drupal-2027',
      '/drupal-2027',
      '/fr/migration-drupal',
      '/fr/refonte-site-drupal',
      '/en/drupal-audit',
      '/en/drupal-migration',
      '/en/drupal-website-redesign',
    ], $paths);
    foreach (['<front>', '/fr', '/en', '/fr/services', '/en/services', '/fr/contact', '/en/contact'] as $excluded) {
      self::assertNotContains($excluded, $paths);
    }

    $fr_block = $this->loadProjectYaml(
      'config/sync/language/fr/block.block.emerging_digital_drupal_lifecycle_diagnostic.yml',
    );
    self::assertSame('Demander un diagnostic Drupal', $fr_block['settings']['label']);

    $analytics = file_get_contents(dirname(DRUPAL_ROOT) . '/docs/analytics.md');
    self::assertIsString($analytics);
    self::assertStringContainsString('drupal_lifecycle_diagnostic', $analytics);
    self::assertStringContainsString('confirmation_exclude_token: true', $analytics);
    self::assertStringContainsString('page_view', $analytics);
    self::assertStringContainsString('sans envoyer `email`', $analytics);
  }

  /**
   * Proves invalid requests fail and a valid request persists without PII URL.
   */
  public function testValidationAndSubmission(): void {
    $this->drupalGet($this->diagnosticWebform->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    foreach (['website_url', 'organization', 'name', 'email', 'message', 'rgpd_consent'] as $field) {
      $this->assertSession()->fieldExists($field);
    }
    $this->assertSession()->buttonExists($this->submitLabel);

    $this->submitForm([
      'website_url' => 'not-a-url',
      'name' => 'Diagnostic Invalid',
      'email' => 'not-an-email',
      'message' => 'Invalid request.',
      'rgpd_consent' => '1',
    ], $this->submitLabel);
    $this->assertSession()->elementExists('css', '[role="alert"]');
    self::assertSame(0, $this->countDiagnosticSubmissions());

    // Reload the form so this scenario starts without the consent checkbox
    // state preserved from the intentionally invalid preceding submission.
    $this->drupalGet($this->diagnosticWebform->toUrl());
    $this->submitForm([
      'website_url' => 'https://example.com',
      'name' => 'Diagnostic Consent',
      'email' => 'contact@example.com',
      'message' => 'Consent intentionally missing.',
    ], $this->submitLabel);
    $this->assertSession()->elementExists('css', '[role="alert"]');
    self::assertSame(0, $this->countDiagnosticSubmissions());

    $this->submitForm([
      'website_url' => 'https://example.com',
      'organization' => 'Example SME',
      'name' => 'Diagnostic Valid',
      'email' => 'contact@example.com',
      'message' => 'We need a human-reviewed modernization diagnostic.',
      'rgpd_consent' => '1',
    ], $this->submitLabel);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->confirmationText);
    self::assertSame(1, $this->countDiagnosticSubmissions());

    $current_url = $this->getSession()->getCurrentUrl();
    self::assertStringContainsString('/confirmation', $current_url);
    self::assertStringNotContainsString('token=', $current_url);
    self::assertStringNotContainsString('contact%40example.com', $current_url);
    self::assertStringNotContainsString('example.com', parse_url($current_url, PHP_URL_QUERY) ?: '');
  }

  /**
   * Proves the existing Honeypot primitive protects exactly the intended forms.
   */
  public function testExistingHoneypotIntegrationProtectsDiagnostic(): void {
    require_once dirname(DRUPAL_ROOT)
      . '/web/modules/custom/emerging_digital_content/emerging_digital_content.module';

    foreach (['contact', 'drupal_lifecycle_diagnostic'] as $protected_webform) {
      $form = ['#webform_id' => $protected_webform];
      $form_state = new FormState();
      emerging_digital_content_form_alter(
        $form,
        $form_state,
        'webform_submission_' . $protected_webform . '_add_form',
      );
      self::assertArrayHasKey('url', $form);
      self::assertSame('textfield', $form['url']['#type']);
      self::assertNotEmpty($form['url']['#element_validate']);
    }

    $unrelated = ['#webform_id' => 'unrelated_webform'];
    $unrelated_state = new FormState();
    emerging_digital_content_form_alter(
      $unrelated,
      $unrelated_state,
      'webform_submission_unrelated_webform_add_form',
    );
    self::assertArrayNotHasKey('url', $unrelated);
  }

  /**
   * Loads one project YAML file.
   */
  private function loadProjectYaml(string $relative_path): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relative_path;
    self::assertFileExists($path);
    $configuration = Yaml::parseFile($path);
    self::assertIsArray($configuration);
    return $configuration;
  }

  /**
   * Counts persisted diagnostic submissions.
   */
  private function countDiagnosticSubmissions(): int {
    return (int) \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', 'drupal_lifecycle_diagnostic')
      ->count()
      ->execute();
  }

}
