<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\PublicAiContextProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Displays the sanitized public context prepared for the chatbot.
 */
final class PublicContextAdminController extends ControllerBase {

  private const SUPPORTED_LANGCODES = ['fr', 'en'];

  public function __construct(
    private readonly PublicAiContextProvider $contextProvider,
    private readonly ChatbotConfig $chatbotConfig,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('emerging_digital_chatbot.public_ai_context_provider'),
      $container->get('emerging_digital_chatbot.config'),
    );
  }

  /**
   * Builds the public context inspection page.
   *
   * No prompts, API keys, raw payloads or conversation data are exposed here.
   *
   * @return array<string, mixed>
   *   The admin page render array.
   */
  public function inspect(Request $request): array {
    $requestedLangcode = $request->query->get('langcode');
    $langcode = is_string($requestedLangcode) && in_array($requestedLangcode, self::SUPPORTED_LANGCODES, TRUE)
      ? $requestedLangcode
      : 'fr';

    $contract = $this->contextProvider->buildContextContract($langcode);
    $allowedPaths = $this->getAllowedPublicPaths($langcode);
    $contextText = $contract['text'];
    $contextLength = mb_strlen($contextText);
    $status = $this->getStatus($contract);

    $build = [
      '#cache' => [
        'max-age' => 0,
      ],
      'language_links' => [
        '#type' => 'links',
        '#links' => [
          'fr' => [
            'title' => $this->t('Inspect French'),
            'url' => Url::fromRoute(
              'emerging_digital_chatbot.public_context',
              [],
              ['query' => ['langcode' => 'fr']],
            ),
          ],
          'en' => [
            'title' => $this->t('Inspect English'),
            'url' => Url::fromRoute(
              'emerging_digital_chatbot.public_context',
              [],
              ['query' => ['langcode' => 'en']],
            ),
          ],
        ],
      ],
      'summary' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Property'),
          $this->t('Value'),
        ],
        '#rows' => [
          [
            $this->t('Inspected language'),
            [
              'data' => [
                '#plain_text' => $langcode,
              ],
            ],
          ],
          [
            $this->t('Context status'),
            [
              'data' => [
                '#plain_text' => $status,
              ],
            ],
          ],
          [
            $this->t('Context length'),
            [
              'data' => [
                '#plain_text' => (string) $contextLength,
              ],
            ],
          ],
          [
            $this->t('max_context_chars'),
            [
              'data' => [
                '#plain_text' => (string) $contract['max_context_chars'],
              ],
            ],
          ],
        ],
      ],
      'allowed_paths_title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Allowed public paths'),
      ],
      'allowed_paths' => [
        '#theme' => 'item_list',
        '#items' => $this->buildPlainTextItems($allowedPaths),
        '#empty' => $this->t('No allowed public paths are configured for this language.'),
      ],
      'messages' => $this->buildMessages($contract, $contextLength),
      'context_title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Sanitized generated text'),
      ],
      'context_text' => [
        '#prefix' => '<pre class="emerging-digital-chatbot-public-context">',
        '#plain_text' => $contextText !== ''
          ? $contextText
          : (string) $this->t('No public context text was generated.'),
        '#suffix' => '</pre>',
      ],
    ];

    return $build;
  }

  /**
   * Builds status text for the admin summary.
   *
   * @param array{enabled: bool, status: string} $contract
   *   Public context contract.
   */
  private function getStatus(array $contract): string {
    if (!$contract['enabled']) {
      return 'disabled';
    }

    return $contract['status'];
  }

  /**
   * Builds operational messages for disabled or empty contexts.
   *
   * @param array{enabled: bool, status: string} $contract
   *   Public context contract.
   * @param int $context_length
   *   Sanitized context length.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function buildMessages(array $contract, int $context_length): array {
    $messages = [
      '#type' => 'container',
    ];

    if (!$contract['enabled']) {
      $messages['future_ai_disabled'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#plain_text' => $this->t(
          'future_ai.enabled is disabled. No public context text is generated for AI mode.',
        ),
      ];
    }

    if ($context_length === 0) {
      $messages['empty_context'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        '#plain_text' => $this->t('The public context is empty for this language.'),
      ];
    }

    return $messages;
  }

  /**
   * Gets configured public paths without exposing any sensitive AI settings.
   *
   * @return list<string>
   *   Configured allowed public paths.
   */
  private function getAllowedPublicPaths(string $langcode): array {
    $summary = $this->chatbotConfig->getFutureAiSummary($langcode);
    $context = $summary['context'] ?? [];
    if (!is_array($context)) {
      return [];
    }

    $paths = $context['allowedPublicPaths'] ?? [];
    if (!is_array($paths)) {
      return [];
    }

    $paths = array_filter(
      $paths,
      static fn(mixed $path): bool => is_string($path) && trim($path) !== '',
    );

    return array_values(array_map('strval', $paths));
  }

  /**
   * Converts strings into safe item_list render items.
   *
   * @param list<string> $items
   *   Plain text items.
   *
   * @return list<array<string, string>>
   *   Render items.
   */
  private function buildPlainTextItems(array $items): array {
    return array_map(
      static fn(string $item): array => ['#plain_text' => $item],
      $items,
    );
  }

}
