<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a footer branding block.
 *
 * @Block(
 *   id = "emerging_digital_footer_branding",
 *   admin_label = @Translation("Footer branding"),
 *   category = @Translation("Emerging Digital")
 * )
 */
final class FooterBrandingBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'tagline' => 'Sites Drupal commerciaux, lisibles et évolutifs pour PME & ASBL.',
      'legal_name' => '',
      'company_number' => '',
      'company_address' => '',
      'company_phone_label' => '',
      'company_phone' => '',
      'company_phone_uri' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['tagline'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tagline'),
      '#default_value' => $this->configuration['tagline'] ?? '',
      '#required' => TRUE,
      '#rows' => 2,
    ];

    $form['legal_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Nom légal de l'entreprise"),
      '#default_value' => $this->configuration['legal_name'] ?? '',
      '#description' => $this->t("Laissez vide si cette information n'est pas encore disponible."),
      '#maxlength' => 255,
    ];

    $form['company_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Numéro d'entreprise"),
      '#default_value' => $this->configuration['company_number'] ?? '',
      '#description' => $this->t("Laissez vide si cette information n'est pas encore disponible."),
      '#maxlength' => 255,
    ];

    $form['company_address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Adresse légale'),
      '#default_value' => $this->configuration['company_address'] ?? '',
      '#description' => $this->t("Laissez vide si cette information n'est pas encore disponible."),
      '#rows' => 3,
    ];

    $form['company_phone_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Libellé du téléphone public'),
      '#default_value' => $this->configuration['company_phone_label'] ?? '',
      '#description' => $this->t("Laissez vide si cette information n'est pas encore disponible."),
      '#maxlength' => 255,
    ];

    $form['company_phone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Téléphone public'),
      '#default_value' => $this->configuration['company_phone'] ?? '',
      '#description' => $this->t("Laissez vide si cette information n'est pas encore disponible."),
      '#maxlength' => 255,
    ];

    $form['company_phone_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Lien téléphone'),
      '#default_value' => $this->configuration['company_phone_uri'] ?? '',
      '#description' => $this->t('URI tel: utilisée pour le lien du numéro public.'),
      '#maxlength' => 255,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['tagline'] = (string) $form_state->getValue('tagline');
    $this->configuration['legal_name'] = trim((string) $form_state->getValue('legal_name'));
    $this->configuration['company_number'] = trim((string) $form_state->getValue('company_number'));
    $this->configuration['company_address'] = trim((string) $form_state->getValue('company_address'));
    $this->configuration['company_phone_label'] = trim((string) $form_state->getValue('company_phone_label'));
    $this->configuration['company_phone'] = trim((string) $form_state->getValue('company_phone'));
    $this->configuration['company_phone_uri'] = trim((string) $form_state->getValue('company_phone_uri'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $tagline = (string) ($this->configuration['tagline'] ?? '');
    $legal_name = trim((string) ($this->configuration['legal_name'] ?? ''));
    $company_number = trim((string) ($this->configuration['company_number'] ?? ''));
    $company_address = trim((string) ($this->configuration['company_address'] ?? ''));
    $company_phone_label = trim((string) ($this->configuration['company_phone_label'] ?? ''));
    $company_phone = trim((string) ($this->configuration['company_phone'] ?? ''));
    $company_phone_uri = trim((string) ($this->configuration['company_phone_uri'] ?? ''));

    $build = [
      'tagline' => [
        '#plain_text' => $tagline,
      ],
      'legal_name' => [
        '#plain_text' => $legal_name,
      ],
      'company_number' => [
        '#plain_text' => $company_number,
      ],
      'company_address' => [
        '#plain_text' => $company_address,
      ],
      'company_phone_label' => [
        '#plain_text' => $company_phone_label,
      ],
    ];

    if ($company_phone !== '' && $company_phone_uri !== '') {
      $build['company_phone'] = [
        '#type' => 'link',
        '#title' => $company_phone,
        '#url' => Url::fromUri($company_phone_uri),
      ];
    }
    else {
      $build['company_phone'] = [];
    }

    return $build;
  }

}
