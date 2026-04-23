<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Controller;

use Drupal\agency_ai_translation\Service\AiTranslationManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Contrôleur de traduction IA unitaire.
 */
final class TranslateNodeController extends ControllerBase {

  public function __construct(
    private readonly AiTranslationManager $translationManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('agency_ai_translation.manager'),
    );
  }

  /**
   * Lance la traduction IA puis redirige vers l'édition de la traduction cible.
   */
  public function translate(NodeInterface $node, string $target_langcode): RedirectResponse {
    $hasPermission = $this->currentUser()->hasPermission('trigger ai translation')
      || $this->currentUser()->hasPermission('administer nodes');

    if (!$hasPermission || !$node->access('update', $this->currentUser())) {
      throw new AccessDeniedHttpException();
    }

    if (!$this->languageManager()->getLanguage($target_langcode)) {
      $this->messenger()->addError($this->t('Langue cible invalide : @lang.', ['@lang' => $target_langcode]));
      return $this->redirectToNode($node);
    }

    if ($target_langcode === $node->language()->getId()) {
      $this->messenger()->addError($this->t('La langue cible doit être différente de la langue source.'));
      return $this->redirectToNode($node);
    }

    try {
      $translatedFieldsCount = $this->translationManager->translateEntityToLanguage($node, $target_langcode, $node->language()->getId());
      $this->messenger()->addStatus($this->t('Traduction @target générée. @count champ(s) traité(s).', [
        '@target' => strtoupper($target_langcode),
        '@count' => $translatedFieldsCount,
      ]));

      return new RedirectResponse($node->toUrl('edit-form', ['query' => ['langcode' => $target_langcode]])->toString());
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Échec de la traduction IA : @message', ['@message' => $exception->getMessage()]));
      return $this->redirectToNode($node);
    }
  }

  /**
   * Redirige vers le nœud source.
   */
  private function redirectToNode(NodeInterface $node): RedirectResponse {
    return new RedirectResponse($node->toUrl()->toString());
  }

}
