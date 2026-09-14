<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Controller;

use Drupal\agency_operations\Service\EnvironmentDataStateProvider;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exposes the normalized Agency cockpit state over a bounded read-only route.
 */
final class CockpitStateController implements ContainerInjectionInterface {

  private const TOKEN_SETTING = 'agency_operations_cockpit_state_token';

  private const MINIMUM_TOKEN_LENGTH = 32;

  public function __construct(
    private readonly EnvironmentDataStateProvider $stateProvider,
    private readonly ?string $configuredToken = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('agency_operations.environment_data_state'),
    );
  }

  /**
   * Returns the existing normalized projection only on authenticated PREPROD.
   */
  public function state(Request $request): JsonResponse {
    $expectedToken = $this->expectedToken();
    if (strlen($expectedToken) < self::MINIMUM_TOKEN_LENGTH) {
      return $this->errorResponse('transport_unavailable', 503);
    }

    $authorization = (string) $request->headers->get('Authorization', '');
    if (preg_match('/^Bearer (?<token>[^\s]+)$/D', $authorization, $matches) !== 1
      || !hash_equals($expectedToken, $matches['token'])) {
      $response = $this->errorResponse('unauthorized', 401);
      $response->headers->set('WWW-Authenticate', 'Bearer');
      return $response;
    }

    $state = $this->stateProvider->read();
    if (($state['runtime']['environment'] ?? NULL) !== 'PREPROD') {
      return $this->errorResponse('not_found', 404);
    }

    return $this->jsonResponse($state, 200);
  }

  /**
   * Resolves the credential from non-exportable settings in production.
   */
  private function expectedToken(): string {
    if ($this->configuredToken !== NULL) {
      return trim($this->configuredToken);
    }

    $configured = Settings::get(self::TOKEN_SETTING, '');
    return is_string($configured) ? trim($configured) : '';
  }

  /**
   * Creates one fail-closed JSON error without credential detail.
   */
  private function errorResponse(string $error, int $status): JsonResponse {
    return $this->jsonResponse(['error' => $error], $status);
  }

  /**
   * Creates a non-cacheable machine response.
   *
   * @param array<string, mixed> $payload
   *   JSON-safe response payload.
   * @param int $status
   *   HTTP response status code.
   */
  private function jsonResponse(array $payload, int $status): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
