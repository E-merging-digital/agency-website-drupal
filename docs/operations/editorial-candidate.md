# Editorial Candidate V1 — Article FR/EN in PREPROD

Status: **SOURCE_IMPLEMENTED / SYNTHETIC PROOF PENDING / REAL PREPROD PROOF PENDING**
Owner: #959 under parent capability #872.
First real consumer after merge: #958.

## Scope

V1 is deliberately Article-only:

```text
bundle = article
operation = CREATE / PREPROD review materialization
FR = REQUIRED
EN = REQUIRED
PROD_WRITE = NONE
```

It does not create a generic entity writer, candidate service, registry, state machine, Content Sync route, Governed Content route, Canvas integration or automatic PROD publication.

## Durable candidate

The durable candidate is the existing #576 owner-authored Article payload comment:

```text
<!-- agency-editorial-payload:v1 -->
{ ...existing #576 closed Article JSON... }
```

No second Article schema exists. Candidate metadata is derived from that existing record:

```text
candidate_id       = agency-article-<issue_number>
candidate_revision = GitHub payload comment id
payload_sha256     = SHA-256 of #576 canonical JSON + one newline
source_issue       = existing issue_number
bundle             = existing bundle=article
publication_intent = existing published boolean
```

The canonical JSON/hash rules are the same as #576: UTF-8 JSON, sorted keys, compact separators and exactly one trailing newline.

```text
GitHub issue/comment = durable governed candidate/control record
PREPROD Drupal       = disposable rendered review materialization
PROD Drupal          = editorial source of truth only after later human-approved publication
```

A PREPROD refresh may discard the rendered node without losing the candidate record.

## Existing capability reuse

The route reuses:

1. #576 `AgencyEditorialPublication` closed Article schema and validation;
2. #576 initial FR Article + EN translation creation through Drupal Entity API;
3. #576 issue-to-node/hash mapping and exact replay idempotence;
4. #576 `EditorialPathautoFinalizer` for FR/EN aliases;
5. existing PREPROD SSH identity `agency-preprod` and pinned host trust;
6. existing `/var/www/agency-preprod/current` Drush bootstrap;
7. existing `scripts/preproduction/validate-runtime.sh` before and after execution;
8. existing single command dispatcher.

The only V1 extension is PREPROD-targeted candidate replacement when the same issue receives a different approved payload hash. It remains hard-coded to Article fields already owned by #576 and uses Drupal Entity API only.

## Commands

The existing top-level dispatcher recognizes exactly:

```text
/agency-editorial-candidate inspect
/agency-editorial-candidate dry-run
/agency-editorial-candidate apply
```

The reusable workflow is `.github/workflows/trusted-editorial-preprod-candidate.yml`. It receives only `PREPROD_SSH_PRIVATE_KEY` and `PREPROD_SERVER_HOST`; no PROD SSH secret is mapped.

`apply` requires a previous bot-authored PREPROD candidate dry-run PASS for the exact tuple:

```text
payload_sha256
candidate_revision
live main SHA
```

A later owner-authored payload comment changes `candidate_revision`; any content change changes `payload_sha256`. Either invalidates the prior apply approval.

Immediately before the PREPROD SSH key is materialized, the workflow reloads live main and reloads the latest owner-authored payload comment, then recalculates the same #576 canonical hash. Any drift fails closed.

## PREPROD execution boundary

The runner fixes all operational targets:

```text
SSH user     = agency-preprod
project root = /var/www/agency-preprod
current      = /var/www/agency-preprod/current
Drush        = current/vendor/bin/drush
```

The caller cannot supply a shell command, Drush command, server path or Unix user. Host trust is repository-pinned. Payload transport is data-only and the on-host SHA-256 is verified before Drupal bootstrap mutation.

PREPROD runtime isolation is validated both before and after the candidate operation. Existing checks cover cron, mail transport/credentials, Config Split, Google Tag, Link Checker host binding and external AI/OpenAI egress.

```text
PROD_ACCESS = NONE
PROD_WRITE = NONE
PROD_SECRET = NONE
ARBITRARY_SHELL = NONE
ARBITRARY_DRUSH = NONE
ARBITRARY_PATH = NONE
ARBITRARY_BUNDLE = NONE
```

## Idempotence and changed payloads

Exact replay of the same issue/hash delegates to #576 and returns `IDEMPOTENT`; it creates neither a duplicate Article nor an unnecessary revision.

For a changed payload, the PREPROD-only helper requires the existing mapping to point to the expected FR Article and validates the new payload through #576 before mutating the already-mapped PREPROD preview. The same node receives a new revision, the EN translation remains present, the category must already exist, and the mapping is updated to the new payload SHA. A second replay of that new hash must again converge to `IDEMPOTENT`.

This changed-payload behavior is only for disposable PREPROD review. It does not extend #576 PROD publication to arbitrary updates.

## Review URLs and evidence

Successful PREPROD execution returns metadata only:

```text
candidate_id
candidate_revision
payload_sha256
node_id
revision_id
FR alias / PREPROD URL
EN alias / PREPROD URL
PREPROD current release
PREPROD runtime validation = PASS
PROD_WRITE = NONE
```

The payload/Article body is not uploaded as evidence. The durable source remains the owner-authored GitHub payload comment.

PREPROD Basic Auth/noindex/protection remains unchanged. Rendering the Article in protected PREPROD never grants PROD publication authority.

## Image boundary

V1 does not include a feature image. #584 remains the proven bounded Article image primitive, but it is not duplicated or widened here.

```text
TEXT/METADATA PREPROD FIRST
IMAGE FOLLOW-UP ONLY AFTER PROVEN GAP
```

## Phase boundary

Before merge, #959 is repository implementation and synthetic/exact-head validation only:

```text
PREPROD_ACCESS = NONE
REAL_PREPROD_#958 = NOT_YET_EXECUTED
PROD_ACCESS = NONE
PROD_WRITE = NONE
MERGE = NOT_PERFORMED_BY_DELIVERY
```

After Project Lead accepts and merges the implementation, the first real proof remains on #958: post the exact #576 Article payload, dry-run the candidate, apply the exact approved hash to PREPROD, validate FR/EN rendering with existing browser capability where available, and return the PREPROD URLs/hash for human review. PROD publication is a later separately authorized step.

Primary sources:

- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/trusted-editorial-preprod-candidate.yml`
- `.github/workflows/trusted-editorial-publication.yml`
- `scripts/runner/editorial-publication.php`
- `scripts/runner/editorial-preprod-candidate.php`
- `scripts/runner/editorial-preprod-candidate-runner.php`
- `scripts/runner/run-editorial-preprod-candidate.sh`
- `scripts/preproduction/validate-runtime.sh`
- `web/modules/custom/agency_project_tests/tests/src/Kernel/GovernedEditorialPublicationKernelTest.php`
- `web/modules/custom/agency_project_tests/tests/src/Kernel/GovernedEditorialPreprodCandidateKernelTest.php`
