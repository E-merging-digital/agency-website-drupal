#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail-closed validator for direct human approval of an exact PREPROD candidate.
 *
 * This is intentionally a small parser, not an approval service. The caller
 * supplies already-resolved candidate identity and PREPROD URLs. The script
 * only accepts an exact, unedited OWNER comment created directly by the human
 * GitHub user, with GitHub App provenance explicitly present and null.
 */

function fail(string $message): never {
  fwrite(STDERR, $message . PHP_EOL);
  exit(1);
}

/**
 * @param array<string, string|false> $options
 */
function required_option(array $options, string $name): string {
  $value = $options[$name] ?? false;
  if (!is_string($value) || $value === '') {
    fail("Missing required --{$name} option.");
  }
  return $value;
}

/**
 * @param mixed $value
 * @param array<int, array<string, mixed>> $comments
 */
function collect_comments(mixed $value, array &$comments): void {
  if (!is_array($value)) {
    return;
  }

  if (array_key_exists('body', $value) && array_key_exists('user', $value)) {
    /** @var array<string, mixed> $value */
    $comments[] = $value;
    return;
  }

  foreach ($value as $child) {
    collect_comments($child, $comments);
  }
}

$options = getopt('', [
  'comments:',
  'owner:',
  'candidate-revision:',
  'payload-sha256:',
  'preprod-fr-url:',
  'preprod-en-url:',
  'language-mode:',
]);
if (!is_array($options)) {
  fail('Unable to parse approval options.');
}

$commentsPath = required_option($options, 'comments');
$owner = required_option($options, 'owner');
$candidateRevision = required_option($options, 'candidate-revision');
$payloadSha256 = required_option($options, 'payload-sha256');
$preprodFrUrl = required_option($options, 'preprod-fr-url');
$preprodEnUrl = required_option($options, 'preprod-en-url');
$languageMode = required_option($options, 'language-mode');

if (!preg_match('/^[1-9][0-9]*$/', $candidateRevision)) {
  fail('candidate-revision must be a positive numeric immutable comment identity.');
}
if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha256)) {
  fail('payload-sha256 must be a lowercase SHA-256 digest.');
}
if (!preg_match('#^https://preprod\.emergingdigital\.be/[^\s]+$#', $preprodFrUrl)) {
  fail('preprod-fr-url must be an exact PREPROD URL.');
}
if (!in_array($languageMode, ['FR_EN', 'FR_ONLY_EXCEPTION_APPROVED'], true)) {
  fail('language-mode must be FR_EN or FR_ONLY_EXCEPTION_APPROVED.');
}
if ($languageMode === 'FR_EN') {
  if (!preg_match('#^https://preprod\.emergingdigital\.be/[^\s]+$#', $preprodEnUrl)) {
    fail('FR_EN approval requires an exact PREPROD EN URL.');
  }
}
elseif ($preprodEnUrl !== 'NONE') {
  fail('FR_ONLY_EXCEPTION_APPROVED requires preprod-en-url=NONE.');
}

$raw = @file_get_contents($commentsPath);
if (!is_string($raw) || $raw === '') {
  fail('Approval comments file is missing or empty.');
}
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
  fail('Approval comments file is not valid JSON.');
}

$comments = [];
collect_comments($decoded, $comments);
if ($comments === []) {
  fail('No issue comments were available for human approval validation.');
}

$expectedBody = implode("\n", [
  '<!-- agency-human-prod-approval:v1 -->',
  'intent=APPROVE_THIS_EXACT_PREPROD_CANDIDATE_FOR_PROD',
  'candidate_revision=' . $candidateRevision,
  'payload_sha256=' . $payloadSha256,
  'preprod_fr_url=' . $preprodFrUrl,
  'preprod_en_url=' . $preprodEnUrl,
  'language_mode=' . $languageMode,
]);

foreach ($comments as $comment) {
  $user = $comment['user'] ?? null;
  if (!is_array($user)) {
    continue;
  }
  if (($user['login'] ?? null) !== $owner || ($user['type'] ?? null) !== 'User') {
    continue;
  }
  if (($comment['author_association'] ?? null) !== 'OWNER') {
    continue;
  }

  // Missing provenance is ambiguous and therefore rejected.
  if (!array_key_exists('performed_via_github_app', $comment)) {
    continue;
  }
  if ($comment['performed_via_github_app'] !== null) {
    continue;
  }

  $createdAt = $comment['created_at'] ?? null;
  $updatedAt = $comment['updated_at'] ?? null;
  if (!is_string($createdAt) || !is_string($updatedAt) || $createdAt !== $updatedAt) {
    // Edited approvals are rejected so a candidate change requires a new comment.
    continue;
  }

  $body = $comment['body'] ?? null;
  if (!is_string($body) || rtrim($body, "\r\n") !== $expectedBody) {
    continue;
  }

  $id = $comment['id'] ?? null;
  if (!(is_int($id) && $id > 0) && !(is_string($id) && preg_match('/^[1-9][0-9]*$/', $id))) {
    continue;
  }

  fwrite(STDOUT, 'human_approval=PASS' . PHP_EOL);
  fwrite(STDOUT, 'human_approval_comment_id=' . (string) $id . PHP_EOL);
  exit(0);
}

fail('No direct human approval matches the exact PREPROD candidate and language contract.');
