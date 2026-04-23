<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration du module de traduction IA.
 */
final class AiTranslationSettingsForm extends ConfigFormBase {

  /**
   * State store.
   */
  protected StateInterface $state;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    StateInterface $state,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agency_ai_translation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['agency_ai_translation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('agency_ai_translation.settings');
    $fromSettings = Settings::get('agency_ai_translation.api_key');

    $form['description'] = [
      '#type' => 'item',
      '#markup' => $this->t('Priorité de résolution : (1) configuration provider OpenAI + module Key, (2) key_id défini ci-dessous, puis (3) fallback legacy settings.php / variable d’environnement / state.'),
    ];

    $form['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint Chat Completions'),
      '#default_value' => $config->get('endpoint') ?: 'https://api.openai.com/v1/chat/completions',
      '#required' => TRUE,
    ];

    $form['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Modèle'),
      '#default_value' => $config->get('model') ?: 'gpt-4o-mini',
      '#required' => TRUE,
    ];

    $form['openai_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key ID Drupal (module Key)'),
      '#default_value' => $config->get('openai_key_id') ?: 'openai_api_key',
      '#description' => $this->t('Identifiant de la clé dans le module Key (ex: openai_api_key).'),
      '#required' => TRUE,
    ];

    $form['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $config->get('system_prompt') ?: 'You are a professional website translator. Translate the source content into the requested target language while preserving meaning, tone, formatting, and calls-to-action. Return only translated text.',
      '#required' => TRUE,
    ];

    $form['api_key_fallback'] = [
      '#type' => 'password',
      '#title' => $this->t('Clé API fallback (state, non exportée)'),
      '#description' => $fromSettings ? $this->t('Une clé est déjà fournie via settings.php.') : $this->t('Optionnel. Utilisé uniquement si aucune clé n’est trouvée dans settings.php ni dans la variable d’environnement.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('agency_ai_translation.settings')
      ->set('endpoint', $form_state->getValue('endpoint'))
      ->set('model', $form_state->getValue('model'))
      ->set('openai_key_id', $form_state->getValue('openai_key_id'))
      ->set('system_prompt', $form_state->getValue('system_prompt'))
      ->save();

    $apiKeyFallback = trim((string) $form_state->getValue('api_key_fallback'));
    if ($apiKeyFallback !== '') {
      $this->state->set('agency_ai_translation.api_key', $apiKeyFallback);
    }

    parent::submitForm($form, $form_state);
  }

}
