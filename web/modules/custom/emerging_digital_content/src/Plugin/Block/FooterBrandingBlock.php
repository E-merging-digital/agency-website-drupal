<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

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
      '#title' => $this->t('Legal company name'),
      '#default_value' => $this->configuration['legal_name'] ?? '',
      '#description' => $this->t('Leave empty if this information is not available yet.'),
      '#maxlength' => 255,
    ];

    $form['company_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company registration number'),
      '#default_value' => $this->configuration['company_number'] ?? '',
      '#description' => $this->t('Leave empty if this information is not available yet.'),
      '#maxlength' => 255,
    ];

    $form['company_address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Company address'),
      '#default_value' => $this->configuration['company_address'] ?? '',
      '#description' => $this->t('Leave empty if this information is not available yet.'),
      '#rows' => 3,
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
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $tagline = (string) ($this->configuration['tagline'] ?? '');
    $legal_name = trim((string) ($this->configuration['legal_name'] ?? ''));
    $company_number = trim((string) ($this->configuration['company_number'] ?? ''));
    $company_address = trim((string) ($this->configuration['company_address'] ?? ''));

    return [
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
    ];
  }

}
