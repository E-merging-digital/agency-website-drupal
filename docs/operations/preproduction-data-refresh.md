# PREPROD data refresh from anonymized PROD snapshot

Issue: #816.

This document is the durable safety contract for refreshing Agency PREPROD data from a production snapshot. It is a data-refresh mechanism, not an application deployment. It must preserve the immutable application release already active in PREPROD and must never trigger production promotion.

## Authority and phases

The mechanism has two distinct phases:

- `PLAN`: repository/preflight only. It may validate non-sensitive metadata, prerequisites, capacity declarations, refresh-lock state and expected release identities. It performs no PROD write, no PROD snapshot, no PROD data transfer, no PREPROD database import/replacement/activation and no runtime side-effect mutation.
- `APPLY`: future owner-authorized operation. It is not implemented or authorized by the first #816 tranche. The first real APPLY is a human boundary after Project Lead review.

Issue creation, branch creation, PR merge, PLAN success, old comments and previous production GO are not APPLY authority.

The first implementation intentionally has no executable APPLY path. A future APPLY must require a newly created OWNER authorization bound to all of: refresh request ID, current PROD release identity, current PREPROD release identity, sanitization policy digest, expected source snapshot identity and the exact requested transition. Authorization must be anti-replay and auditable.

## Execution boundary for raw PROD data

GitHub-hosted infrastructure is allowed only as an authority, metadata and validation gateway for this mechanism. A GitHub-hosted job may validate repository state, exact identities, policy, non-sensitive aggregate metadata and authorization prerequisites.

`RAW PROD DATA ON GITHUB-HOSTED RUNNER = FORBIDDEN`.

Any future step that materializes or manipulates raw production snapshot data must use exactly one of these execution paths:

- the trusted Agency runner with all required labels `self-hosted`, `linux`, `x64`, `agency`; or
- a strictly controlled server-to-server path between controlled hosts where the raw production data never transits through and never materializes on GitHub-hosted infrastructure.

A future implementation must fail closed if a raw-data step is assigned to any other runner class or if a server-to-server path would route raw bytes through GitHub-hosted infrastructure. Metadata-only PLAN jobs may remain on `ubuntu-24.04` because they do not materialize or manipulate raw production data.

This boundary is independent from artifact policy: raw production SQL/files are forbidden as GitHub artifacts even when the raw-data step itself runs on trusted infrastructure.

## Production boundary

PROD is strictly read-only for this mechanism. A future snapshot operation may read the production database, but the refresh implementation must have no route that can:

- enable PROD maintenance mode;
- alter Drupal configuration/content/users/tables;
- change PROD cron or scheduler state;
- change the active PROD release;
- write credentials/settings into PROD;
- trigger a production deployment.

The phase-1 PLAN route does not connect to PROD and does not create a snapshot.

## Data classification

### Preserve for fidelity

Where present in the source schema and required by the current application release, preserve public/editorial state such as:

- nodes and revisions;
- taxonomy, translations and aliases;
- paragraphs/entity-reference revisions;
- menu/link content and redirects;
- media metadata required by public editorial content;
- other public editorial entity state required to reproduce production behavior.

Private files are never copied by default. Public-file synchronization is out of scope for this first tranche.

### Mandatory sanitization/removal before any future activation

The repository-owned policy in `scripts/preproduction-refresh/sanitization-policy.json` is authoritative for the first contract version. A future sanitizer must discover the actual imported schema and fail closed when a mandatory sensitive surface cannot be mapped safely.

At minimum it must deterministically handle:

- Drupal users: imported identifying values are anonymized; imported password/session/reset material is invalidated; imported accounts do not become the PREPROD administrative access route. A PREPROD-only administrator is recreated/preserved from server-owned PREPROD bootstrap state after sanitization. Imported user email/name transformations must be deterministic by UID and use non-routable `example.invalid` values.
- Webform submissions and submission data: delete by default.
- Sessions: delete all.
- Flood/rate-limit state: delete all imported state.
- `dblog`/watchdog: delete imported rows because request/user/IP data may be present.
- caches, render/dynamic cache and discovery caches: clear imported cache state.
- batch/temp state: clear.
- queues: clear imported queue items by default, including Link Checker, mail and AI/provider work. Unknown externally acting queue state fails closed.
- cron/update/announcements/link-checker execution state: reset imported runtime state before activation.
- one-time login/password-reset/session material: invalidate/remove.
- persisted credentials or secret-like state: remove or replace from PREPROD server-owned state; unknown credential-bearing state fails closed.
- production-only environment state: never survives activation.

`drush sql:sanitize` may be used as one layer in a future APPLY, but it is not sufficient proof by itself and does not replace Agency-specific sanitization.

## PREPROD side-effect hardening

Before a future imported database may become active, fail closed unless all of the following are proven against the isolated staged database/current PREPROD runtime contract:

- production Config Split = OFF;
- preproduction Config Split = ON;
- Google Tag/GA4 outbound behavior = OFF;
- mail uses the PREPROD sink/null behavior and contains no production credential;
- Drupal automated cron = OFF;
- external AI/provider egress = OFF and no production provider credential is available;
- no production webhook/API credential is present;
- copied Link Checker/other externally acting queues are empty or explicitly bounded by a future approved fixture policy;
- Webform submissions = 0;
- active sessions = 0;
- staged Drupal bootstrap health = PASS;
- Basic Auth remains preserved by the host boundary;
- PREPROD noindex remains preserved by the host boundary.

The existing `scripts/preproduction/settings.php.template` and `scripts/preproduction/validate-runtime.sh` remain the application-side source of truth for the normal PREPROD side-effect contract. #816 must not weaken them.

## Isolated staging and activation design

A future APPLY must use an isolated staging database. The public PREPROD web runtime must never point to the imported database before sanitization and all side-effect assertions are terminal PASS.

Required future sequence:

1. acquire a dedicated PREPROD data-refresh lock;
2. prove current PROD/PREPROD release identities and capacity;
3. create a read-only PROD snapshot into transient material with restrictive permissions (`0600`) only on an execution path allowed by the raw-data boundary above;
4. transfer transient raw material only over an encrypted transport, only through an allowed raw-data execution path, and never through GitHub-hosted infrastructure or GitHub artifacts;
5. import into an isolated PREPROD staging DB that is not referenced by the public runtime;
6. sanitize deterministically and run fail-closed assertions;
7. bootstrap/validate the staged DB with the currently deployed PREPROD application release;
8. create and verify a backup of the current PREPROD DB and record the previous data-refresh identity;
9. activate only the sanitized staging data boundary while preserving the application release identity;
10. run applicable `updb`/canonical config convergence, PREPROD split and runtime validation;
11. publish metadata-only evidence and clean transient raw material on success or failure.

PROD is never part of rollback.

## Rollback boundary

Before activation a future APPLY must record:

- verified current PREPROD DB backup identity;
- previous PREPROD data-refresh identity;
- active PREPROD application release identity.

If activation or post-activation validation fails, restore the previous PREPROD data boundary without changing the application release. No automatic or manual rollback action is ever performed against PROD.

## Governed Content rule

A sanitized production snapshot is the fidelity baseline. The refresh path must **not** automatically execute `emerging:governed-content --all`, because that could silently overwrite production editorial fidelity.

After a refresh, the current release may run Governed Content validation and dry-run for evidence. Applying governed content is allowed only when the currently deployed release explicitly requires that convergence and the change is authorized as part of the release/content governance path. Data refresh itself is not authority to overwrite refreshed editorial content.

## Evidence contract

GitHub evidence is metadata only. Raw SQL, copied files, PII and secrets are forbidden.

Allowed evidence for this mechanism is limited to stable identifiers and aggregate metadata such as:

- refresh request ID;
- repository/release SHA identities;
- sanitization policy version and SHA-256;
- aggregate byte counts/capacity values;
- aggregate removed-row counts without values;
- source snapshot digest/size after a future APPLY (digest/size only, never bytes);
- PREPROD backup/data-refresh identities;
- PASS/FAIL side-effect assertions;
- health/smoke outcomes.

No email address, IP address, password/hash, session/reset token, form submission value, API token, credential, SQL content or private file content may be logged, committed, attached to an issue/PR or uploaded as an Actions artifact.

The phase-1 PLAN workflow uploads only `artifacts/preproduction-refresh-plan/plan.env`, whose keys are generated from a fixed allowlist by `scripts/preproduction-refresh/plan.sh`.

## Public files

Public-file synchronization is not implemented in this tranche. If later required:

- capacity must be checked first;
- private files remain `NEVER` by default;
- only already-public PROD files may be considered;
- generated/cache/temp files should be excluded where reproducible;
- a staged/rollback boundary must be preserved;
- any raw PROD file synchronization must obey the same trusted Agency runner or controlled server-to-server execution boundary and may never materialize raw PROD files on GitHub-hosted infrastructure.

## Phase-1 stop boundary

For #816 phase 1:

```text
PROD_WRITE_PATH=NONE
REAL_PROD_DATA_TRANSFER=NONE
PREPROD_DB_ACTIVATION=NONE
RAW_PROD_DATA_IN_GITHUB=NONE
FIRST_REAL_APPLY=NOT_AUTHORIZED
```

Once the repository PLAN implementation and exact-head CI are terminal green, Delivery stops for Project Lead review before any real PROD snapshot, data transfer, staging import or PREPROD mutation.
