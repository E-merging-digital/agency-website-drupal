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

PLAN performs metadata/readiness observation only. It may validate the trusted
runner, pinned SSH trust, current PROD release identity, PREPROD Drush commands,
backup path, Config Split, runtime settings/admin reconciliation inputs and lock
presence. It creates no snapshot, staging DB, backup, maintenance state or DB
mutation.

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
- Raw PROD data on GitHub-hosted runners/artifacts/logs: none.
- Raw data execution surface: trusted self-hosted only.
- PREPROD runtime never sees unsanitized PROD data.
- PREPROD settings/secrets remain environment-owned.
- Verified PREPROD backup precedes destructive replacement.
- Rollback must be proven before maintenance reopens.
- Caller-selected generic root execution: none.
- Persistent `DATA_ACTIVATION_AUTHORITY`: disabled.
