# PREPROD refresh atomic backup / activation / rollback boundary

Issue: #868. Parent: #816. Predecessor: #866.

This document defines the bounded data-activation architecture after #866 proved a real read-only PROD snapshot can be imported, deterministically sanitized and cleaned without touching the PREPROD runtime database.

It is **not** runtime authority. The #868 delivery tranche performs no real PROD read/transfer, no real PREPROD backup, no real PREPROD DB mutation, no real activation and no real rollback.

## Current PREPROD runtime model

The current PREPROD application release is selected by:

```text
/var/www/agency-preprod/current -> /var/www/agency-preprod/releases/<release>
```

The Drupal database identity is deliberately stable and server-owned:

```text
runtime database = agency_preprod
runtime user     = agency_preprod
settings         = /var/www/agency-preprod/shared/settings/settings.php
runtime secrets  = /var/www/agency-preprod/shared/settings/runtime.env
```

The shared PREPROD settings own the database credential and hash salt. They also force the PREPROD environment policy. A data refresh must not modify the `current` release symlink, replace the PREPROD hash salt, copy a PROD credential into PREPROD, or rewrite the shared runtime settings to point at a candidate database.

Therefore #868 does **not** use a database-name switch.

## Why the #866 staging database cannot be activated

The installed #859/#861 helper intentionally implements a one-shot lifecycle:

```text
IMPORT_SANITIZE_PROVE
-> sanitization/assertions
-> cleanup
-> verify absence
```

The derived staging DB and its account are absent after the helper returns. Reusing that helper as a persistent activation candidate would violate its pinned contract. #868 must not weaken or mutate that helper.

## Sanitized candidate lifecycle

A future separately provisioned fixed capability must create a new candidate namespace derived only from the exact #868 request identity:

```text
agency_preprod_candidate_<12 hex>
```

The candidate lifecycle is:

```text
fresh PROD read-only snapshot
-> import into isolated candidate
-> deterministic sanitization
-> side-effect hardening
-> candidate assertions
-> drop the ephemeral candidate account
-> retain candidate DB root-only
-> verified current PREPROD backup
-> atomic table-set activation
-> post-activation runtime validation
-> commit cleanup OR exact rollback
```

The public PREPROD runtime never points at the candidate DB name. Before activation the normal `agency_preprod` application account has no authority on the candidate schema.

## Side-effect hardening before runtime

The hardening gate is deliberately layered.

### Candidate data-state layer

The pinned sanitizer/candidate assertion capability must prove before activation:

- Webform submissions = 0;
- active sessions = 0;
- imported flood/rate-limit state removed;
- imported dblog/watchdog state removed;
- cache/batch/temp state cleared;
- external-action queues cleared or explicitly bounded;
- imported one-time authentication state invalidated;
- production credential/provider configuration removed;
- imported production runtime state removed.

### PREPROD server-owned settings layer

The already deployed PREPROD settings remain authoritative for effective runtime behavior and must be proven unchanged before activation:

- production Config Split OFF;
- preproduction Config Split ON;
- GA4 / Google Tag outbound OFF;
- email sink/null policy;
- automated cron OFF;
- OpenAI/provider egress OFF;
- no production provider credential injected;
- PREPROD hash salt preserved;
- PREPROD database credential preserved.

### Host layer

The activation transaction does not modify Nginx/PHP/TLS state. Basic Auth and `X-Robots-Tag: noindex, nofollow, noarchive` remain host-owned PREPROD controls and must still pass post-activation validation.

## Backup model

Before any table activation the future fixed helper must create a backup of the exact current `agency_preprod` boundary using an internally fixed MariaDB dump command. The raw backup is server-local, restrictive and never emitted to GitHub or user-visible evidence.

The backup is accepted only after internal proof of:

```text
backup byte size > 0
backup sha256 = 64 hex
safe table count > 0
previous runtime DB identity = agency_preprod
application release identity = unchanged exact current release
```

GitHub may receive only metadata such as a hashed backup identity, byte size, SHA-256 and safe table count. No SQL bytes, values, PII or secrets are evidence.

The exact previous boundary is represented twice until the transaction commits:

1. the verified raw backup; and
2. the old runtime table set moved atomically into a request-derived rollback schema during activation.

## Activation model

MariaDB has no `RENAME DATABASE` statement. #868 therefore uses a fixed runtime database name and an atomic **multi-table `RENAME TABLE`** statement. MariaDB 11.8 documents multi-table `RENAME TABLE` as atomic for supported engines, but moving tables across databases has limitations for triggers and views. The future helper must fail closed unless the runtime and candidate schemas satisfy the exact supported subset.

Mandatory preflight before activation:

```text
MariaDB family/version = accepted tested family (PREPROD currently 11.8)
runtime objects         = BASE TABLE only
candidate objects       = BASE TABLE only
views                    = 0
triggers                 = 0
events                   = 0
foreign-key constraints  = 0
runtime/candidate table sets compatible = YES
verified backup          = PASS
candidate sanitization   = PASS
candidate hardening      = PASS
application release      = unchanged
```

If any prerequisite is false, activation does not start.

The helper creates an empty request-derived rollback schema and performs one generated, internally validated multi-table statement equivalent to:

```text
RENAME TABLE
  agency_preprod.t1 TO agency_preprod_rollback_<id>.t1,
  agency_preprod_candidate_<id>.t1 TO agency_preprod.t1,
  agency_preprod.t2 TO agency_preprod_rollback_<id>.t2,
  agency_preprod_candidate_<id>.t2 TO agency_preprod.t2,
  ...;
```

No caller supplies table identifiers. The helper discovers and validates them from `information_schema`, rejects unsafe identifiers and emits only aggregate metadata.

Because the runtime database name stays `agency_preprod`, shared settings, DB credentials and PREPROD hash salt do not change.

## Rollback model

A post-activation validation failure requires rollback before cleanup. Rollback is the inverse atomic table-set rename:

```text
current refreshed runtime tables -> failed-candidate schema
exact previous rollback tables    -> agency_preprod
```

The helper must then internally recompute the previous-boundary identity and require it to match the pre-activation recorded identity. Creating a new empty or freshly installed Drupal database is not rollback.

Only after exact rollback proof may failed candidate/rollback transient schemas be removed. The verified raw backup remains the durable recovery boundary according to retention policy.

If rollback itself fails, the helper fails closed, retains the verified backup and lock/recovery metadata, and requires a separately governed recovery action. It must not improvise a fresh database.

## Partial-failure model

- Failure before backup verification: candidate cleanup; runtime untouched.
- Failure after backup but before `RENAME TABLE`: candidate cleanup; runtime untouched; backup retained.
- Failure inside multi-table rename: MariaDB atomic DDL is required to leave the original table set unchanged; synthetic regression proves the failure path.
- Failure after successful activation but before terminal validation: reverse atomic rename to the exact previous table set.
- Failure during rollback: fail closed with backup and recovery identity retained; no destructive cleanup of the evidence boundary.

## Locking model

The whole refresh transaction must hold the existing PREPROD deployment lock:

```text
/var/www/agency-preprod/shared/deploy.lock
```

This prevents a release deployment from changing application code while a data boundary is being prepared/activated/validated.

The future privileged helper must additionally hold a fixed root-owned refresh lock, for example:

```text
/run/lock/agency-preprod-refresh.lock
```

The outer deploy lock must remain held through post-activation health/side-effect validation and through rollback or commit cleanup.

## Application release preservation

At entry, record the exact resolved `/var/www/agency-preprod/current` release path and repository SHA identity. Re-read it before activation, after activation, after validation and after rollback if applicable. Any change fails closed.

#868 is data-only. It never changes the release symlink, builds an application artifact, runs a production promotion, or applies Governed Content automatically.

## New privileged capability dependency

A real activation cannot be built safely from the currently installed #859/#861 helper without changing its pinned semantics. A **new fixed root-owned, pinned refresh activation capability is therefore required**.

The required future bounded actions are conceptually:

```text
IMPORT_SANITIZE_HARDEN_RETAIN
BACKUP_AND_ACTIVATE
ROLLBACK
COMMIT_CLEANUP
VERIFY_ABSENCE
```

This #868 tranche deliberately does not install or authorize those actions. Provisioning must be a separate Project Lead-authorized tranche with exact helper/sanitizer/policy digests, root ownership/modes, fixed sudoers command forms and dedicated least-privilege regressions.

Forbidden permanently:

- generic `sudo mariadb`;
- generic shell/Python/env sudo;
- `NOPASSWD: ALL`;
- executing a mutable repository checkout as root;
- caller-supplied database/table identifiers;
- a mutable runtime settings DB switch.

## Synthetic proof scope

The #868 regression workflow uses only ephemeral synthetic MariaDB data. It proves:

- candidate is a distinct schema;
- backup is mandatory before activation;
- a forced multi-table rename failure leaves the previous runtime table set unchanged;
- a successful atomic table-set swap makes only the proven candidate active;
- reverse rename restores the exact previous synthetic identity;
- application release identity is modeled as immutable;
- evidence is metadata-only;
- no PROD host, PREPROD host, credentials or real data are accessed.

## Delivery stop boundary

#868 Delivery stops before merge.

```text
REAL_PROD_DATA_READ       = NONE
REAL_PROD_DATA_TRANSFER   = NONE
REAL_PREPROD_MUTATION     = NONE
REAL_BACKUP               = NOT_PERFORMED
REAL_ACTIVATION           = NOT_PERFORMED
REAL_ROLLBACK             = NOT_PERFORMED
MERGE                     = NOT_PERFORMED
```

After Project Lead exact-head review and merge, only a mutation-free real PLAN may be considered. Real backup/activation/rollback remains a separate explicit human GO and additionally depends on the fixed privileged capability being provisioned and proven first.
