<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Plugin\Action;

use Drupal\agency_ai_translation\Service\AiTranslationManager;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Action de masse de traduction IA.
 *
 * @Action(
 *   id = "agency_ai_translate_nodes_bulk_action",
 *   label = @Translation("Traduire avec IA vers une langue cible"),
 *   type = "node"
 * )
 */
final class AiTranslateNodesBulkAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly AiTranslationManager $translationManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
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
      $container->get('agency_ai_translation.manager'),
      $container->get('language_manager'),
      $container->get('logger.channel.agency_ai_translation'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'source_langcode' => 'fr',
      'target_langcode' => 'en',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE);
    $options = [];
    foreach ($languages as $language) {
      $options[$language->getId()] = strtoupper($language->getId()) . ' — ' . $language->getName();
    }

    $form['source_langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Langue source'),
      '#options' => $options,
      '#default_value' => $this->configuration['source_langcode'],
      '#required' => TRUE,
    ];

    $form['target_langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Langue cible'),
      '#options' => $options,
      '#default_value' => $this->configuration['target_langcode'],
      '#description' => $this->t('Langue cible utilisée pour cette exécution de l’action de masse.'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['source_langcode'] = $form_state->getValue('source_langcode');
    $this->configuration['target_langcode'] = $form_state->getValue('target_langcode');
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof NodeInterface) {
      return;
    }

    $this->translationManager->translateEntityToLanguage(
      $entity,
      (string) $this->configuration['target_langcode'],
      (string) $this->configuration['source_langcode'],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities): void {
    $sourceLangcode = (string) $this->configuration['source_langcode'];
    $targetLangcode = (string) $this->configuration['target_langcode'];
    if ($sourceLangcode === $targetLangcode) {
      $this->messenger()->addError($this->t('La langue cible doit être différente de la langue source.'));
      return;
    }

    $success = 0;
    $errors = 0;
    $errorMessages = [];

    foreach ($entities as $entity) {
      if (!$entity instanceof NodeInterface) {
        continue;
      }

      try {
        $this->execute($entity);
        $success++;
      }
      catch (\Throwable $exception) {
        $errors++;
        if (count($errorMessages) < 3) {
          $errorMessages[] = $this->t('Nœud @nid : @message', [
            '@nid' => (string) $entity->id(),
            '@message' => $exception->getMessage(),
          ]);
        }
        $this->logger->error('Échec traduction IA en masse pour le nœud @nid : @message', [
          '@nid' => $entity->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    if ($success > 0) {
      $this->messenger()->addStatus($this->formatPlural($success, '1 contenu traduit.', '@count contenus traduits.'));
    }
    if ($errors > 0) {
      $this->messenger()->addWarning($this->formatPlural($errors, '1 contenu en erreur.', '@count contenus en erreur.'));
      foreach ($errorMessages as $errorMessage) {
        $this->messenger()->addWarning($errorMessage);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $accessResult = AccessResult::allowedIf($object instanceof NodeInterface
      && $object->access('update', $account)
      && ($account?->hasPermission('trigger ai translation') || $account?->hasPermission('administer nodes')));

    return $return_as_object ? $accessResult : $accessResult->isAllowed();
  }

}
