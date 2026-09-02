# Governed PROD → PREPROD refresh successor (#914)

Status: `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `REAL_EXECUTION_PROVEN`; real PLAN proven; real APPLY proven `COMMITTED` by #953.
Architecture authority: `AGENTS.md` and accepted `ADR-003-use-existing-first.md`.
Parent implementation program: #816 (**CLOSED / COMPLETED**).

## Decision

`EXTEND_EXISTING`.

The refresh reuses existing Drupal/Drush/MariaDB/SSH and Agency primitives. No generic orchestration framework, transaction registry, recovery framework, new sanitization policy or new issue-comment listener is part of the current route. #915/#917 remain historical lineage only.

The current controlled server-to-server route is the proven APPLY path. The trusted self-hosted route remains an authorized alternative under the existing sanitization policy; live runner availability is dynamic and must be reloaded rather than frozen in this document.

## Authority

#816 is closed and is not reopened for routine execution. Every real `PLAN` or `APPLY` still requires a fresh one-shot authority accepted by the current validator. #914 does not grant persistent execution authority. The current validator binds mode, request ID, live `main`, exact PROD release for APPLY, profile, actor and `run_attempt = 1`.

```text
PLAN != APPLY
CONSUMED / NEVER REUSE
DATA_ACTIVATION_AUTHORITY = DISABLED
```

GitHub stores authorization/audit metadata only; it stores no data-refresh transaction state.

## Current PLAN

PLAN runs on GitHub-hosted `ubuntu-24.04`. JIT revalidates live `main`, exact checkout, one-shot authority and `runner.environment == github-hosted` before SSH identities are materialized.

PLAN is metadata/readiness-only. It performs no:

- PROD DB content read;
- PROD snapshot;
- PROD data transfer;
- PROD write;
- PREPROD DB/runtime mutation;
- backup, maintenance or activation.

The current real PLAN contract has terminal proof:

```text
PLAN_RESULT = PASS
PROD_DB_CONTENT_READ = NONE
PROD_SNAPSHOT = NOT_PERFORMED
PROD_DATA_TRANSFER = NONE
PROD_WRITE = NONE
PREPROD_DB_MUTATION = NONE
DATA_ACTIVATION_AUTHORITY = DISABLED
APPLY = SKIPPED
```

Future commands must always reload current live state rather than copy a stale SHA from this document.

## Current controlled APPLY

Current APPLY execution path:

```text
GitHub-hosted ubuntu-24.04 control plane
-> exact-main + fresh authority + JIT-before-secret
-> fixed scripts / fixed identities / metadata only to PREPROD
-> detached request-scoped PREPROD root preparation worker
-> direct pinned read-only SSH PREPROD -> PROD
-> existing production-readonly-snapshot/remote-stream.sh
-> raw stream directly into derived PREPROD staging DB
-> existing root-owned Agency sanitizer + existing single policy
-> sanitization assertions
-> sanitized SQL export only
-> prove raw staging DB/account absent
-> remove request-scoped PROD identity/trust root stage
-> prove root stage absent
-> existing #914 remote-apply-worker.sh as agency-preprod
-> backup / maintenance / activation / rollback
-> validation
-> COMMITTED or fail-closed terminal outcome
```

GitHub-hosted is **control plane only**. It never receives raw PROD bytes.

```text
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_GITHUB_ARTIFACT = NONE
RAW_PROD_GITHUB_TEMP = NONE
RAW_PROD_IN_GITHUB_LOGS = NONE
RAW_PROD_ROUTE = PROD -> PREPROD DIRECT
PROD_WRITE = NONE
PRIVATE_FILES = NONE
```

The registered trusted self-hosted Agency runner (`self-hosted`, `linux`, `x64`, `agency`) remains an authorized raw-data alternative under the single policy. The current controlled route does not create a permanent multi-provider execution framework.

## Terminal real proof

#953 is the terminal real proof for the current recipe:

```text
PROD_TO_PREPROD_REFRESH = REAL_EXECUTION_PROVEN
PREPROD_WORKER_OUTCOME = COMMITTED
PREPROD_WORKER_DETAIL = SANITIZED_DATABASE_ACTIVE_AND_VALIDATED
PROD_ACCESS = READ_ONLY_REQUEST_SCOPED_TRANSIENT
PROD_WRITE = NONE
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_ROUTE = PROD_TO_PREPROD_DIRECT
SANITIZED_ONLY_ACTIVATION = PASS
HUMAN_RECOVERY_REQUIRED = NO
```

The #953 request is consumed / never reusable. The recipe itself does not depend on its historical run number or frozen SHA.

## Read-only PROD identity

The PREPROD-side PROD SSH identity used by APPLY is:

```text
REQUEST_SCOPED = YES
TRANSIENT = YES
ROOT_ONLY = YES
MODE = 0600
PINNED_TRUST = YES
PROD_ACCESS = EXISTING READ_ONLY SNAPSHOT PATH ONLY
GENERIC PROD SHELL AUTHORITY = NONE
```

The worker uses the existing pinned PROD host key/fingerprint and the existing `scripts/production-readonly-snapshot/remote-stream.sh`. That remote primitive binds the exact promoted PROD release and executes only fixed Drush `sql:dump` against PROD server-owned settings.

## Raw staging and sanitization

Raw data is never imported into the PREPROD live Drupal database. The existing bounded root-owned staging helper derives a request-specific MariaDB database/account namespace that cannot target `agency_preprod`.

The server-side sanitizer and `scripts/preproduction-refresh/sanitization-policy.json` remain the single sanitization implementation/policy for this route. They cover the #914 semantic outcome:

- deterministic imported usernames;
- anonymized mail/init and invalidated auth/password/access/login state;
- sessions removed;
- Webform submissions removed;
- flood/watchdog removed;
- queues removed;
- batch/temp/cache state removed;
- targeted cron/update/announcement/linkchecker state removed;
- persisted provider/key config removed;
- PREPROD admin remains server-owned.

No second Drush emulation or sanitization policy is introduced.

## Mandatory pre-activation cleanup gates

Activation is impossible until both cleanup proofs pass.

First, after sanitized SQL is exported:

```text
helper.cleanup_scope(scope)
-> helper.require_absent(scope)
-> RAW_STAGING_CLEANUP = PROVEN
```

The cleanup is retried only a small fixed number of times. If the derived DB/account cannot be proven absent:

```text
ACTIVATION = NOT_STARTED
OUTCOME = HUMAN_RECOVERY_REQUIRED
DETAIL = RAW_STAGING_CLEANUP_UNPROVEN
```

Second, the request-scoped root stage containing `prod-read.key`, pinned PROD trust material and fixed scripts is removed and absence is independently checked before activation:

```text
PROD_IDENTITY_STAGE_CLEANUP = PROVEN
```

If that cleanup/absence proof fails:

```text
ACTIVATION = NOT_STARTED
OUTCOME = HUMAN_RECOVERY_REQUIRED
DETAIL = PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN
```

Only after both gates pass may the root preparation worker `exec` the existing non-root #914 activation worker. Unexpected pre-activation exceptions are reduced to bounded terminal metadata rather than leaving the control plane polling until timeout.

## Existing activation and rollback

`scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh` remains authoritative and unchanged in responsibility.

It:

1. validates sanitized SQL identity/mode;
2. acquires the existing PREPROD deploy lock;
3. creates and verifies an exact protected Drush backup before destructive replacement;
4. enables maintenance;
5. executes `sql:drop` + `sql:cli`;
6. runs `updb`, `cim`, PREPROD Config Split, server-owned admin reconciliation, `cr` and runtime validation;
7. disables maintenance only after successful validation;
8. returns `COMMITTED`.

Failure after destructive replacement restores the exact backup and validates it. Proven restore returns `ROLLED_BACK`; unprovable restore returns `HUMAN_RECOVERY_REQUIRED` and maintenance stays ON.

## Hard runner/control-plane loss

Before the detached PREPROD preparation worker starts, there is no raw PROD stream and no PREPROD runtime mutation. After launch, PREPROD owns the preparation, cleanup gates and handoff to the existing activation worker independently of the GitHub-hosted session.

No GitHub transaction reconstruction, `RECOVER_CURRENT`, `RECOVER_ABORT` or historical target lookup exists.

## Files

Public files are out of scope for the current DB refresh. Stage File Proxy remains the preferred standard primitive to evaluate if public-file fidelity becomes necessary. Private files remain excluded.

## Non-negotiable invariants

- PLAN = GitHub-hosted / real execution proven.
- APPLY = `CONTROLLED_SERVER_TO_SERVER` / real execution proven.
- Trusted self-hosted runner = authorized alternative.
- Raw PROD on GitHub-hosted = none.
- PROD write = none.
- PREPROD live runtime raw access = forbidden.
- Single existing sanitization policy = preserved.
- Raw staging cleanup proven before activation.
- PROD identity/root stage cleanup proven before activation.
- Any unproven pre-activation cleanup = `HUMAN_RECOVERY_REQUIRED`; activation not started.
- Existing `remote-apply-worker.sh` = reused.
- Verified PREPROD backup precedes destructive replacement.
- PREPROD settings/secrets remain environment-owned.
- Persistent `DATA_ACTIVATION_AUTHORITY` = disabled.
- New framework = none.
