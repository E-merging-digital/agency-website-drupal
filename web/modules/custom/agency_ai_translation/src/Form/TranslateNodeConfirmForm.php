<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Form;

use Drupal\agency_ai_translation\Service\AiTranslationManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation manuelle de traduction IA.
 */
final class TranslateNodeConfirmForm extends ConfirmFormBase {

  /**
   * Nœud source à traduire.
   */
  private ?NodeInterface $node = NULL;

  /**
   * Langue source.
   */
  private string $sourceLangcode = 'fr';

  /**
   * Langue cible.
   */
  private string $targetLangcode = 'en';

  public function __construct(
    private readonly AiTranslationManager $translationManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('agency_ai_translation.manager'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agency_ai_translation_translate_node_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Générer/mettre à jour la traduction @target de "@title" ?', [
      '@target' => strtoupper($this->targetLangcode),
      '@title' => $this->node?->label() ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Source : @source. Cible : @target. Cette action traduit uniquement les champs éditoriaux (texte, résumé, CTA et paragraphs translatables). Les champs techniques ne sont pas modifiés.', [
      '@source' => strtoupper($this->sourceLangcode),
      '@target' => strtoupper($this->targetLangcode),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->node ? $this->node->toUrl('canonical') : Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Lancer la traduction IA');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, string $target_langcode = 'en'): array {
    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException('Nœud invalide.');
    }
    $this->sourceLangcode = $node->language()->getId();
    $this->targetLangcode = $target_langcode;

    if (!$this->languageManager->getLanguage($this->targetLangcode)) {
      throw new \InvalidArgumentException('Langue cible invalide.');
    }
    if ($this->targetLangcode === $this->sourceLangcode) {
      throw new \InvalidArgumentException('La langue cible doit être différente de la langue source.');
    }
    if (!$node->access('update', $this->currentUser())) {
      throw new AccessDeniedHttpException();
    }
    $this->node = $node;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $translatedFieldsCount = $this->translationManager->translateEntityToLanguage($this->node, $this->targetLangcode, $this->sourceLangcode);
      $this->messenger()->addStatus($this->t('Traduction @target générée. @count champ(s) traité(s). Vérifiez puis publiez manuellement la version traduite.', [
        '@target' => strtoupper($this->targetLangcode),
        '@count' => $translatedFieldsCount,
      ]));
      $form_state->setRedirect('entity.node.edit_form', ['node' => $this->node->id()], ['query' => ['langcode' => $this->targetLangcode]]);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Échec de la traduction IA : @message', ['@message' => $exception->getMessage()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
    }
  }

}
