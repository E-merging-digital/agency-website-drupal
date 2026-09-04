# Governed read-only PROD database snapshot proof

Issue: #826. Parent program: #816.

This tranche implements only the governed route and static/synthetic proof for a future read-only production database snapshot. It does not authorize or execute the first real PROD snapshot.

## Human and authority boundary

`FIRST REAL PROD SNAPSHOT = NOT_AUTHORIZED` until Project Lead reviews the exact PR HEAD and the owner subsequently creates one fresh #826 authority comment.

Issue creation, PR creation, PR merge, PLAN success, an old production GO, an old comment, and a workflow rerun are not snapshot authority.

After Project Lead review, the future one-shot owner command has exactly this shape:

```text
/agency-prod-readonly-snapshot prove <request_id> <live_main_sha> <expected_prod_release_sha> agency-prod-readonly-snapshot-v1
```

The GitHub-hosted gateway accepts only issue #826, actor/comment author `E-merging-digital`, an open owner-created issue, `run_attempt=1`, a fresh request ID appearing in exactly one authority comment, exact live `main`, a 40-character expected PROD release SHA, and the fixed repository-owned profile. The authority binds the request ID, authority comment ID, workflow run ID, live main SHA, expected current PROD release SHA, profile ID and profile SHA-256.

## Execution boundary

The inherited #816 rule remains absolute:

`RAW PROD DATA ON GITHUB-HOSTED RUNNER = FORBIDDEN`.

GitHub-hosted infrastructure is authority/metadata/policy validation only. The raw-data job requires all labels:

- `self-hosted`;
- `linux`;
- `x64`;
- `agency`.

The trusted runner is not a generic remote executor. The owner cannot supply a shell command, SQL query, table name, database name, file path, SSH option, Drush argument or dump option. Only request/release identities matching strict schemas reach the trusted job.

The PROD SSH host trust must already be provisioned in `~/.ssh/known_hosts` on the trusted Agency runner. The route uses `StrictHostKeyChecking=yes` and fails closed when the configured PROD host is not already trusted. The snapshot workflow never bootstraps host trust with `ssh-keyscan`.

## Fixed read-only snapshot operation

The reviewed operation profile is `scripts/production-readonly-snapshot/profile.json` with ID `agency-prod-readonly-snapshot-v1`.

The trusted runner uses the repository-owned `remote-stream.sh` over SSH. The remote operation has one argument only: the expected 40-character PROD release SHA. It operates only against the fixed `/var/www/agency/current` release.

Release candidate payloads deliberately exclude `.git/`, so runtime identity is not derived from a Git checkout. The route resolves the real target of `/var/www/agency/current`, requires the production promotion-receipt directory, then requires exactly one durable promotion receipt whose `release_path` equals that resolved release. The receipt's `candidate_sha` must be a valid 40-character SHA and must exactly equal the authorized expected PROD release SHA. A missing, duplicate, invalid or mismatched receipt fails closed before the database snapshot starts.

Database connection details come only from PROD server-owned Drupal settings through the fixed `vendor/bin/drush sql:dump` operation. No connection string is supplied by the request, and the route does not execute or print `sql:connect`, generic SQL, a database name, a table name or a user-provided dump option.

The dump options are fixed in repository code through `--extra-dump`:

```text
--single-transaction
--quick
--skip-lock-tables
--no-tablespaces
```

`--single-transaction` provides a transactionally consistent logical snapshot for transactional tables, while `--quick` streams rows and `--skip-lock-tables` prevents table-lock dump behavior. No maintenance/config/content/user/state/scheduler/release mutation, `updb`, `cim`, generic SQL query or deployment command exists in this route.

No `--result-file` is supplied. The remote `drush sql:dump` therefore streams SQL to stdout, which SSH carries directly to the trusted runner. The operation does not create a raw snapshot file on the PROD host. Therefore:

```text
PROD_WRITE_PATH=NONE
```

## Trusted raw material lifecycle

Raw SQL is captured only in the trusted runner's `RUNNER_TEMP`, at a path derived exclusively from GitHub run ID and run attempt. No request field becomes a file path.

The lifecycle script enforces:

- `umask 077`;
- regular raw file mode `0600`;
- raw path outside `GITHUB_WORKSPACE`;
- SHA-256 and byte-size calculation without printing contents;
- EXIT cleanup trap;
- HUP/INT/TERM handlers that exit through the cleanup trap;
- deletion of raw SQL and transient remote stderr on success or failure;
- evidence written only after cleanup has been attempted and absence checked;
- terminal failure if raw absence cannot be proven;
- an independent workflow `always()` finalizer that derives the same private path and removes/verifies it again.

A hard process/host failure can never be made mathematically atomic by a shell trap; the design therefore uses both the in-process trap and workflow finalizer, which is the strongest enforceable cleanup boundary available to this runner model. A future real run is not accepted as successful unless cleanup evidence is `PASS` and `RAW_SNAPSHOT_PRESENT_AFTER_CLEANUP=NO`.

## Metadata-only evidence

The only GitHub artifact path is fixed:

`artifacts/prod-readonly-snapshot/evidence.env`

The exact evidence keys are versioned in `profile.json`. They contain only request/run/release/profile identities, byte size, SHA-256 and PASS/FAIL/NONE metadata. Raw SQL, SQL fragments, database connection strings, credentials, PII, emails, usernames, IP addresses, password/hash/session/reset material, form data, private files and raw archives are forbidden.

Expected successful evidence includes:

```text
snapshot_created=PASS
raw_material_mode=600
snapshot_cleanup=PASS
raw_snapshot_present_after_cleanup=NO
prod_write_path=NONE
preprod_path=NONE
raw_prod_artifact_in_github=NONE
```

## Explicitly absent PREPROD/full-refresh paths

This tranche contains no PREPROD SSH/connection, data transfer, DB import, sanitization execution, backup, activation, rollback, public-files sync, scheduler or full APPLY path.

```text
REAL_PROD_DATA_TRANSFER=NONE
PREPROD_PATH=NONE
PREPROD_DB_IMPORT=NONE
PREPROD_DB_ACTIVATION=NONE
```

Synthetic CI may exercise the same local lifecycle using a fixed non-production fixture. Synthetic bytes on a GitHub-hosted CI runner are not production data and cannot enable the REAL path.

## Stop boundary

Delivery stops once the implementation PR has exact-head CI and targeted snapshot-governance validation green with no blocking review thread.

No real authority comment is created and no real PROD snapshot is triggered during #826 implementation review.
