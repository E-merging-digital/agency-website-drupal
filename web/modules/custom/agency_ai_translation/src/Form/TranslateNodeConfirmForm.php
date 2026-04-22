<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Form;

use Drupal\agency_ai_translation\Service\AiTranslationManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation manuelle de traduction IA.
 */
final class TranslateNodeConfirmForm extends ConfirmFormBase {

  private ?NodeInterface $node = NULL;

  public function __construct(
    private readonly AiTranslationManager $translationManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('agency_ai_translation.manager'),
    );
  }

  public function getFormId(): string {
    return 'agency_ai_translation_translate_node_confirm';
  }

  public function getQuestion(): string {
    return $this->t('Générer/mettre à jour la traduction anglaise de "@title" ?', ['@title' => $this->node?->label() ?? '']);
  }

  public function getDescription(): string {
    return $this->t('Cette action traduit uniquement les champs éditoriaux (texte, résumé, CTA et paragraphs translatables). Les champs techniques ne sont pas modifiés.');
  }

  public function getCancelUrl(): Url {
    return $this->node ? $this->node->toUrl('canonical') : Url::fromRoute('<front>');
  }

  public function getConfirmText(): string {
    return $this->t('Lancer la traduction IA');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException('Nœud invalide.');
    }
    $this->node = $node;

    if ($node->language()->getId() !== 'fr') {
      throw new \InvalidArgumentException('Seuls les contenus FR source peuvent être traduits.');
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $translatedFieldsCount = $this->translationManager->translateEntityToEnglish($this->node);
      $this->messenger()->addStatus($this->t('Traduction EN générée. @count champ(s) traité(s). Vérifiez puis publiez manuellement la version anglaise.', ['@count' => $translatedFieldsCount]));
      $form_state->setRedirect('entity.node.edit_form', ['node' => $this->node->id()], ['query' => ['langcode' => 'en']]);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Échec de la traduction IA : @message', ['@message' => $exception->getMessage()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
    }
  }

}
