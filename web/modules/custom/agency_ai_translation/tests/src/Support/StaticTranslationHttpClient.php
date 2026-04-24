<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_ai_translation\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Faux client HTTP pour rendre les tests de traduction déterministes.
 */
final class StaticTranslationHttpClient implements ClientInterface {

  /**
   * {@inheritdoc}
   */
  public function send(RequestInterface $request, array $options = []): ResponseInterface {
    return $this->request($request->getMethod(), (string) $request->getUri(), $options);
  }

  /**
   * {@inheritdoc}
   */
  public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
    return Create::promiseFor($this->send($request, $options));
  }

  /**
   * {@inheritdoc}
   */
  public function request(string $method, $uri = '', array $options = []): ResponseInterface {
    $source = '';
    $payload = $options['json'] ?? [];
    if (is_array($payload) && isset($payload['messages'][1]['content']) && is_string($payload['messages'][1]['content'])) {
      $parts = explode("Source content:\n", $payload['messages'][1]['content'], 2);
      $source = trim((string) ($parts[1] ?? ''));
    }

    $translated = $source === '' ? 'EN: translated' : 'EN: ' . $source;
    $body = json_encode([
      'choices' => [
        [
          'message' => [
            'content' => $translated,
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);

    return new Response(200, ['Content-Type' => 'application/json'], $body);
  }

  /**
   * {@inheritdoc}
   */
  public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface {
    return Create::promiseFor($this->request($method, $uri, $options));
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(?string $option = NULL) {
    return NULL;
  }

}
