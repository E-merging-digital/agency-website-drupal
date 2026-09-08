<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Service;

use GuzzleHttp\ClientInterface;

/**
 * Reads trusted Cockpit PLAN receipts from the public GitHub REST API.
 */
final class CockpitPlanReceiptReader implements CockpitPlanReceiptReaderInterface {

  private const API_BASE = 'https://api.github.com/repos/E-merging-digital/agency-website-drupal';

  private const WEB_BASE = 'https://github.com/E-merging-digital/agency-website-drupal';

  private const REPOSITORY = 'E-merging-digital/agency-website-drupal';

  private const AUTHORITY_TITLE = '[Ops][PLAN] Prepare PREPROD refresh PLAN';

  private const AUTHORITY_MARKER = 'AGENCY_PREPROD_COCKPIT_PLAN_AUTHORITY={"authorized_actor":"E-merging-digital","implementation_issue":914,"mode":"PLAN","parent_issue":816,"profile_id":"agency-preprod-refresh-simple-v1","run_attempt":1,"schema_version":1}';

  private const RECEIPT_MARKER = 'AGENCY_PREPROD_COCKPIT_PLAN_RECEIPT=';

  private const PROFILE = 'agency-preprod-refresh-simple-v1';

  private const BACKFILL_AUTHORITY_ISSUE = 1094;

  private const BACKFILL_COMMENT_ID = 5575909815;

  private const MAX_REQUESTS = 3;

  private const RECEIPT_KEYS = [
    'schema_version',
    'receipt_source',
    'authority_issue',
    'dispatch_run',
    'run_attempt',
    'request_id',
    'mode',
    'profile_id',
    'main_sha',
    'jit_main_revalidation',
    'plan_result',
    'observed_prod_release_sha',
    'prod_db_content_read',
    'prod_snapshot',
    'prod_data_transfer',
    'preprod_db_mutation',
    'prod_write',
    'data_activation_authority',
    'apply_job',
    'issue_open_apply',
    'completed_at',
  ];

  /**
   * Number of public GitHub requests used by the current read.
   */
  private int $requestCount = 0;

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function read(): array {
    $this->requestCount = 0;

    try {
      $issues = $this->getJson(self::API_BASE . '/issues', [
        'state' => 'all',
        'creator' => 'E-merging-digital',
        'sort' => 'created',
        'direction' => 'desc',
        'per_page' => 20,
      ]);
      $authority = $this->latestAuthorityIssue($issues);
      if ($authority === NULL) {
        return $this->unavailable();
      }

      $authorityIssue = $authority['number'];
      $comments = $this->getJson(
        self::API_BASE . '/issues/' . $authorityIssue . '/comments',
        ['per_page' => 100],
      );
      $candidate = $this->latestReceiptComment($comments);
      if ($candidate === NULL) {
        return $this->unavailable();
      }

      $receipt = $this->decodeReceipt($candidate['body'] ?? NULL);
      if (!$this->validReceipt($receipt, $authorityIssue, $candidate)) {
        return $this->unavailable();
      }

      if ($receipt['receipt_source'] === 'WORKFLOW') {
        $run = $this->getJson(
          self::API_BASE . '/actions/runs/' . $receipt['dispatch_run'],
        );
        if (!$this->validWorkflowRun($run, $receipt)) {
          return $this->unavailable();
        }
      }

      return [
        'available' => TRUE,
        'status' => 'PASSED',
        'plan_result' => 'PASS',
        'authority_issue' => $receipt['authority_issue'],
        'request_id' => $receipt['request_id'],
        'main_sha' => $receipt['main_sha'],
        'observed_prod_release_sha' => $receipt['observed_prod_release_sha'],
        'completed_at' => $receipt['completed_at'],
        'receipt_source' => $receipt['receipt_source'],
        'dispatch_run' => $receipt['dispatch_run'],
        'jit_main_revalidation' => $receipt['jit_main_revalidation'],
        'mutation' => 'NONE',
        'apply' => 'NOT_AUTHORIZED',
        'authority_url' => $this->webUrl(
          '/issues/' . $receipt['authority_issue'],
        ),
        'run_url' => $this->webUrl(
          '/actions/runs/' . $receipt['dispatch_run'],
        ),
      ];
    }
    catch (\Throwable) {
      return $this->unavailable();
    }
  }

  /**
   * Finds the newest exact human-created Cockpit PLAN authority issue.
   */
  private function latestAuthorityIssue(array $issues): ?array {
    foreach ($issues as $issue) {
      if (!is_array($issue) || isset($issue['pull_request'])) {
        continue;
      }
      if (($issue['title'] ?? NULL) !== self::AUTHORITY_TITLE) {
        continue;
      }
      if (($issue['user']['login'] ?? NULL) !== 'E-merging-digital') {
        continue;
      }
      if (($issue['author_association'] ?? NULL) !== 'OWNER') {
        continue;
      }
      if (!array_key_exists('performed_via_github_app', $issue)
        || $issue['performed_via_github_app'] !== NULL) {
        continue;
      }
      if (!is_int($issue['number'] ?? NULL) || $issue['number'] < 1) {
        continue;
      }
      $body = $issue['body'] ?? NULL;
      if (!is_string($body)
        || !str_contains($body, 'Parent: #816')
        || substr_count($body, self::AUTHORITY_MARKER) !== 1) {
        continue;
      }

      return $issue;
    }

    return NULL;
  }

  /**
   * Finds the newest machine-readable receipt comment on one authority issue.
   */
  private function latestReceiptComment(array $comments): ?array {
    for ($index = count($comments) - 1; $index >= 0; $index--) {
      $comment = $comments[$index] ?? NULL;
      if (!is_array($comment)) {
        continue;
      }
      $body = $comment['body'] ?? NULL;
      if (is_string($body) && str_contains($body, self::RECEIPT_MARKER)) {
        return $comment;
      }
    }

    return NULL;
  }

  /**
   * Extracts exactly one canonical receipt marker from a comment body.
   */
  private function decodeReceipt(mixed $body): ?array {
    if (!is_string($body)) {
      return NULL;
    }

    $pattern = '/^' . preg_quote(self::RECEIPT_MARKER, '/')
      . '(\{[^\r\n]+\})$/m';
    if (preg_match_all($pattern, $body, $matches) !== 1) {
      return NULL;
    }

    try {
      $decoded = json_decode(
        $matches[1][0],
        TRUE,
        32,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException) {
      return NULL;
    }

    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Validates the entire receipt schema and mutation-free contract.
   */
  private function validReceipt(
    ?array $receipt,
    int $authorityIssue,
    array $comment,
  ): bool {
    if ($receipt === NULL) {
      return FALSE;
    }

    $keys = array_keys($receipt);
    $expectedKeys = self::RECEIPT_KEYS;
    sort($keys);
    sort($expectedKeys);
    if ($keys !== $expectedKeys) {
      return FALSE;
    }

    if (($receipt['schema_version'] ?? NULL) !== 1
      || ($receipt['authority_issue'] ?? NULL) !== $authorityIssue
      || ($receipt['run_attempt'] ?? NULL) !== 1
      || ($receipt['mode'] ?? NULL) !== 'PLAN'
      || ($receipt['profile_id'] ?? NULL) !== self::PROFILE
      || ($receipt['jit_main_revalidation'] ?? NULL) !== 'PASS'
      || ($receipt['plan_result'] ?? NULL) !== 'PASS'
      || ($receipt['prod_db_content_read'] ?? NULL) !== 'NONE'
      || ($receipt['prod_snapshot'] ?? NULL) !== 'NOT_PERFORMED'
      || ($receipt['prod_data_transfer'] ?? NULL) !== 'NONE'
      || ($receipt['preprod_db_mutation'] ?? NULL) !== 'NONE'
      || ($receipt['prod_write'] ?? NULL) !== 'NONE'
      || ($receipt['data_activation_authority'] ?? NULL) !== 'DISABLED'
      || ($receipt['apply_job'] ?? NULL) !== 'SKIPPED'
      || ($receipt['issue_open_apply'] ?? NULL) !== 'IMPOSSIBLE') {
      return FALSE;
    }

    if (!is_int($receipt['dispatch_run'] ?? NULL)
      || $receipt['dispatch_run'] < 1) {
      return FALSE;
    }
    if (($receipt['request_id'] ?? NULL)
      !== 'plan-' . $authorityIssue . '-cockpit-v1-r1') {
      return FALSE;
    }
    if (!$this->isSha($receipt['main_sha'] ?? NULL)
      || !$this->isSha($receipt['observed_prod_release_sha'] ?? NULL)) {
      return FALSE;
    }
    if (!$this->isUtcTimestamp($receipt['completed_at'] ?? NULL)) {
      return FALSE;
    }

    return match ($receipt['receipt_source'] ?? NULL) {
      'PROJECT_LEAD_BACKFILL' => $authorityIssue === self::BACKFILL_AUTHORITY_ISSUE
        && ($comment['id'] ?? NULL) === self::BACKFILL_COMMENT_ID
        && ($comment['user']['login'] ?? NULL) === 'E-merging-digital',
      'WORKFLOW' => ($comment['user']['login'] ?? NULL) === 'github-actions[bot]',
      default => FALSE,
    };
  }

  /**
   * Validates public Actions metadata for workflow-authored receipts.
   */
  private function validWorkflowRun(array $run, array $receipt): bool {
    return ($run['id'] ?? NULL) === $receipt['dispatch_run']
      && ($run['status'] ?? NULL) === 'completed'
      && ($run['conclusion'] ?? NULL) === 'success'
      && ($run['run_attempt'] ?? NULL) === 1
      && ($run['event'] ?? NULL) === 'issues'
      && ($run['head_sha'] ?? NULL) === $receipt['main_sha']
      && ($run['actor']['login'] ?? NULL) === 'E-merging-digital'
      && ($run['repository']['full_name'] ?? NULL) === self::REPOSITORY;
  }

  /**
   * Performs one fixed-host unauthenticated public GitHub REST GET.
   */
  private function getJson(string $url, array $query = []): array {
    $parts = parse_url($url);
    if (!is_array($parts)
      || ($parts['scheme'] ?? NULL) !== 'https'
      || ($parts['host'] ?? NULL) !== 'api.github.com'
      || !str_starts_with($url, self::API_BASE . '/')) {
      throw new \RuntimeException('Unexpected GitHub endpoint.');
    }

    $this->requestCount++;
    if ($this->requestCount > self::MAX_REQUESTS) {
      throw new \RuntimeException('GitHub request budget exceeded.');
    }

    $response = $this->httpClient->request('GET', $url, [
      'allow_redirects' => FALSE,
      'connect_timeout' => 1.0,
      'timeout' => 2.0,
      'http_errors' => FALSE,
      'query' => $query,
      'headers' => [
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'E-merging-Digital-Agency-Cockpit',
        'X-GitHub-Api-Version' => '2022-11-28',
      ],
    ]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('GitHub evidence is unavailable.');
    }

    try {
      $decoded = json_decode(
        (string) $response->getBody(),
        TRUE,
        32,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Invalid GitHub JSON.', 0, $exception);
    }

    if (!is_array($decoded)) {
      throw new \RuntimeException('Unexpected GitHub response shape.');
    }

    return $decoded;
  }

  /**
   * Validates a full Git SHA.
   */
  private function isSha(mixed $value): bool {
    return is_string($value) && preg_match('/^[0-9a-f]{40}$/', $value) === 1;
  }

  /**
   * Validates the canonical UTC receipt timestamp.
   */
  private function isUtcTimestamp(mixed $value): bool {
    if (!is_string($value)
      || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
      return FALSE;
    }

    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
    return $date !== FALSE && $date->format('Y-m-d\TH:i:s\Z') === $value;
  }

  /**
   * Builds one fixed repository web URL.
   */
  private function webUrl(string $path): string {
    return self::WEB_BASE . $path;
  }

  /**
   * Returns the fail-closed user-facing state.
   */
  private function unavailable(): array {
    return [
      'available' => FALSE,
      'status' => 'EVIDENCE_UNAVAILABLE',
      'plan_result' => 'NOT_INFERRED',
      'apply' => 'NOT_AUTHORIZED',
      'mutation' => 'NOT_INFERRED',
      'authority_url' => $this->webUrl('/issues'),
      'run_url' => $this->webUrl(
        '/actions/workflows/preprod-914-governed-successor.yml',
      ),
    ];
  }

}
