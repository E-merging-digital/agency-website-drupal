# PREPROD refresh atomic backup / activation / rollback boundary

Issue: #868. Parent: #816. Predecessor: #866.

This document defines the bounded data-activation architecture after #866 proved a real read-only PROD snapshot can be imported, deterministically sanitized and cleaned without touching the PREPROD runtime database.

It is **not** runtime authority. The #868 delivery tranche performs no real PROD read/transfer, no real PREPROD backup, no real PREPROD DB mutation, no real activation and no real rollback.

## Current PREPROD runtime DB model

The application release is selected by `/var/www/agency-preprod/current`. The Drupal data boundary is deliberately stable and server-owned:

```text
runtime database = agency_preprod
runtime user     = agency_preprod
settings         = /var/www/agency-preprod/shared/settings/settings.php
runtime secrets  = /var/www/agency-preprod/shared/settings/runtime.env
```

The shared settings own the PREPROD database credential and hash salt and force the PREPROD environment policy. The application `current` symlink selects code only; there is no database selector.

Therefore a data refresh:

- does **not** use a database-name switch;
- does not change the application release symlink;
- does not replace PREPROD DB credentials or hash salt;
- does not copy PROD server settings/credentials into PREPROD;
- preserves Basic Auth, trusted-host policy and noindex as PREPROD host/server state.

## Why #866 cannot be activated

The installed #859/#861 helper intentionally implements one-shot `IMPORT_SANITIZE_PROVE -> cleanup -> verify absence`. Its derived staging DB/account are absent after success. Reusing it as a persistent activation candidate would weaken a pinned capability and is forbidden.

## Sanitized candidate lifecycle

A future separately provisioned fixed capability creates a request-derived isolated candidate such as `agency_preprod_candidate_<12 hex>` and performs:

```text
fresh PROD read-only snapshot
→ import isolated
→ deterministic sanitization
→ PREPROD side-effect hardening
→ candidate assertions
→ remove ephemeral candidate account
→ retain only sanitized + hardened + sealed candidate root-only
```

Unsanitized candidate persistence outside the bounded prepare transaction is forbidden. The public PREPROD runtime and normal `agency_preprod` DB user cannot reach the candidate before activation.

## Side-effect hardening gate

Before a candidate may be sealed, fail closed unless all required assertions pass:

- production Config Split OFF;
- PREPRODUCTION Config Split ON;
- GA4/Google Tag outbound OFF;
- mail sink/null behavior;
- automated cron OFF;
- OpenAI/provider egress OFF;
- production webhook/API credentials absent;
- Basic Auth preserved;
- noindex preserved;
- Webform submissions = 0;
- active sessions = 0;
- external-action queues cleared or explicitly bounded.

The pinned sanitizer owns data-state removal. `scripts/preproduction/settings.php.template` and `scripts/preproduction/validate-runtime.sh` own the effective PREPROD runtime overrides. The host/Nginx boundary owns Basic Auth/noindex. #868 does not weaken any layer.

## Locking and mandatory HTTP fence

The full future refresh transaction holds the existing PREPROD deployment lock `/var/www/agency-preprod/shared/deploy.lock`, preventing concurrent code deployment. A separate fixed root-owned refresh lock such as `/run/lock/agency-preprod-refresh.lock` rejects concurrent refresh/activation.

A DB-table swap is atomic, but **post-swap Drupal convergence is not instantaneous**. Therefore the runtime must be externally fenced before the backup/activation boundary so that an unvalidated refreshed DB is never publicly or asynchronously exercised.

The future host capability must provide a server-owned maintenance marker, proposed as `/var/www/agency-preprod/shared/refresh-maintenance.flag`, enforced by Nginx/runtime policy. The sequence is:

```text
acquire both locks
→ prove sealed candidate and release identity
→ close server HTTP/runtime fence
→ drain existing runtime DB sessions
→ re-check application release identity
→ create and verify exact PREPROD backup
→ activate candidate atomically
→ Drupal convergence and validation while fence remains closed
→ commit OR exact rollback
→ open fence only after terminal PASS / verified restore
```

The current host does not yet contain this #868 fence capability. It is part of the required separate privileged/host provisioning dependency; #868 does not provision it.

## Backup model

The exact current PREPROD data boundary is captured **after the fence is closed and runtime DB sessions have drained**, and before any runtime DB mutation. The future fixed helper uses an internally fixed logical dump command. Raw backup bytes stay server-local with restrictive `0600` permissions and never enter GitHub/log/user evidence.

Required metadata-only proof includes:

```text
backup identity hash
byte size > 0
SHA-256
safe table count > 0
previous runtime state SHA-256
previous runtime DB identity hash
application release SHA
```

The active transaction backup cannot be pruned before terminal success/rollback proof. Retention after successful transactions is explicitly bounded (target: ten backups, consistent with existing PREPROD deployment retention).

## Activation model: atomic base-table swap, not database rename

MariaDB has no `RENAME DATABASE` statement. MariaDB 11.8 documents multi-table `RENAME TABLE` as atomic, including rollback of all renames when one rename in the statement fails. Moving tables between databases has limitations; triggers cannot be moved across schemas and views cannot be moved. Consequently the future capability accepts only a deliberately narrow subset and fails closed otherwise.

Before activation require:

```text
MariaDB tested family = 11.8
runtime/candidate objects = BASE TABLE only
views = 0
triggers = 0
events = 0
routines = 0
foreign-key constraints = 0
runtime/candidate table sets compatible = YES
candidate sanitization/hardening = PASS
verified backup = PASS
HTTP/runtime fence = CLOSED
application release identity = unchanged
```

No caller supplies table/database identifiers. The fixed helper discovers and validates the table set from metadata, rejects unsafe/unsupported objects, and emits aggregate metadata only.

The swap uses one internally generated multi-table `RENAME TABLE` statement moving the old runtime base tables into a request-derived rollback schema and the sealed candidate base tables into the fixed `agency_preprod` runtime schema. The runtime DB name, shared settings and credentials remain unchanged.

If the actual PREPROD schema does not satisfy this subset, a future mutation-free PLAN must stop; #868 does not widen the mechanism to force activation.

## Config / updb / cache model

The current shared settings have no safe alternate candidate DB selector. Introducing generic DB credential/settings overrides merely to bootstrap Drupal against the candidate would create an unnecessary privilege/configuration surface.

Therefore pre-activation proof is data/server-contract based. After the atomic table swap, but **while the HTTP/runtime fence remains closed**, the immutable current PREPROD release runs as the normal non-root application user:

```text
1. drush updb
2. canonical config import (cim)
3. PREPROD Config Split convergence
4. restore/recreate PREPROD-only admin route from server-owned PREPROD state
5. Governed Content validation
6. Governed Content dry-run
7. cache rebuild (cr)
8. side-effect validation
9. runtime health validation
```

The shared settings continue to force production split OFF and PREPROD split ON during/after config import. Mail/provider/webhook egress remains fail-closed by the sanitizer + server-owned settings/environment + final runtime assertions.

## Governed Content decision

The sanitized PROD snapshot is the editorial fidelity baseline. Data refresh does **not** authorize `emerging:governed-content --all` or another governed-content apply. Only validation and dry-run run in #868’s future transaction. An apply requires separate release/content-governance authority because it could overwrite refreshed PROD editorial fidelity.

## Rollback model

Rollback never reads PROD and a fresh PROD re-import is not rollback.

Two failure phases are distinguished:

1. **Before Drupal convergence starts:** if the table swap completed but no schema/config convergence has changed the refreshed runtime, a reverse atomic multi-table rename may immediately restore the exact previous table set.
2. **Once `updb`/config convergence has started:** table names/schema may change. Reverse rename alone is no longer a complete recovery contract. Rollback must restore `agency_preprod` from the exact verified pre-activation backup while the HTTP/runtime fence remains closed.

After either route, the future capability recomputes the restored runtime state digest and requires exact equality with the recorded pre-activation runtime state digest; application release identity must also remain unchanged. Only then may the fence reopen.

If rollback verification fails, retain backup/recovery metadata, keep the fence closed and stop for separately governed human recovery.

## Partial-failure model

- Candidate sanitization/hardening failure: destroy unsealed candidate; runtime untouched.
- Before backup: runtime untouched.
- Backup creation/verification failure: abort before runtime mutation; prove unchanged runtime; fence may reopen.
- Atomic `RENAME TABLE` failure: MariaDB atomic statement must leave previous runtime table set unchanged; prove unchanged state before reopening fence.
- Failure after swap but before Drupal convergence: reverse rename or exact backup restore.
- Failure during/after `updb`, `cim`, split, admin restore, governed validation, cache rebuild or health validation: exact backup restore.
- Rollback verification failure: fence stays closed; backup/recovery identity retained; terminal human recovery.
- Raw transient/candidate cleanup runs on every safe terminal path; active recovery material is never destroyed early.

## Application/server-state preservation

At entry record the resolved `/var/www/agency-preprod/current` release identity and re-read it before activation, after activation, after validation and after rollback if applicable. Any drift fails closed. #868 never changes that symlink.

PREPROD shared settings, runtime.env, DB credentials, hash salt, trusted hosts, Basic Auth and noindex stay PREPROD-owned. PROD server state is never copied over them. Public/private files are out of scope.

## Privileged capability dependency

The existing installed staging helper remains unchanged. A future real transaction requires a new fixed root-owned pinned activation capability plus the host HTTP-fence integration. Conceptual fixed actions are bounded to candidate preparation, backup+activation+convergence+validation, recorded rollback, cleanup and absence verification.

Forbidden permanently:

- generic MariaDB/shell/Python/env sudo;
- `NOPASSWD: ALL`;
- mutable repository checkout executed as root;
- caller SQL/database/table/path/executable inputs;
- mutable runtime settings DB switch.

`NEW_PRIVILEGED_CAPABILITY_REQUIRED = YES`

`SEPARATE_PROVISIONING_DEPENDENCY = REQUIRED`

## Evidence and delivery stop

GitHub evidence is metadata-only: request/release/candidate/backup identities, counts, digests and PASS/FAIL. Raw PROD SQL, raw PREPROD backup, DB values, PII, credentials, tokens and private files are forbidden.

This #868 tranche performs static/synthetic/ephemeral MariaDB proof only:

```text
REAL_PROD_DATA_READ       = NONE
REAL_PROD_DATA_TRANSFER   = NONE
REAL_PREPROD_MUTATION     = NONE
REAL_PREPROD_BACKUP       = NOT_PERFORMED
REAL_ACTIVATION           = NOT_PERFORMED
REAL_ROLLBACK             = NOT_PERFORMED
MERGE                     = NOT_PERFORMED
```

Delivery stops before merge for Project Lead exact-head review. After merge, Project Lead must first decide/provision the missing bounded privileged + HTTP-fence capability before any real PLAN/APPLY. A real backup/activation/rollback remains a separate explicit human boundary.
