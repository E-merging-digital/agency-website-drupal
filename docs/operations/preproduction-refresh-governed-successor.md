# Governed PROD → PREPROD refresh successor (#914)

Status: source/static/synthetic only. No execution authority is granted by #914.
Architecture authority: `AGENTS.md` and accepted `ADR-003-use-existing-first`.

## Decision

`EXTEND_EXISTING`: reuse Drupal/Drush/DDEV/MariaDB/SSH and existing Agency safety
controls. Custom code is limited to Agency-specific sanitization/assertions and a
small PREPROD worker that sequences standard Drush operations.

The former #914 `RECOVER_ABORT`, GitHub recovery records, historical target
reconstruction, comment-id binding and near-zero-downtime #915 transaction path
are not part of this route. #915/#917 remain merged history but are not an
operational dependency.

## Authority

A future fresh issue under #816 may authorize exactly one `PLAN` or `APPLY`.
The implementation/governance issues through #920 cannot execute. Marker schema
v4 contains only authority issue, mode, one-shot request, current main SHA,
PROD release (`AUTO` for PLAN, exact SHA for APPLY), profile, actor and attempt 1.
GitHub stores authorization/audit only; it stores no transaction/recovery state.

## PLAN

PLAN performs metadata/readiness observation only. It runs on GitHub-hosted
`ubuntu-24.04`, which is permitted by the durable #816 boundary because PLAN
never materializes or manipulates raw PROD data. Immediately before any SSH
secret is materialized, the PLAN JIT revalidates live `main`, exact one-shot
authority, checked-out HEAD and `runner.environment == github-hosted`.

After that JIT gate only, transient PROD/PREPROD SSH identities are written under
`RUNNER_TEMP`. The ephemeral runner provisions both `known_hosts` entries with
the existing PROD/PREPROD `manage-known-host.sh PROVISION` primitives. Those
primitives use only repository-pinned ED25519 key material and SHA-256
fingerprints; no network-discovered host key, TOFU, `ssh-keyscan`, `accept-new`
or disabled host-key checking is permitted. PROD trust is then rechecked through
`VERIFY_ONLY`, PREPROD trust through the existing strict
`verify-preprod-pinned-trust.sh`, and all observations use
`StrictHostKeyChecking=yes` with the explicit ephemeral `known_hosts` file.

PLAN may then observe current PROD release/promotion receipt metadata and PREPROD
readiness (Drush command availability, backup path, Config Split, runtime/admin
inputs and lock presence). The PROD observer intentionally performs no Drush/DB
command. PLAN creates no snapshot, transfers no PROD data, and performs no PROD
write, PREPROD database mutation, runtime mutation, backup, maintenance or
activation. Transient SSH identities are deleted in the workflow cleanup step.

### Diagnostics de readiness bornés

A failed PLAN reports only this fixed metadata contract:

```text
PLAN_RESULT=FAIL_CLOSED
PLAN_STAGE=<bounded enum>
PLAN_REASON=<bounded enum>
```

The local PLAN process suppresses trust/SSH stderr, captures remote stdout/stderr
only in `0600` transient files under the ephemeral runner, limits observer stdout
to 4096 bytes, deletes those files, and never forwards their raw contents. A
remote readiness refusal is accepted only when its output is exactly the fixed
two-line `PLAN_OBSERVER_RESULT=FAIL_CLOSED` + allowlisted `PLAN_REASON` contract.
Unknown, additional or malformed remote output is reduced to
`OBSERVER_OUTPUT_INVALID`; an SSH/observer failure with no valid bounded marker is
reduced to `PROD_SSH_OBSERVER` or `PREPROD_SSH_OBSERVER`.

Current bounded reasons are:

```text
PLAN_CONTEXT_INVALID
PLAN_REPOSITORY_IDENTITY
UNEXPECTED_LOCAL_FAILURE
PROD_PINNED_TRUST
PREPROD_PINNED_TRUST
PROD_SSH_OBSERVER
PREPROD_SSH_OBSERVER
PROD_OBSERVER_CONTEXT
PROD_CURRENT_RELEASE
PROD_PROMOTION_RECEIPT
PREPROD_OBSERVER_CONTEXT
PREPROD_CURRENT_RELEASE
PREPROD_DRUSH_EXECUTABLE
PREPROD_BACKUP_PATH
PREPROD_RUNTIME_ENV
PREPROD_RUNTIME_VALIDATOR
PREPROD_ADMIN_RECONCILER
PREPROD_CONFIG_SPLIT
PREPROD_DRUSH_COMMAND_SET
OBSERVER_OUTPUT_INVALID
```

These diagnostics classify existing predicates; they do not remove or weaken any
readiness requirement. In particular,
`scripts/preproduction-refresh/activation-capability/admin-reconcile.php` remains
required because the current APPLY worker consumes it after sanitized DB import
to reconcile the PREPROD administrator from server-owned state. A missing file is
therefore a legitimate `PREPROD_ADMIN_RECONCILER` readiness failure, not authority
for the data-refresh route to deploy application code.

The success contract remains unchanged and still emits only the observed PROD
release SHA plus the existing mutation/data-boundary PASS metadata.

This #927 change does not broaden a runner class for these SSH secrets: the same
PROD and PREPROD SSH secret classes are already consumed by existing
GitHub-hosted Agency workflows. That precedent is not authority to broaden any
other secret or workflow.

## APPLY

1. JIT revalidate live main and exact authority before SSH secrets exist.
2. On `agency-browser-runner-01`, prepare the exact PROD release DDEV project.
3. Before importing raw data, disconnect its web+db containers from every normal
   Docker network and attach them only to a fresh `docker network --internal`.
4. Use the reviewed read-only PROD `drush sql:dump` primitive. Raw SQL exists only
   under trusted `RUNNER_TEMP` and inside the isolated DDEV database.
5. Import raw SQL only after isolation, then run Drush 13.7.6 `sql:sanitize`.
   Drush owns generic user email/password and session sanitization.
6. Run `agency-sanitize.php` only for Agency gaps: usernames, Webform, flood,
   watchdog, queues, caches/temp/state and persisted provider/key configuration.
   Fail-closed assertions prove the required classes are sanitized.
7. Export a sanitized SQL dump, delete the raw dump, and transfer only the
   sanitized SQL to PREPROD over pinned SSH.
8. Start `remote-apply-worker.sh` detached with `nohup setsid --wait` as the
   non-root `agency-preprod` user. No root/provisioning/recovery secret exists.
9. The worker acquires the existing PREPROD deploy lock and creates a non-empty,
   SHA-256 verified Drush backup before any destructive replacement.
10. It enables bounded maintenance, uses `sql:drop` + `sql:cli`, then `updb`,
    `cim`, PREPROD Config Split, server-owned PREPROD admin reconciliation,
    runtime validation, `cr`, and disables maintenance. Success is `COMMITTED`.
11. Any failure after destructive replacement restores the exact backup through
    `sql:drop` + `sql:cli`, validates runtime, and only then disables maintenance:
    `ROLLED_BACK`. If restore/validation is unprovable, maintenance stays ON and
    the terminal result is `HUMAN_RECOVERY_REQUIRED`.

APPLY remains strictly assigned to `[self-hosted, linux, x64, agency]`. Its raw
staging, isolation, sanitization and sanitized-only transfer architecture is
unchanged by #927. Raw PROD data on GitHub-hosted infrastructure remains
forbidden and impossible on the PLAN path.

## Hard runner loss

Before detached worker launch there is no PREPROD runtime mutation. After launch,
the worker is independent of the runner/SSH session and owns backup, maintenance,
replacement and rollback. GitHub does not reconstruct old transactions and no
`RECOVER_CURRENT` or historical lookup exists.

## Raw staging isolation

The safety order is strict: **ISOLATE → IMPORT RAW → BOOTSTRAP/SANITIZE**.
The DDEV web and DB containers are attached exclusively to an internal Docker
network before raw import. Therefore mail, webhook, analytics, provider/API,
cron/queue and arbitrary third-party network egress cannot leave the staging
network while production data is present. Existing PREPROD settings additionally
keep mail, analytics, cron and AI egress disabled after sanitized activation.

## Files

Public files are out of scope. Stage File Proxy is the preferred future standard
solution to evaluate/use rather than custom synchronization. Private files remain
excluded.

## Non-negotiable invariants

- PROD write: none.
- PLAN PROD DB content read: none.
- PLAN PROD snapshot: not performed.
- PLAN PROD data transfer: none.
- PLAN PREPROD DB/runtime mutation: none.
- Raw PROD data on GitHub-hosted runners/artifacts/logs: none.
- Raw data execution surface: trusted self-hosted only.
- APPLY runner: `self-hosted`, `linux`, `x64`, `agency` only.
- PREPROD runtime never sees unsanitized PROD data.
- PREPROD settings/secrets remain environment-owned.
- Verified PREPROD backup precedes destructive replacement.
- Rollback must be proven before maintenance reopens.
- Caller-selected generic root execution: none.
- Persistent `DATA_ACTIVATION_AUTHORITY`: disabled.
