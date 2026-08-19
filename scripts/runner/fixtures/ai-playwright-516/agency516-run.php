<?php

declare(strict_types=1);

use Drupal\Component\Serialization\Yaml;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Event\AgentRequestEvent;

$baselineBeforePath = DRUPAL_ROOT . '/../artifacts/ai-playwright-governed-loop/baseline-before.json';
if (!is_file($baselineBeforePath)) {
  throw new \RuntimeException('Captured #516 baseline evidence is unavailable.');
}
$baselineBefore = json_decode((string) file_get_contents($baselineBeforePath), TRUE, 512, JSON_THROW_ON_ERROR);
$originalHeading = $baselineBefore['heading'] ?? NULL;
if (!is_string($originalHeading) || trim($originalHeading) === '') {
  throw new \RuntimeException('Captured #516 original CTA heading is invalid.');
}
$temporaryHeading = 'Composition Canvas bornée — vérification agentique';
if ($originalHeading === $temporaryHeading) {
  throw new \RuntimeException('Captured #516 baseline is already in the temporary proof state.');
}

$sequence = [
  ['name' => 'browser_preview', 'args' => ['url' => '/canvas-governed-sdc-baseline', 'task' => 'Inspect the approved Canvas baseline before the bounded #516 mutation.']],
  ['name' => 'bounded_canvas_heading', 'args' => ['mode' => 'mutate']],
  ['name' => 'browser_preview', 'args' => ['url' => '/canvas-governed-sdc-baseline', 'task' => 'Inspect the approved Canvas baseline after the bounded #516 heading mutation.']],
  ['name' => 'bounded_canvas_heading', 'args' => ['mode' => 'restore']],
  ['name' => 'browser_preview', 'args' => ['url' => '/canvas-governed-sdc-baseline', 'task' => 'Inspect the approved Canvas baseline after exact restoration.']],
  ['name' => NULL, 'text' => '#516 governed AI Playwright loop complete.'],
];
$step = 0;
$fixtureDir = DRUPAL_ROOT . '/modules/custom/agency_ai_playwright_516_test/tests/resources/ai_test/requests/chat';
@mkdir($fixtureDir, 0775, TRUE);

\Drupal::service('event_dispatcher')->addListener(
  AgentRequestEvent::EVENT_NAME,
  static function (AgentRequestEvent $event) use (&$step, $sequence, $fixtureDir): void {
    if (!isset($sequence[$step])) {
      throw new \RuntimeException('Provider requested more #516 hops than scripted.');
    }
    $planned = $sequence[$step];
    if ($planned['name'] === NULL) {
      $normalized = [
        'role' => 'assistant',
        'text' => $planned['text'],
        'images' => [],
        'remote_files' => [],
        'tools' => NULL,
        'tool_id' => NULL,
      ];
      $raw = ['role' => 'assistant', 'text' => $planned['text']];
    }
    else {
      $call = [
        'id' => 'agency_516_call_' . ($step + 1),
        'type' => 'function',
        'function' => [
          'name' => $planned['name'],
          'arguments' => json_encode($planned['args'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
      ];
      $normalized = [
        'role' => 'assistant',
        'text' => '',
        'images' => [],
        'remote_files' => [],
        'tools' => [$call],
        'tool_id' => NULL,
      ];
      $raw = ['role' => 'assistant', 'text' => '', 'tools' => [$call]];
    }
    $fixture = [
      'request' => $event->getChatInput()->toArray(),
      'response' => [
        'normalized' => $normalized,
        'rawOutput' => $raw,
        'metadata' => [],
        'tokenUsage' => ['input' => NULL, 'output' => NULL, 'total' => NULL, 'reasoning' => NULL, 'cached' => NULL],
        'rateLimits' => [
          'rateLimitMaxRequests' => NULL,
          'rateLimitMaxTokens' => NULL,
          'rateLimitRemainingRequests' => NULL,
          'rateLimitRemainingTokens' => NULL,
          'rateLimitResetRequests' => NULL,
          'rateLimitResetTokens' => NULL,
        ],
      ],
      'operation_type' => 'chat',
    ];
    file_put_contents($fixtureDir . '/step-' . ($step + 1) . '.yml', Yaml::encode($fixture));
    \Drupal::service('cache.default')->deleteAll();
    $step++;
  },
  1000,
);

$user = user_load_by_name('agency_516_agent');
if (!$user) {
  throw new \RuntimeException('Least-privilege #516 actor not found.');
}
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($user);
try {
  $agent = \Drupal::service('plugin.manager.ai_agents')->createInstance('agency_516_governed_loop');
  $agent->setAiProvider(\Drupal::service('ai.provider')->createInstance('echoai'));
  $agent->setModelName('gpt-test');
  $agent->setLooped(FALSE);
  $agent->setChatInput(new ChatInput([new ChatMessage('user', 'Run the deterministic governed #516 Canvas inspection, mutation, verification and restoration proof.') ]));

  for ($loop = 0; $loop < 8 && !$agent->isFinished(); $loop++) {
    $agent->determineSolvability();
  }
  if (!$agent->isFinished()) {
    throw new \RuntimeException('The #516 agent did not finish within the bounded loop count.');
  }
  if ($step !== 6) {
    throw new \RuntimeException(sprintf('Expected exactly 6 provider hops, observed %d.', $step));
  }

  $tools = [];
  foreach ($agent->getToolResults() as $tool) {
    $tools[] = [
      'function_name' => $tool->getFunctionName(),
      'output' => $tool->getReadableOutput(),
    ];
  }
  $names = array_column($tools, 'function_name');
  $expected = ['browser_preview', 'bounded_canvas_heading', 'browser_preview', 'bounded_canvas_heading', 'browser_preview'];
  if ($names !== $expected) {
    throw new \RuntimeException('Unexpected tool sequence: ' . json_encode($names));
  }
  if (!str_contains($tools[0]['output'], $originalHeading)) {
    throw new \RuntimeException('Before inspection did not observe the captured original CTA heading.');
  }
  if (!str_contains($tools[2]['output'], $temporaryHeading)) {
    throw new \RuntimeException('After inspection did not observe the temporary CTA heading.');
  }
  if (!str_contains($tools[4]['output'], $originalHeading)) {
    throw new \RuntimeException('Restored inspection did not observe the captured original CTA heading.');
  }
  if (str_contains($tools[4]['output'], $temporaryHeading)) {
    throw new \RuntimeException('Restored inspection still exposes the temporary heading.');
  }

  file_put_contents(
    DRUPAL_ROOT . '/../artifacts/ai-playwright-governed-loop/agent-loop.json',
    json_encode([
      'status' => 'PASS',
      'provider' => 'echoai',
      'model' => 'gpt-test',
      'provider_network_required' => FALSE,
      'provider_hops' => $step,
      'tool_sequence' => $tools,
      'answer' => $agent->solve(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
  );
}
finally {
  $switcher->switchBack();
}
