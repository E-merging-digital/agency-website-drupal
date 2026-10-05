<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Service;

/**
 * Computes publication language readiness from Drupal-configured languages.
 */
interface EditorialLanguageReadinessInterface {

  /**
   * Returns every currently configurable site language, keyed by langcode.
   *
   * @return array<string, string>
   *   Language code => human-readable language name.
   */
  public function getRequiredLanguages(): array;

  /**
   * Evaluates candidate readiness against every required site language.
   *
   * @param array<string, array<string, mixed>> $languageStates
   *   Candidate states keyed by language code.
   *
   * @return array{overall: string, languages: array<string, array<string, mixed>>}
   *   Overall READY/BLOCKED state and the per-language matrix.
   */
  public function evaluate(array $languageStates): array;

  /**
   * Returns TRUE only when every configured language is ready.
   */
  public function isPublicationLanguageComplete(array $languageStates): bool;

}
