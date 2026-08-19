<?php

declare(strict_types=1);

namespace Drupal\agency_ai_playwright_516_test\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;

#[FunctionCall(
  id: 'agency_ai_playwright_516_test:bounded_canvas_heading',
  function_name: 'bounded_canvas_heading',
  name: 'Agency #516 bounded Canvas heading',
  description: 'DEV-ONLY proof tool. Mutates or restores one fixed approved CTA heading on one fixed Canvas baseline page.',
  context_definitions: [
    'mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Mode'),
      description: new TranslatableMarkup('Exactly mutate or restore.'),
      required: TRUE,
    ),
  ],
)]
final class BoundedCanvasHeading extends FunctionCallBase implements ExecutableFunctionCallInterface {

  private string $readableOutput = '';

  public function execute(): void {
    $account = \Drupal::currentUser();
    if (!$account->hasPermission('use agency 516 bounded canvas mutation')) {
      throw new \RuntimeException('Bounded Canvas mutation permission denied.');
    }

    $mode = (string) $this->getContextValue('mode');
    if (!in_array($mode, ['mutate', 'restore'], TRUE)) {
      throw new \InvalidArgumentException('Unsupported bounded mutation mode.');
    }

    $ids = \Drupal::entityQuery('canvas_page')
      ->accessCheck(FALSE)
      ->condition('uuid', '52600000-0000-4000-8000-000000000001')
      ->execute();
    if (count($ids) !== 1) {
      throw new \RuntimeException('The fixed Canvas baseline page was not found uniquely.');
    }
    $page = \Drupal::entityTypeManager()->getStorage('canvas_page')->load(reset($ids));
    if (!$page || !$page->hasField('components')) {
      throw new \RuntimeException('The fixed Canvas baseline has no components field.');
    }

    $state = \Drupal::state();
    $components = $page->get('components')->getValue();
    if ($mode === 'mutate') {
      if ($state->get('agency_ai_playwright_516_test.original_components')) {
        throw new \RuntimeException('A previous #516 mutation snapshot is still active.');
      }
      $state->set('agency_ai_playwright_516_test.original_components', json_encode($components, JSON_THROW_ON_ERROR));
      $changed = FALSE;
      foreach ($components as &$component) {
        if (($component['uuid'] ?? NULL) !== '52600000-0000-4000-8000-000000000103') {
          continue;
        }
        if (($component['component_id'] ?? NULL) !== 'sdc.emerging_digital.cta') {
          throw new \RuntimeException('The fixed component UUID no longer identifies the approved CTA.');
        }
        if (($component['inputs']['heading'] ?? NULL) !== 'Composition Canvas bornée') {
          throw new \RuntimeException('The original approved CTA heading changed; refusing mutation.');
        }
        $component['inputs']['heading'] = 'Composition Canvas bornée — vérification agentique';
        $changed = TRUE;
      }
      unset($component);
      if (!$changed) {
        throw new \RuntimeException('The fixed approved CTA component was not found.');
      }
      $page->set('components', $components);
      $page->save();
      $this->readableOutput = 'MUTATED: Composition Canvas bornée — vérification agentique';
      return;
    }

    $stored = $state->get('agency_ai_playwright_516_test.original_components');
    if (!is_string($stored) || $stored === '') {
      throw new \RuntimeException('No #516 mutation snapshot is available to restore.');
    }
    $original = json_decode($stored, TRUE, 512, JSON_THROW_ON_ERROR);
    $page->set('components', $original);
    $page->save();
    $state->delete('agency_ai_playwright_516_test.original_components');
    $this->readableOutput = 'RESTORED: Composition Canvas bornée';
  }

  public function getReadableOutput(): string {
    return $this->readableOutput;
  }

}
