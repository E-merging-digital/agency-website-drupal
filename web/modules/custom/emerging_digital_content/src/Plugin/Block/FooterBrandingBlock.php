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
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['tagline'] = (string) $form_state->getValue('tagline');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#plain_text' => (string) ($this->configuration['tagline'] ?? ''),
    ];
  }

}
