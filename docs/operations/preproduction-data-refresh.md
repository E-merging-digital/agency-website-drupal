# PREPROD data refresh from sanitized PROD data

Parent issue: #816.
Current repository implementation: #914.
Architecture: `EXTEND_EXISTING` under `docs/decisions/ADR-003-use-existing-first.md`.

This is the durable safety contract for the one-way Agency PROD -> PREPROD database refresh. It is **not** code deployment, production mutation, editorial promotion or Development Seed distribution.

The current executable source is documented by `docs/operations/preproduction-refresh-governed-successor.md` and implemented under `scripts/preproduction-refresh/governed-successor/`. #914 provides source/static/synthetic proof only; #816 remains open and the full real end-to-end refresh has not yet reached terminal execution proof.

## Current status

```text
REPOSITORY_IMPLEMENTATION = SOURCE_IMPLEMENTED
STATIC_SYNTHETIC_PROOF = COMPLETE
REAL_PLAN = PENDING fresh #816 child authority
REAL_APPLY = PENDING fresh #816 child authority
REAL_END_TO_END_REFRESH = NOT_YET_PROVEN
PROD_WRITE = NONE
PUBLIC_FILES = OUT_OF_SCOPE
PRIVATE_FILES = EXCLUDED
```

Do not infer real execution from source, CI, predecessor component proofs or issue history.

## Authority and replay

The current route supports exactly two modes:

- `PLAN`: mutation-free metadata/readiness observation.
- `APPLY`: the bounded real refresh sequence, but only when a fresh separately authorized #816 child issue permits it.

#914 itself cannot execute. The current authority validator requires an owner-created OPEN active issue under #816, a canonical marker bound to exact live `main`, exact mode/profile, one fresh request identity and `run_attempt = 1`.

The accepted trigger shape is derived from the canonical marker; the validator remains authoritative. Conceptually:

```text
/agency-preprod-refresh-successor PLAN  plan-<authority-issue>-<fresh-id>-r1 <live-main-sha> AUTO <profile>
/agency-preprod-refresh-successor APPLY apply-<authority-issue>-<fresh-id>-r1 <live-main-sha> <exact-prod-release-sha> <profile>
```

A request emitted for execution is:

```text
CONSUMED / NEVER REUSE
```

Rerun/replay, duplicate request identity, stale `main` or a trigger that differs from the issue marker fails closed. PLAN never authorizes APPLY.

Primary authority source:

- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/profile.json`

## PLAN contract

PLAN runs on the trusted Agency executor after exact authority/JIT checks and may observe only the metadata/readiness required for a future APPLY, including current release identities, SSH trust/readiness, PREPROD Drush/runtime checks, backup path, Config Split/admin convergence inputs and lock presence.

PLAN performs no:

- PROD DB snapshot or data read beyond bounded metadata/readiness checks;
- PROD data transfer;
- raw staging import;
- PREPROD DB backup;
- PREPROD maintenance mutation;
- PREPROD DB replacement;
- production or PREPROD side-effect mutation.

`PLAN = PASS` means only that its exact preconditions passed for that exact request/main observation.

## APPLY contract — current implementation

A separately authorized APPLY uses existing primitives rather than the superseded transactional architecture.

### 1. JIT and trusted execution

Before SSH secrets are materialized:

1. reload live `main`;
2. prove the exact checkout is that main;
3. revalidate the exact fresh authority marker/request;
4. require the fixed trusted Agency runner.

Raw-data execution is never moved to GitHub-hosted infrastructure because a runner is unavailable or busy.

### 2. Read-only PROD source

The source operation uses the reviewed production Drush `sql:dump` primitive against the exact source release. This mechanism has no PROD write authority.

It may not:

- enable PROD maintenance;
- write Drupal entities/config/users/tables;
- change cron/scheduler state;
- change the active PROD release;
- write settings/credentials;
- trigger deployment.

PROD is never part of refresh rollback.

### 3. Raw staging isolation before import

The safety order is mandatory:

```text
START exact source code in DDEV
-> detach web/db from normal Docker networks
-> attach only a fresh --internal Docker network
-> IMPORT RAW
-> BOOTSTRAP / SANITIZE
```

Raw PROD SQL exists only in protected transient trusted storage and the isolated DDEV DB. While raw data is present, the isolated DDEV containers have no normal external network egress.

```text
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW PROD SQL AS GITHUB ARTIFACT = FORBIDDEN
RAW PROD SQL IN LOGS = FORBIDDEN
```

### 4. Sanitization stack

After isolation/import:

```text
Drush 13.7.6 sql:sanitize
-> scripts/preproduction-refresh/governed-successor/agency-sanitize.php
-> fail-closed Agency assertions
```

Drush owns generic user email/password/session sanitization. The thin Agency pass owns project-specific gaps such as usernames, Webform data, flood, watchdog, queues, caches/temp/runtime state and persisted provider/key configuration.

Only after all required assertions pass may a sanitized SQL dump be exported. Raw transient material is deleted before sanitized transfer to PREPROD.

### 5. Sanitized-only PREPROD ingress

Only sanitized SQL is transferred to PREPROD over the pinned encrypted channel. The PREPROD runtime never receives unsanitized PROD data.

The remote worker is launched detached as the non-root PREPROD deploy identity. After launch it owns the terminal backup/maintenance/replacement/rollback path independently of the initiating SSH/runner session.

### 6. Backup before destructive replacement

The worker acquires the existing PREPROD deploy lock and creates a protected Drush SQL backup of the current PREPROD runtime DB.

Before any destructive replacement it requires:

- backup file exists and is not a symlink;
- backup is non-empty;
- restrictive permissions;
- SHA-256 computed successfully.

No destructive replacement is allowed before this boundary.

### 7. Bounded maintenance and standard replacement

The current route accepts bounded PREPROD downtime to remain simple and recoverable:

```text
maint:set 1
-> sql:drop
-> sql:cli < sanitized SQL
-> updb
-> cim
-> PREPROD split import
-> PREPROD server-owned admin reconciliation
-> cr
-> validate-runtime
-> maint:set 0
-> COMMITTED
```

PREPROD shared settings, hash salt, DB credentials, Basic Auth/noindex host policy and other runtime ownership remain PREPROD-owned; they are not copied from PROD.

## Rollback and HUMAN_RECOVERY_REQUIRED

The remote worker owns rollback.

### Failure before destructive replacement

If the destructive step has not started, the sanitized input is removed and the result is a bounded rollback/no-runtime-mutation outcome. PREPROD DB remains unchanged.

### Failure after destructive replacement

The worker attempts:

```text
sql:drop
-> sql:cli < exact PREPROD backup
-> cr
-> validate-runtime
-> maint:set 0 only after proof
-> ROLLED_BACK
```

Rollback success means the exact backup was restored and runtime validation passed.

### Unprovable rollback

If exact restore/validation cannot be proven:

```text
HUMAN_RECOVERY_REQUIRED
maintenance remains ON
```

`HUMAN_RECOVERY_REQUIRED` is reserved for this real safety boundary. Ordinary CI/script/runner/transport defects that remain diagnosable within project authority are recoverable technical failures, not a manual escalation by default.

No `RECOVER_CURRENT`, `RECOVER_ABORT`, GitHub transaction reconstruction, historical target lookup or near-zero-downtime #915 swap belongs to the current operational route. #915/#917 are historical merged lineage only, not execution dependencies.

## Data classification

### Preserve for fidelity when present/relevant

- public/editorial nodes and revisions;
- taxonomy and translations;
- Paragraphs/entity-reference revisions;
- menu/link content needed by current behavior;
- aliases and redirects;
- public media metadata required by editorial content;
- other public editorial entity state required to reproduce PROD behavior.

### Remove or sanitize

At minimum:

- imported user identity is minimized/anonymized and authentication/reset/session material invalidated;
- Webform submissions/data are removed;
- sessions are removed;
- flood/rate-limit state is removed;
- watchdog/dblog request/user/IP state is removed;
- imported queues are removed by default, especially externally acting mail/link-checker/AI/provider work;
- cache/render/discovery, batch and temporary state are cleared;
- cron/update/announcement/link-checker runtime state is reset where policy requires;
- persisted provider/API credentials and production-only environment state are removed;
- unknown credential-bearing/external-action state fails closed when it cannot be mapped safely.

The machine-readable authority is `scripts/preproduction-refresh/sanitization-policy.json` plus the current #914 sanitizer/assertions.

## PREPROD side-effect contract

After sanitized import/convergence and before maintenance reopens, PREPROD must still satisfy its own environment contract:

- production Config Split OFF;
- preproduction Config Split ON;
- GA/Google Tag outbound OFF;
- mail sink/null behavior with no production credential;
- automated Drupal cron OFF;
- external AI/provider egress OFF by default;
- no production webhook/API credential;
- imported externally acting queues empty/bounded according to policy;
- Webform submissions and active sessions absent;
- Drupal bootstrap/runtime validation PASS;
- Basic Auth/noindex remain host-owned and preserved.

`scripts/preproduction/settings.php.template`, `scripts/preproduction/validate-runtime.sh` and `docs/operations/environment-side-effects.md` remain the normal PREPROD runtime sources of truth.

## Settings and secrets

Environment ownership is non-transferable:

```text
PROD settings/secrets     = PROD-owned
PREPROD settings/secrets  = PREPROD-owned
DDEV settings/secrets     = local-owned
```

The PREPROD hash salt remains PREPROD server-owned. No production credential becomes a PREPROD runtime credential. No secret value belongs in repository documentation/evidence.

## Files

The current #914 route is database-only.

- private files: excluded;
- public-file synchronization: not implemented by this route;
- Stage File Proxy or another existing standard primitive should be evaluated before custom synchronization if public-file fidelity becomes necessary.

Do not silently add files to the DB refresh mechanism.

## Evidence contract

Evidence is metadata only. Safe examples:

- authority issue/request ID;
- repository/source release SHA;
- sanitization policy version/digest;
- dump/backup byte size and SHA-256;
- aggregate removed-row counts without values;
- PREPROD backup/result identity;
- side-effect/runtime PASS/FAIL;
- terminal `COMMITTED`, `ROLLED_BACK` or `HUMAN_RECOVERY_REQUIRED` outcome.

Never expose raw SQL, copied data values, email/IP/PII, passwords/hashes, session/reset tokens, DB credentials, API tokens or private files.

## Relationship to other flows

- **Code/config deployment:** separate; refresh preserves the currently deployed PREPROD application release.
- **PROD promotion:** never triggered by refresh.
- **Editorial publication:** separate bounded Drupal Entity API route; no database promotion.
- **Development Seed:** #873 consumes an already sanitized, stricter development seed contract; real generation/distribution remains pending #816 + separate authorization.

## Authoritative implementation

- `docs/operations/preproduction-refresh-governed-successor.md`
- `.github/workflows/preprod-914-governed-successor.yml`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`
- `scripts/preproduction-refresh/governed-successor/agency-sanitize.php`
- `scripts/preproduction-refresh/sanitization-policy.json`
- `scripts/preproduction/settings.php.template`

No command in this document grants execution authority. Before any real PLAN/APPLY, reload current `main`, #816 and the fresh authority issue, then follow the exact live validator/workflow.