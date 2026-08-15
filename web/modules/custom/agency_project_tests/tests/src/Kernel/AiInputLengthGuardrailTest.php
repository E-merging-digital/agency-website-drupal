<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

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

  /**
   * Verifies the versioned YAML can be saved as real AI config entities.
   */
  public function testVersionedGuardrailEntitiesCanBeSaved(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');

    $guardrail_configuration = $this->loadConfiguration(
      'ai.ai_guardrail.agency_editorial_input_length.yml',
    );
    $guardrail_storage = $entity_type_manager->getStorage('ai_guardrail');
    $guardrail = $guardrail_storage->create($guardrail_configuration);
    $guardrail->save();

    self::assertNotNull(
      $guardrail_storage->load('agency_editorial_input_length'),
    );

    $set_configuration = $this->loadConfiguration(
      'ai.ai_guardrail_set.agency_editorial_baseline.yml',
    );
    $set_storage = $entity_type_manager->getStorage('ai_guardrail_set');
    $set = $set_storage->create($set_configuration);
    $set->save();

    self::assertNotNull($set_storage->load('agency_editorial_baseline'));

    $settings = $this->loadConfiguration('ai.settings.yml');
    $this->config('ai.settings')->setData($settings)->save();
    self::assertSame(
      ['agency_editorial_baseline'],
      $this->config('ai.settings')->get('global_guardrails'),
    );
  }

  /**
   * Loads one configuration file from the repository sync directory.
   */
  private function loadConfiguration(string $filename): array {
    $path = dirname(DRUPAL_ROOT) . '/config/sync/' . $filename;
    self::assertFileExists($path);

    $configuration = Yaml::parseFile($path);
    self::assertIsArray($configuration);

    return $configuration;
  }

}
