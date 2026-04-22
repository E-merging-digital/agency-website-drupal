<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;

/**
 * Configuration du module de traduction IA.
 */
final class AiTranslationSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'agency_ai_translation_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['agency_ai_translation.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('agency_ai_translation.settings');
    $fromSettings = Settings::get('agency_ai_translation.api_key');

    $form['description'] = [
      '#type' => 'item',
      '#markup' => $this->t('Définissez la clé API dans <code>settings.php</code> via <code>$settings["agency_ai_translation.api_key"]</code> (recommandé) ou via la variable d’environnement <code>AGENCY_AI_TRANSLATION_API_KEY</code>. Le champ ci-dessous est un fallback stocké en base (state), non exporté.'),
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

    $form['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $config->get('system_prompt') ?: 'You are a professional website translator. Translate French into natural English while preserving meaning, tone, formatting, and calls-to-action. Return only translated text.',
      '#required' => TRUE,
    ];

    $form['api_key_fallback'] = [
      '#type' => 'password',
      '#title' => $this->t('Clé API fallback (state, non exportée)'),
      '#description' => $fromSettings ? $this->t('Une clé est déjà fournie via settings.php.') : $this->t('Optionnel. Utilisé uniquement si aucune clé n’est trouvée dans settings.php ni dans la variable d’environnement.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('agency_ai_translation.settings')
      ->set('endpoint', $form_state->getValue('endpoint'))
      ->set('model', $form_state->getValue('model'))
      ->set('system_prompt', $form_state->getValue('system_prompt'))
      ->save();

    $apiKeyFallback = trim((string) $form_state->getValue('api_key_fallback'));
    if ($apiKeyFallback !== '') {
      $this->state()->set('agency_ai_translation.api_key', $apiKeyFallback);
    }

    parent::submitForm($form, $form_state);
  }

}
