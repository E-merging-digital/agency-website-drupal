<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies deterministic Input Length Limit behavior without a provider call.
 *
 * @group agency_project_tests
 * @group agency_ai
 */
#[Group('agency_ai')]
final class AiInputLengthGuardrailTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
  ];

  /**
   * Verifies the configured character boundary passes and then stops.
   */
  public function testCharacterLimitStopsBeforeProviderUse(): void {
    $plugin = $this->container
      ->get('plugin.manager.ai_guardrail')
      ->createInstance('input_length_limit', [
        'max_length' => 20000,
        'use_tokens' => FALSE,
        'tokenizer_model' => 'gpt-4',
        'check_all_messages' => FALSE,
        'violation_message' => 'Input too long: @count/@max @unit.',
      ]);

    $within_limit = new ChatInput([
      new ChatMessage('user', str_repeat('a', 20000)),
    ]);
    self::assertInstanceOf(
      PassResult::class,
      $plugin->processInput($within_limit),
    );

    $over_limit = new ChatInput([
      new ChatMessage('user', str_repeat('a', 20001)),
    ]);
    self::assertInstanceOf(
      StopResult::class,
      $plugin->processInput($over_limit),
    );
  }

  /**
   * Verifies the baseline checks the latest message, not accumulated history.
   */
  public function testOnlyLatestMessageIsCounted(): void {
    $plugin = $this->container
      ->get('plugin.manager.ai_guardrail')
      ->createInstance('input_length_limit', [
        'max_length' => 20000,
        'use_tokens' => FALSE,
        'tokenizer_model' => 'gpt-4',
        'check_all_messages' => FALSE,
        'violation_message' => 'Input too long: @count/@max @unit.',
      ]);

    $input = new ChatInput([
      new ChatMessage('user', str_repeat('a', 15000)),
      new ChatMessage('assistant', str_repeat('b', 15000)),
      new ChatMessage('user', str_repeat('c', 15000)),
    ]);

    self::assertInstanceOf(PassResult::class, $plugin->processInput($input));
  }

}
