<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\emerging_digital_chatbot\ChatbotConfig;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Server-side OpenAI Responses API gateway.
 */
final class OpenAiResponsesGateway implements FutureAiProviderGatewayInterface {

  public function __construct(
    private readonly ChatbotConfig $chatbotConfig,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return FutureAiProviderRegistry::PROVIDER_OPENAI;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function respond(
    array $payload,
    string $langcode,
    string $promptContext,
    string $apiKey,
  ): FutureAiResponse {
    $payload = $this->sanitizePayloadForProvider($payload);
    $message = trim((string) ($payload['message'] ?? ''));
    if ($message === '') {
      return $this->providerFailure(
        FutureAiResponseStatus::EmptyAiResponse,
        $langcode,
      );
    }

    try {
      $response = $this->httpClient->request('POST', $this->chatbotConfig->getFutureAiEndpoint(), [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => $this->buildRequestPayload($payload, $langcode, $promptContext),
        'timeout' => $this->chatbotConfig->getFutureAiTimeoutSeconds(),
      ]);

      if ($response->getStatusCode() >= 400) {
        $this->logger->warning('Chatbot AI provider returned an HTTP error: @status', [
          '@status' => $response->getStatusCode(),
        ]);

        return $this->providerFailure(
          FutureAiResponseStatus::ProviderError,
          $langcode,
        );
      }

      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $answer = $this->extractOutputText(is_array($data) ? $data : []);
      if ($answer === '') {
        return $this->providerFailure(
          FutureAiResponseStatus::EmptyAiResponse,
          $langcode,
        );
      }
      if ($this->violatesCommercialGuardrails($answer)) {
        return $this->providerFailure(
          FutureAiResponseStatus::GuardrailFallback,
          $langcode,
        );
      }

      return FutureAiResponse::aiResponse($answer, $langcode);
    }
    catch (ConnectException $exception) {
      $this->logger->warning('Chatbot AI provider request failed: @class', [
        '@class' => $exception::class,
      ]);

      return $this->providerFailure(
        FutureAiResponseStatus::ProviderTimeout,
        $langcode,
      );
    }
    catch (GuzzleException $exception) {
      $this->logger->warning('Chatbot AI provider request failed: @class', [
        '@class' => $exception::class,
      ]);

      return $this->providerFailure(
        FutureAiResponseStatus::ProviderError,
        $langcode,
      );
    }
    catch (\JsonException) {
      $this->logger->warning('Chatbot AI provider returned invalid JSON.');

      return $this->providerFailure(
        FutureAiResponseStatus::ProviderError,
        $langcode,
      );
    }
  }

  /**
   * Builds the OpenAI Responses API request body.
   *
   * @param array<string, mixed> $payload
   *   Sanitized visitor payload.
   * @param string $langcode
   *   Active language code.
   * @param string $promptContext
   *   Public-only prompt context.
   *
   * @return array<string, mixed>
   *   Provider payload.
   */
  private function buildRequestPayload(
    array $payload,
    string $langcode,
    string $promptContext,
  ): array {
    return [
      'model' => $this->chatbotConfig->getFutureAiModel(),
      'instructions' => $this->chatbotConfig->getFutureAiSystemPrompt($langcode),
      'input' => [
        [
          'role' => 'user',
          'content' => [
            [
              'type' => 'input_text',
              'text' => $this->buildUserInput($payload, $promptContext),
            ],
          ],
        ],
      ],
      'temperature' => $this->chatbotConfig->getFutureAiTemperature(),
      'max_output_tokens' => $this->chatbotConfig->getFutureAiMaxOutputTokens(),
      'store' => FALSE,
      'metadata' => [
        'module' => 'emerging_digital_chatbot',
        'langcode' => $langcode,
        'prompt_version' => $this->chatbotConfig->getFutureAiPromptVersion(),
        'rag_profile' => $this->chatbotConfig->getFutureAiRagProfile(),
      ],
    ];
  }

  /**
   * Builds a compact, non-sensitive user input block.
   *
   * @param array<string, mixed> $payload
   *   Sanitized visitor payload.
   * @param string $context
   *   Public context note.
   */
  private function buildUserInput(array $payload, string $context): string {
    $lines = [
      $context,
      '',
      'Visitor intent, already sanitized by Drupal:',
      'message: ' . (string) ($payload['message'] ?? ''),
      'need: ' . (string) ($payload['need'] ?? ''),
      'project_type: ' . (string) ($payload['project_type'] ?? ''),
      'organization_type: ' . (string) ($payload['organization_type'] ?? ''),
      'voluntary_public_url: ' . (string) ($payload['url'] ?? ''),
      '',
      'Answer briefly. Orient toward useful public pages or human contact.',
    ];

    return implode("\n", $lines);
  }

  /**
   * Extracts text from a Responses API response.
   *
   * @param array<string, mixed> $data
   *   Decoded provider response.
   */
  private function extractOutputText(array $data): string {
    if (is_string($data['output_text'] ?? NULL)) {
      return $this->sanitizeProviderText($data['output_text']);
    }

    foreach (($data['output'] ?? []) as $item) {
      if (!is_array($item)) {
        continue;
      }
      foreach (($item['content'] ?? []) as $content) {
        if (is_array($content) && is_string($content['text'] ?? NULL)) {
          return $this->sanitizeProviderText($content['text']);
        }
      }
    }

    return '';
  }

  /**
   * Normalizes provider text before returning it to the frontend.
   */
  private function sanitizeProviderText(string $text): string {
    $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text);
    $text = trim(strip_tags((string) $text));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', (string) $text);

    return mb_substr(trim((string) $text), 0, 900);
  }

  /**
   * Rejects answers that appear to cross commercial guardrails.
   */
  private function violatesCommercialGuardrails(string $answer): bool {
    $patterns = [
      '/(?:\x{20AC}|\$|eur\b|euro\b|euros\b)/iu',
      '/\b(?:devis|quote|estimate|prix|price|budget)\b/i',
      '/\b(?:accept(?:e|\x{00E9})|accepted|approved|valid(?:e|\x{00E9})|validated|guaranteed)\b/iu',
      '/\b(?:d(?:e|\x{00E9})lai garanti|guaranteed timeline|contractual deadline)\b/iu',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $answer) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets a safe provider failure result.
   *
   * @return \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse
   *   Provider failure result.
   */
  private function providerFailure(
    FutureAiResponseStatus $status,
    string $langcode,
  ): FutureAiResponse {
    return FutureAiResponse::providerFailure($status, $langcode);
  }

  /**
   * Normalizes all provider-bound visitor fields as a defense in depth.
   *
   * @param array<string, mixed> $payload
   *   Sanitized payload from the endpoint.
   *
   * @return array<string, bool|string>
   *   Provider-safe scalar values.
   */
  private function sanitizePayloadForProvider(array $payload): array {
    return [
      'flow' => $this->sanitizePayloadText($payload['flow'] ?? '', 80),
      'langcode' => $this->sanitizePayloadText($payload['langcode'] ?? '', 8),
      'message' => $this->sanitizePayloadText(
        $payload['message'] ?? '',
        $this->chatbotConfig->getFutureAiMaxInputChars(),
      ),
      'need' => $this->sanitizePayloadText($payload['need'] ?? '', 160),
      'organization_type' => $this->sanitizePayloadText(
        $payload['organization_type'] ?? '',
        80,
      ),
      'project_type' => $this->sanitizePayloadText(
        $payload['project_type'] ?? '',
        80,
      ),
      'url' => $this->sanitizePayloadText($payload['url'] ?? '', 300),
      'blocked_sensitive_input' => !empty($payload['blocked_sensitive_input']),
    ];
  }

  /**
   * Keeps provider-bound text compact and free of raw HTML/control characters.
   */
  private function sanitizePayloadText(mixed $value, int $maxLength): string {
    if (!is_scalar($value)) {
      return '';
    }

    $text = preg_replace(
      '/<(script|style)\b[^>]*>.*?<\/\1>/is',
      '',
      (string) $value,
    );
    $text = trim(strip_tags((string) $text));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', (string) $text);

    return mb_substr(trim((string) $text), 0, $maxLength);
  }

}
