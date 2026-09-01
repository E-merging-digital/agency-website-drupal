# PREPROD data refresh from sanitized PROD data

Parent issue: #816.
Current repository implementation: #914 + completed #927 PLAN runner adaptation.
Architecture: `EXTEND_EXISTING` under `docs/decisions/ADR-003-use-existing-first.md`.

This is the durable safety contract for the one-way Agency PROD -> PREPROD database refresh. It is **not** code deployment, production mutation, editorial promotion or Development Seed distribution.

The current executable source is documented by `docs/operations/preproduction-refresh-governed-successor.md` and implemented under `scripts/preproduction-refresh/governed-successor/`. #816 remains open and the full real end-to-end refresh has not yet reached terminal execution proof.

## Current status

```text
REPOSITORY_IMPLEMENTATION = SOURCE_IMPLEMENTED
STATIC_SYNTHETIC_PROOF = COMPLETE
PLAN_RUNNER = ubuntu-24.04 / github-hosted
APPLY_RUNNER = self-hosted / linux / x64 / agency
REAL_PLAN = EXECUTED / FAIL_CLOSED
REAL_APPLY = PENDING fresh #816 child authority
REAL_END_TO_END_REFRESH = NOT_YET_PROVEN
FIRST_REAL_APPLY=NOT_AUTHORIZED
PROD_WRITE = NONE
PUBLIC_FILES = OUT_OF_SCOPE
PRIVATE_FILES = EXCLUDED
```

`FIRST_REAL_APPLY=NOT_AUTHORIZED` remains the current #816/#871 evidence boundary. #871 creates no APPLY authority. A future fresh #816 child may separately authorize one APPLY under the live #914 validator.

#927 is CLOSED / COMPLETED. It moved only PLAN to GitHub-hosted `ubuntu-24.04`; APPLY remains on `[self-hosted, linux, x64, agency]`. JIT-before-secret and pinned PROD/PREPROD SSH trust remain mandatory.

#929 executed the first real metadata-only PLAN on the hosted route. Authority and JIT passed, transient SSH identities were materialized only after JIT, the PLAN reached readiness observation and returned `FAIL_CLOSED`, cleanup succeeded, and APPLY was skipped. No PROD DB content read, PROD snapshot, PROD data transfer, PROD write or PREPROD mutation occurred. The exact failed readiness predicate is **NOT YET PROVEN** and must not be guessed.

#930 is OPEN / `status:in-progress` and is the current repository-only recovery tranche for bounded metadata-only PLAN diagnostics. #930 is not yet implemented/current behavior.

## Authority and replay

The current route supports exactly two modes:

- `PLAN`: mutation-free metadata/readiness observation on GitHub-hosted `ubuntu-24.04`.
- `APPLY`: bounded real refresh sequence on the trusted Agency self-hosted runner, only when a fresh separately authorized #816 child permits it.

#914 itself does not grant persistent execution authority. The authority validator requires an owner-created OPEN active issue under #816, a canonical marker bound to exact live `main`, exact mode/profile, one fresh request identity and `run_attempt = 1`.

Conceptual trigger shape:

```text
/agency-preprod-refresh-successor PLAN  plan-<authority-issue>-<fresh-id>-r1 <live-main-sha> AUTO <profile>
/agency-preprod-refresh-successor APPLY apply-<authority-issue>-<fresh-id>-r1 <live-main-sha> <exact-prod-release-sha> <profile>
```

A request emitted for execution is:

```text
CONSUMED / NEVER REUSE
```

Rerun/replay, duplicate request identity, stale `main` or trigger/marker mismatch fails closed. PLAN never authorizes APPLY.

Primary authority source:

- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/profile.json`

## PLAN contract

PLAN is mutation-free. The current #914 PLAN runs on GitHub-hosted `ubuntu-24.04`. Immediately before any SSH secret materialization, it revalidates exact live main, checked-out HEAD, one-shot authority and `runner.environment == github-hosted`.

After JIT only, transient PROD/PREPROD identities are written under `RUNNER_TEMP`. The runner provisions `known_hosts` only through existing repository-pinned trust primitives; `ssh-keyscan`, TOFU, `accept-new` and disabled host-key checking remain forbidden. PROD/PREPROD observations use strict pinned SSH trust.

Metadata-only PLAN jobs may remain on `ubuntu-24.04` because no raw PROD data is materialized or manipulated there.

PLAN may observe bounded metadata/readiness such as current release/promotion receipt identity, SSH readiness, PREPROD Drush/runtime capability, backup path, Config Split/admin convergence inputs and lock state.

PLAN performs no:

- PROD DB content read;
- PROD DB snapshot;
- PROD data transfer;
- raw staging import;
- PREPROD backup;
- PREPROD maintenance mutation;
- PREPROD DB/runtime mutation;
- production write.

The #929 result proves the hosted PLAN route executes and fails closed when readiness is not satisfied. It does **not** prove which predicate failed. #930 exists to make future failure classification bounded and non-sensitive without weakening readiness.

## APPLY contract — current implementation

A separately authorized APPLY uses existing primitives rather than the superseded transactional architecture.

### 1. JIT and trusted execution

Before SSH secrets are materialized:

1. reload live `main`;
2. prove exact checkout is that main;
3. revalidate fresh authority marker/request;
4. require `[self-hosted, linux, x64, agency]`.

The required runner labels are `self-hosted`, `linux`, `x64`, `agency`.

Raw-data execution is never moved to GitHub-hosted because a runner is unavailable or busy.

The durable #816 boundary allows raw data only on the trusted Agency runner **or** a separately reviewed strictly controlled server-to-server path where raw bytes never transit through GitHub-hosted infrastructure and raw PROD data never materializes on GitHub-hosted infrastructure. Current #914 APPLY uses the trusted Agency runner/DDEV route; this clause does not create a second current path.

### 2. Read-only PROD source

The source operation uses reviewed production Drush `sql:dump` against the exact source release. PROD is read-only and is never part of rollback. PROD is never part of rollback.

It may not enable PROD maintenance, mutate Drupal/config/users/tables, alter scheduler state, change the active release, write settings/credentials or trigger deployment.

### 3. Raw staging isolation before import

The current implementation uses DDEV as the isolated staging database. The safety order is mandatory:

```text
START exact source code in DDEV
-> detach web/db from normal Docker networks
-> attach only a fresh --internal Docker network
-> IMPORT RAW
-> BOOTSTRAP / SANITIZE
```

Raw PROD SQL exists only in protected transient trusted storage and the isolated staging database. While raw data is present, isolated DDEV containers have no normal external network egress.

```text
RAW PROD DATA ON GITHUB-HOSTED RUNNER = FORBIDDEN
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

Only after all assertions pass may sanitized SQL be exported. Raw transient material is deleted before transfer to PREPROD.

### 5. Sanitized-only PREPROD ingress

Only sanitized SQL is transferred over the pinned encrypted channel. PREPROD runtime never receives unsanitized PROD data.

The remote worker is launched detached as the non-root PREPROD deploy identity and then owns backup/maintenance/replacement/rollback independently of the initiating runner session.

### 6. Backup before destructive replacement

The worker acquires the existing PREPROD deploy lock and creates a protected Drush SQL backup. Before destructive replacement it requires that backup to exist, be non-symlink, non-empty, restrictively permissioned and SHA-256 verifiable.

No destructive replacement is allowed before this boundary.

### 7. Bounded maintenance and standard replacement

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

PREPROD shared settings, hash salt, DB credentials, Basic Auth/noindex host policy and other runtime ownership remain PREPROD-owned.

The refresh must **not** automatically execute `emerging:governed-content --all`. Governed Content is a separate repository/product baseline mechanism.

## Rollback and HUMAN_RECOVERY_REQUIRED

The remote worker owns rollback.

Failure before destructive replacement leaves PREPROD DB unchanged.

Failure after destructive replacement attempts:

```text
sql:drop
-> sql:cli < exact PREPROD backup
-> cr
-> validate-runtime
-> maint:set 0 only after proof
-> ROLLED_BACK
```

If exact restore/validation cannot be proven:

```text
HUMAN_RECOVERY_REQUIRED
maintenance remains ON
```

`HUMAN_RECOVERY_REQUIRED` is reserved for this real safety boundary. Ordinary CI/script/runner/transport/readiness defects are recoverable technical failures when they remain diagnosable within project authority. The #929 PLAN failure is not `HUMAN_REQUIRED`.

No `RECOVER_CURRENT`, `RECOVER_ABORT`, GitHub transaction reconstruction, historical target lookup or near-zero-downtime #915 swap belongs to the current route. #915/#917 are historical lineage only, not execution dependencies.

## Data classification

### Preserve for fidelity when present/relevant

- public/editorial nodes and revisions;
- taxonomy and translations;
- Paragraphs/entity-reference revisions;
- menu/link content;
- aliases and redirects;
- public media metadata needed by editorial content.

### Remove or sanitize

At minimum:

- imported user identity/auth/reset/session material;
- Webform submissions/data;
- sessions;
- flood/rate-limit state;
- watchdog/dblog request/user/IP state;
- imported queues;
- cache/render/discovery, batch and temporary state;
- cron/update/announcement/link-checker runtime state where policy requires;
- persisted provider/API credentials and production-only environment state.

The machine-readable authority is `scripts/preproduction-refresh/sanitization-policy.json` plus the current #914 sanitizer/assertions.

## PREPROD side-effect contract

After sanitized import/convergence and before maintenance reopens, PREPROD must still satisfy its own environment contract:

- production Config Split OFF;
- preproduction Config Split ON;
- GA/Google Tag outbound OFF;
- mail sink/null behavior without production credential;
- automated Drupal cron OFF;
- external AI/provider egress OFF by default;
- no production webhook/API credential;
- externally acting queues empty/bounded;
- Webform submissions and active sessions absent;
- Drupal bootstrap/runtime validation PASS;
- Basic Auth/noindex preserved.

`scripts/preproduction/settings.php.template`, `scripts/preproduction/validate-runtime.sh` and `docs/operations/environment-side-effects.md` remain PREPROD runtime sources of truth.

## Settings and secrets

```text
PROD settings/secrets     = PROD-owned
PREPROD settings/secrets  = PREPROD-owned
DDEV settings/secrets     = local-owned
```

No production credential becomes a PREPROD runtime credential. PREPROD hash salt stays PREPROD-owned. No secret value belongs in repository documentation/evidence.

## Files

The current #914 route is database-only.

- private files: excluded;
- public-file synchronization: not implemented by this route;
- Stage File Proxy or another existing standard primitive should be evaluated before custom sync if public-file fidelity becomes necessary.

Private files are never copied by default. Do not silently add files to the DB refresh mechanism.

## Evidence contract

Evidence is metadata only. Safe examples include authority/request identity, repository/source release SHA, policy version/digest, byte size/hash, aggregate removed-row counts, backup/result identity and bounded PASS/FAIL/terminal state.

Never expose raw SQL, copied values, email/IP/PII, passwords/hashes, session/reset tokens, DB credentials, API tokens or private files.

## Relationship to other flows

- **Code/config deployment:** separate; refresh preserves currently deployed PREPROD application release.
- **PROD promotion:** never triggered by refresh.
- **Governed Content:** not automatically applied because data refresh occurred.
- **Editorial publication:** separate bounded Drupal Entity API route; no DB promotion.
- **Development Seed:** #873 consumes a stricter development seed contract; real generation/distribution remains pending #816 + separate provisioning.

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

No command in this document grants execution authority. Before any future real PLAN/APPLY, reload current `main`, #816 and the fresh authority issue, then follow the exact live validator/workflow.
