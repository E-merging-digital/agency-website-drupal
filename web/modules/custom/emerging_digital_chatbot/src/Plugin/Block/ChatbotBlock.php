<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\QualificationEngine;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the guided chatbot widget.
 *
 * @Block(
 *   id = "emerging_digital_chatbot",
 *   admin_label = @Translation("Guided chatbot"),
 *   category = @Translation("Emerging Digital")
 * )
 */
final class ChatbotBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ChatbotConfig $chatbotConfig,
    private readonly QualificationEngine $qualificationEngine,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('emerging_digital_chatbot.config'),
      $container->get('emerging_digital_chatbot.qualification_engine'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'suppress_contact_pages' => TRUE,
      'launcher_variant' => 'compact',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['suppress_contact_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide the widget on contact pages'),
      '#default_value' => (bool) ($this->configuration['suppress_contact_pages'] ?? TRUE),
      '#description' => $this->t('Prevents the floating assistant from covering the contact form.'),
    ];

    $form['launcher_variant'] = [
      '#type' => 'select',
      '#title' => $this->t('Launcher style'),
      '#default_value' => (string) ($this->configuration['launcher_variant'] ?? 'compact'),
      '#options' => [
        'compact' => $this->t('Compact'),
        'labelled' => $this->t('Icon and label'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['suppress_contact_pages'] = (bool) $form_state->getValue('suppress_contact_pages');
    $this->configuration['launcher_variant'] = (string) $form_state->getValue('launcher_variant');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $suppress_contact_pages = (bool) ($this->configuration['suppress_contact_pages'] ?? TRUE);
    $build = [];
    $cache_metadata = (new CacheableMetadata())
      ->addCacheContexts([
        'languages:' . LanguageInterface::TYPE_INTERFACE,
        'url.path',
      ])
      ->addCacheTags(['config:emerging_digital_chatbot.settings']);

    if (!$this->chatbotConfig->isVisibleOnCurrentPage($suppress_contact_pages)) {
      $cache_metadata->applyTo($build);
      return $build;
    }

    $payload = $this->qualificationEngine->buildPayload();
    $messages = is_array($payload['messages'] ?? NULL) ? $payload['messages'] : [];
    $chatbot_id = 'emerging-digital-chatbot';
    $variant = (string) ($this->configuration['launcher_variant'] ?? 'compact');

    $build = [
      '#theme' => 'emerging_digital_chatbot',
      '#chatbot_id' => $chatbot_id,
      '#title' => (string) ($messages['title'] ?? $this->t('Assistant')),
      '#launcher_label' => (string) ($messages['launcher_label'] ?? $this->t('Open assistant')),
      '#close_label' => (string) ($messages['close_label'] ?? $this->t('Close')),
      '#reset_label' => (string) ($messages['reset_label'] ?? $this->t('Start again')),
      '#attached' => [
        'library' => [
          'emerging_digital_chatbot/widget',
        ],
        'drupalSettings' => [
          'emergingDigitalChatbot' => [
            $chatbot_id => [
              'launcherVariant' => $variant,
              'payload' => $payload,
            ],
          ],
        ],
      ],
    ];

    $cache_metadata->applyTo($build);
    return $build;
  }

}
