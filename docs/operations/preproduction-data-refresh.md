# PREPROD data refresh from sanitized PROD data

Parent implementation program: #816 (**CLOSED / COMPLETED**).
Current repository implementation: #914 governed successor with controlled server-to-server APPLY.
Architecture: `EXTEND_EXISTING` under `docs/decisions/ADR-003-use-existing-first.md`.

This is the durable safety contract for the one-way Agency PROD -> PREPROD database refresh. It is not code deployment, production mutation, editorial promotion or Development Seed distribution.

## Current status

```text
REPOSITORY_IMPLEMENTATION = SOURCE_IMPLEMENTED
STATIC_SYNTHETIC_PROOF = COMPLETE
PROD_TO_PREPROD_REFRESH = REAL_EXECUTION_PROVEN
PLAN_RUNNER = ubuntu-24.04 / github-hosted
PLAN_RESULT = PASS / REAL_EXECUTION_PROVEN
APPLY_CONTROL_PLANE = ubuntu-24.04 / github-hosted
APPLY_EXECUTION_PATH = CONTROLLED_SERVER_TO_SERVER / CURRENT
TERMINAL_REAL_PROOF = #953 / COMMITTED
PREPROD_RUNTIME = SANITIZED_DATABASE_ACTIVE_AND_VALIDATED
PROD_ACCESS = READ_ONLY_REQUEST_SCOPED_TRANSIENT
PROD_WRITE = NONE
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_ROUTE = PROD_TO_PREPROD_DIRECT
SANITIZED_ONLY_ACTIVATION = PASS
HUMAN_RECOVERY_REQUIRED = NO
TRUSTED_SELF_HOSTED_RUNNER = AUTHORIZED ALTERNATIVE
PUBLIC_FILES = OUT_OF_SCOPE
PRIVATE_FILES = EXCLUDED
```

#953 is CLOSED / COMPLETED and provides the terminal real APPLY proof. Its one-shot request is consumed / never reusable. The operational recipe does not depend on a historical run ID or frozen SHA: every future execution must reload current live state and obtain fresh authority.

#937 remains the real proof that the hosted PLAN route reaches terminal `PASS` with no PROD DB content read, no snapshot, no data transfer, no PROD write and no PREPROD mutation.

The trusted Agency runner remains a registered authorized raw-data execution alternative under the existing policy, with labels `self-hosted`, `linux`, `x64`, `agency`. Live availability is dynamic and must be reloaded before relying on it; the current controlled server-to-server route does not require raw PROD data on GitHub-hosted infrastructure.

## Authority and replay

The current route supports exactly two modes:

- `PLAN`: mutation-free metadata/readiness observation on GitHub-hosted `ubuntu-24.04`.
- `APPLY`: a separately authorized bounded refresh. GitHub-hosted is control plane only; raw PROD travels directly PROD -> PREPROD.

#816 is closed and is not reopened for routine execution. #914 does not grant persistent execution authority. The validator requires a fresh owner-created active one-shot authority accepted by the current route, exact live `main`, exact mode/profile, a fresh request identity and `run_attempt = 1`.

```text
CONSUMED / NEVER REUSE
```

Rerun/replay, duplicate request identity, stale `main` or trigger/marker mismatch fail closed. PLAN never authorizes APPLY.

## PLAN contract

PLAN runs on GitHub-hosted `ubuntu-24.04`. Immediately before SSH secret materialization it revalidates exact live main, checked-out HEAD, one-shot authority and `runner.environment == github-hosted`.

After JIT only, transient PROD/PREPROD identities are written under `RUNNER_TEMP`. Existing repository-pinned trust primitives are used; TOFU, `ssh-keyscan`, `accept-new` and disabled host-key checking remain forbidden.

Metadata-only PLAN jobs may remain on `ubuntu-24.04` because no raw PROD data is materialized or manipulated there.

PLAN performs no:

- PROD DB content read;
- PROD DB snapshot;
- PROD data transfer;
- raw staging import;
- PREPROD backup;
- PREPROD maintenance mutation;
- PREPROD DB/runtime mutation;
- production write.

Current real evidence is `PLAN_RESULT=PASS`.

## APPLY contract — current controlled server-to-server route

The current APPLY reuses the existing #914 workflow, read-only snapshot primitive, bounded staging helper, single sanitizer/policy and activation/rollback worker. It is not a generic multi-provider execution architecture.

### 1. GitHub-hosted control plane only

Before any APPLY SSH identities are materialized:

1. resolve live `main`;
2. checkout that exact SHA;
3. revalidate fresh one-shot APPLY authority;
4. require `runner.environment == github-hosted`.

After JIT, GitHub-hosted may handle only fixed scripts, route identities and metadata needed to start the PREPROD-side worker.

```text
RAW PROD DATA ON GITHUB-HOSTED RUNNER = FORBIDDEN
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW SQL AS GITHUB ARTIFACT = FORBIDDEN
RAW PROD SQL IN LOGS = FORBIDDEN
RAW_PROD_GITHUB_TEMP = NONE
```

The durable policy permits the controlled server-to-server path only when raw data never materializes on GitHub-hosted infrastructure.

### 2. Direct read-only PROD source

The detached PREPROD preparation worker uses existing pinned PROD SSH trust and the reviewed `scripts/production-readonly-snapshot/remote-stream.sh` primitive.

The only raw route is:

```text
PROD
-> DIRECT SERVER-TO-SERVER STREAM
-> PREPROD isolated staging database
```

PROD is read-only. PROD is never part of rollback.

No generic PROD shell executor is introduced. The PROD identity is request-scoped, transient, root-only on PREPROD, mode `0600`, and used only for the existing fixed read-only snapshot route.

### 3. Raw PREPROD boundary

The raw stream is imported only into the request-derived staging database managed by the existing bounded root-owned `agency-preprod-staging-db` capability.

The live PREPROD Drupal database `agency_preprod` is not targetable by that helper and the live runtime never connects to the raw staging database.

```text
PREPROD_LIVE_DB_RAW_IMPORT = FORBIDDEN
PREPROD_RUNTIME_ACCESS_TO_RAW = FORBIDDEN
SANITIZATION_BEFORE_ACTIVATION = REQUIRED
SANITIZATION_ASSERTIONS = REQUIRED
```

### 4. Existing single sanitization policy

The route reuses exactly one policy:

`scripts/preproduction-refresh/sanitization-policy.json`

and the already-installed root-owned server sanitizer. It preserves the #914 semantic result for:

- deterministic username anonymization;
- mail/init/password/auth invalidation;
- sessions;
- Webform submissions;
- flood/watchdog;
- queues;
- batch/temp/cache state;
- targeted cron/update/announcement/linkchecker state;
- persisted provider/key config;
- PREPROD admin server-owned boundary.

No second Drush emulation/sanitizer or second policy is created.

### 5. Raw cleanup before activation

After sanitization assertions and sanitized SQL export, the worker must prove the derived raw staging DB and account are absent.

```text
helper.cleanup_scope(scope)
-> helper.require_absent(scope)
-> RAW_STAGING_CLEANUP = PROVEN
```

A small fixed idempotent retry is allowed. If cleanup/absence remains unproven:

```text
ACTIVATION = NOT_STARTED
OUTCOME = HUMAN_RECOVERY_REQUIRED
DETAIL = RAW_STAGING_CLEANUP_UNPROVEN
```

This is intentionally not reported as `ROLLED_BACK`, because raw PROD staging may still exist.

### 6. PROD identity/root stage cleanup before activation

The request-scoped root stage contains the transient PROD read identity, pinned PROD trust material and fixed worker/scripts. It must be deleted and its absence proven before the existing activation worker starts.

```text
PROD_IDENTITY_STAGE_CLEANUP = PROVEN
```

If deletion or absence proof fails:

```text
ACTIVATION = NOT_STARTED
OUTCOME = HUMAN_RECOVERY_REQUIRED
DETAIL = PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN
```

Unexpected pre-activation exceptions produce only bounded terminal metadata and do not leave the control plane polling until timeout.

### 7. Sanitized-only activation

Only after both cleanup gates pass is sanitized SQL handed to the existing non-root #914 worker:

`scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`

The preparation worker does not rewrite activation.

The real #953 proof reached:

```text
PREPROD_WORKER_OUTCOME = COMMITTED
PREPROD_WORKER_DETAIL = SANITIZED_DATABASE_ACTIVE_AND_VALIDATED
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_ROUTE = PROD_TO_PREPROD_DIRECT
PROD_WRITE = NONE
PROD_READ_IDENTITY = READ_ONLY_REQUEST_SCOPED_TRANSIENT
SANITIZED_ONLY_ACTIVATION = PASS
HUMAN_RECOVERY_REQUIRED = NO
```

### 8. Backup before destructive replacement

The existing worker acquires the PREPROD deploy lock and creates a protected Drush SQL backup. Before destructive replacement it requires the backup to exist, be non-symlink, non-empty, restrictively permissioned and SHA-256 verifiable.

No destructive replacement is allowed before this boundary.

### 9. Bounded maintenance and standard replacement

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

PREPROD settings, hash salt, DB credentials, Basic Auth/noindex policy and other runtime ownership remain PREPROD-owned.

The refresh must **not** automatically execute `emerging:governed-content --all`. Governed Content remains a separate mechanism.

## Rollback and HUMAN_RECOVERY_REQUIRED

After activation begins, the existing #914 worker owns rollback.

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

The pre-activation cleanup boundary is separate: unproven raw staging cleanup or unproven PROD identity-stage cleanup also returns `HUMAN_RECOVERY_REQUIRED`, with activation not started.

The terminal #953 proof required no human recovery. No `RECOVER_CURRENT`, `RECOVER_ABORT`, GitHub transaction reconstruction, historical target lookup or #915 transaction state machine belongs to the current route.

## Data classification

Preserve for fidelity where relevant:

- nodes/revisions;
- taxonomy/translations;
- Paragraphs/entity-reference revisions;
- menu/link content;
- aliases/redirects;
- public media metadata.

Remove/sanitize before activation:

- imported user/auth/reset/session material;
- Webform submissions/data;
- sessions;
- flood/rate-limit state;
- watchdog/dblog state;
- queues;
- cache/render/discovery, batch and temporary state;
- targeted cron/update/announcement/link-checker state;
- persisted provider/API credentials and production-only state.

## PREPROD side-effect contract

Before maintenance reopens, PREPROD must still satisfy:

- production Config Split OFF;
- preproduction Config Split ON;
- analytics/Google Tag outbound OFF;
- mail sink/null behavior;
- automated Drupal cron OFF;
- external AI/provider egress OFF by default;
- no production webhook/API credential;
- externally acting queues empty/bounded;
- Webform submissions and active sessions absent;
- Drupal bootstrap/runtime validation PASS;
- Basic Auth/noindex preserved.

## Settings and secrets

```text
PROD settings/secrets     = PROD-owned
PREPROD settings/secrets  = PREPROD-owned
DDEV settings/secrets     = local-owned
```

No production credential becomes a PREPROD runtime credential. The transient PROD SSH read identity is not a Drupal/runtime credential and is deleted before activation.

## Files

The current route is database-only.

- private files: excluded;
- public-file synchronization: not implemented;
- Stage File Proxy or another existing standard primitive should be evaluated before custom sync.

Private files are never copied by default.

## Evidence contract

Evidence is metadata only. Safe values include issue/request identity, repository/source release identity, policy identity, non-sensitive hashes/counts and bounded PASS/FAIL/terminal state.

Never expose raw SQL, copied values, email/IP/PII, passwords/hashes, session/reset tokens, DB credentials, API tokens or private files.

## Development Seed relationship

#816 has removed the source-data blocker for #873, but the Development Seed service itself is not yet real.

```text
#873_BLOCKED_BY_816 = NO
REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
SYNTHETIC_PROOF = COMPLETE
DDEV_PROVIDER = .ddev/providers/agency.yaml
LOCAL_UX = ddev pull agency
PULL_ONLY = YES
REAL_PREPROD_SEED_GENERATION = PENDING
REAL_STORAGE_PROVISIONING = PENDING
REAL_DISTRIBUTION = PENDING
```

## Authoritative implementation

- `docs/operations/preproduction-refresh-governed-successor.md`
- `.github/workflows/preprod-914-governed-successor.yml`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-server-to-server-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-server-to-server-worker.py`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`
- `scripts/preproduction-staging-import/privileged/agency-preprod-staging-db`
- `scripts/preproduction-staging-import/privileged/agency-preprod-staging-sanitizer.py`
- `scripts/preproduction-refresh/sanitization-policy.json`

No command in this document grants execution authority. Reload current `main` and fresh execution authority before any real execution. #816 remains closed / completed.
