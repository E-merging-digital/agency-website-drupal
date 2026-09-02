# Agency Development Seed

Status: **SOURCE_IMPLEMENTED / SYNTHETICALLY_PROVEN / REAL PROOF PENDING**
Owner: #873; first real publisher/proof tranche: #956.
Architecture: `EXTEND_EXISTING` under `docs/decisions/ADR-003-use-existing-first.md`.

This runbook covers only the pull-only Development Seed flow. It does not replace code/config deployment, PROD -> PREPROD refresh or editorial publication.

## Current truth

```text
#816 = CLOSED / COMPLETED
PROD_TO_PREPROD_REFRESH = REAL_EXECUTION_PROVEN
PREPROD = CURRENT SANITIZED SOURCE AVAILABLE
#873_BLOCKED_BY_816 = NO

REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
SYNTHETIC_PROOF = COMPLETE

REAL_SEED_GENERATION = PENDING
REAL_STORAGE = PENDING
REAL_DISTRIBUTION = PENDING
REAL_DDEV_PULL = PENDING
```

#956 materializes the repository publisher, fixed storage contract and restricted read-only distribution path. Until the first post-merge #956 execution reaches the complete real proof, those four `REAL_*` states remain `PENDING`.

## Boundary and target flow

```text
CURRENT SANITIZED PREPROD runtime DB
-> fixed read-only PREPROD logical dump
-> trusted self-hosted Agency/DDEV generation surface
-> isolated temporary DDEV database
-> Drush sql:sanitize
-> existing #914 Agency sanitizer
-> agency-development-seed-v1 sanitizer/assertions
-> database.sql.gz
-> seed.json + SHA-256
-> fixed PREPROD-host seed storage
-> restricted read-only SCP identity
-> ddev pull agency
-> DDEV native import/snapshot recovery
-> updb / cim / cr
-> local-only admin + local side-effect assertions
```

Permanent boundaries:

```text
PROD_ACCESS = NONE
PROD_CREDENTIALS = NONE
SOURCE = CURRENT SANITIZED PREPROD ONLY
PREPROD_RUNTIME_DB_WRITE = NONE
PREPROD_RUNTIME_SETTINGS_MUTATION = NONE
RAW_PREPROD_ON_GITHUB_HOSTED = NONE
UNSANITIZED_PREPROD_COPY_DISTRIBUTED = NONE
DB_AS_GITHUB_ARTIFACT = NONE
PII_IN_LOGS = NONE
SECRETS_IN_SEED = NONE
PUBLIC_FILES_V1 = NONE
PRIVATE_FILES = NONE
DDEV_PUSH = NONE
```

All destructive sanitization occurs in the isolated DDEV generation database. The live PREPROD Drupal runtime never points to that temporary database.

## Source identity — current and JIT

`scripts/development-seed/remote-readonly-preprod-source.sh` is the fixed PREPROD source primitive. It accepts only `PROBE` or `STREAM` plus the exact expected source refresh and application release identities.

It resolves current data identity from the durable #914 `shared/refresh-jobs/*/result.env` terminal evidence:

- a current unresolved `HUMAN_RECOVERY_REQUIRED` state fails closed;
- a proven `ROLLED_BACK` result leaves the previous proven runtime source current;
- the accepted source must resolve to `COMMITTED / SANITIZED_DATABASE_ACTIVE_AND_VALIDATED`.

It independently binds the current `/var/www/agency-preprod/current` release to the existing full-SHA archive under `shared/artifacts/<candidate-sha>/...`. Both resolved identities must equal the #956 one-shot authority immediately before streaming.

`STREAM` executes only fixed Drush bootstrap/read-only `sql:dump` against server-owned PREPROD settings. It accepts no caller SQL, DB name, result path or dump options.

## Sanitization and seed identity

The existing layers remain authoritative:

1. Drush `sql:sanitize`, with non-persisted random password material and deterministic `example.invalid` mail;
2. `scripts/preproduction-refresh/governed-successor/agency-sanitize.php`;
3. `scripts/development-seed/agency-development-sanitize.php`;
4. Development Seed assertions from `scripts/development-seed/sanitization-policy.json`.

Only after those passes does the publisher create:

```text
database.sql.gz
seed.json
```

`build-seed-metadata.php` records the immutable seed identity, source PREPROD refresh identity, source PREPROD application release SHA, policy identity/version, byte size, SHA-256 and compatibility metadata. `verify-seed.php` must pass before publication.

## Fixed storage

Agency reuses the existing PREPROD project-owned `shared` root rather than introducing `/srv` provisioning or another system account:

```text
/var/www/agency-preprod/shared/development-seeds/
  immutable/
    <seed-id>/
      database.sql.gz
      seed.json
  current -> immutable/<seed-id>
  .incoming/<request-id>/
  read-only-scp.sh
```

Contract:

```text
SEED_DIRECTORY = FIXED / NOT CALLER-CONTROLLED
IMMUTABLE_SEED_ID_REUSE = FORBIDDEN
CURRENT_POINTER_SWITCH = AFTER DATABASE + METADATA + READER VERIFICATION ONLY
INCOMING_TEMP = CLEANED / ABSENCE REQUIRED
```

`remote-storage.sh` refuses an already published seed identity, validates the database SHA-256 and metadata before moving the two-file payload to `immutable/<seed-id>`, then switches `current` atomically. It never touches the PREPROD runtime DB or Drupal settings.

## Restricted reader identity

The smallest safe reader uses the existing `agency-preprod` Unix account with a **distinct dedicated SSH key**, not the deployment key.

The reader public key is installed as an `authorized_keys` line using:

```text
restrict
+ forced command /var/www/agency-preprod/shared/development-seeds/read-only-scp.sh
```

The forced command accepts only legacy SCP server read mode for exactly:

```text
.../development-seeds/current/seed.json
.../development-seeds/current/database.sql.gz
```

It rejects upload mode, general shell and all other paths. `restrict` disables PTY and forwarding capabilities. The first automated #956 proof uses an ephemeral reader key and removes it terminally. A future human developer may supply a developer-generated public key through a small controlled onboarding operation; no key-registration service is implied.

## Operational publication route

The single existing top-level dispatcher remains authoritative. #956 adds one exact route to `.github/workflows/agency-command-dispatch.yml`, calling `.github/workflows/development-seed-publish.yml`.

Phase-B command shape:

```text
/agency-development-seed publish \
  seed-956-<fresh-suffix>-r1 \
  <exact-live-main> \
  <current-preprod-refresh-id> \
  <current-preprod-release-sha>
```

The route is bound to #956 and owner `E-merging-digital`, requires `run_attempt=1`, an exact unique request ID, exact live main and exact source identities. The same authority is revalidated on the trusted self-hosted runner **before** the PREPROD SSH key is materialized. Duplicate request IDs, reruns and stale main/source identities fail closed.

No PLAN ceremony and no generic authority framework are introduced.

## Generation and terminal cleanup

The real publisher runs only on the registered trusted Agency DDEV surface:

```text
self-hosted, linux, x64, agency, ddev
```

The raw sanitized-PREPROD dump exists only in `RUNNER_TEMP` on that trusted runner until DDEV native import succeeds, then is deleted immediately. The isolated generation DDEV environment is deleted before the distribution proof.

Every terminal path attempts and proves cleanup of all temporary material it may have created:

```text
RAW_PREPROD_TEMP_DUMP
TEMP_DDEV_GENERATION_DB
TEMP_DDEV_PROOF_DB
TEMP_WORKTREES
TEMP_INCOMING_STORAGE
TEMP_READER_IDENTITY
TEMP_SSH_KEYS / KNOWN_HOSTS / AGENT
```

Unproven cleanup fails the publication job. No recovery registry/state machine is added.

## Developer UX

The normal consumer command remains:

```bash
ddev pull agency
```

Local setup requires only the dedicated restricted reader target, for example in an ignored `.ddev/config.local.yaml`:

```yaml
web_environment:
  - AGENCY_SEED_SSH_TARGET=agency-preprod@preprod.example.invalid
```

The private key stays local and is exposed to DDEV through standard `ddev auth ssh`. `.ddev/providers/agency.yaml` pins the existing repository PREPROD host key, uses the fixed remote seed root and downloads only `seed.json` plus `database.sql.gz` using standard OpenSSH SCP read mode.

There is no `AGENCY_SEED_REMOTE_DIR` setting and no push stanza.

## DDEV import, convergence and rollback

The #873 consumer remains native:

```text
pre-pull -> ddev snapshot
pull -> verify seed metadata/SHA + compatibility
DDEV native DB import
post-pull -> drush updb -y
             drush cim -y
             drush cr
             local-converge.php
             drush cr
```

`local-converge.php` requires:

- production Config Split OFF;
- PREPROD Config Split OFF;
- analytics OFF;
- provider/AI egress OFF;
- secret-free local mail baseline;
- sessions/Webform/log/queue state empty;
- local-only `agency-local-admin` creation after import.

If import/convergence fails, recovery is DDEV-native:

```bash
ddev snapshot list
ddev snapshot restore <snapshot-name>
```

No Agency database rollback engine exists.

## Compatibility

`verify-seed.php` preserves the #873 Git ancestry rule:

```text
checkout == seed release          -> allowed
seed release ancestor of checkout -> allowed
checkout older/diverged           -> fail closed
```

The immutable seed identity remains unchanged even when a newer branch applies its own `updb`/`cim` locally.

## Phase A vs first real proof

Repository implementation and synthetic/static validation do **not** constitute real seed operation.

Before Project Lead authorizes Phase B on #956:

```text
PREPROD_ACCESS = NONE
REAL_PREPROD_DB_READ = NONE
REAL_STORAGE_PROVISIONING = NONE
REAL_SEED_GENERATION = NONE
REAL_DISTRIBUTION = NONE
REAL_DDEV_PULL = NONE
```

The first real proof must establish all of:

```text
SOURCE_PREPROD_REFRESH_ID = CURRENT / PROVEN
SOURCE_PREPROD_RELEASE_SHA = CURRENT / PROVEN
PREPROD_RUNTIME_DB_WRITE = NONE
DEVELOPMENT_SANITIZATION = PASS
SEED_ID = IMMUTABLE
DATABASE_SHA256 = VERIFIED
SEED_STORAGE = PUBLISHED
CURRENT_POINTER = VERIFIED
READ_ONLY_DISTRIBUTION = PROVEN
DDEV_PULL_AGENCY = REAL SUCCESS
LOCAL_SIDE_EFFECT_ASSERTIONS = PASS
TEMPORARY_GENERATION_MATERIAL = ABSENT
```

Only after that evidence exists may the capability be documented as real/operational and #871 terminally close.

## Authoritative files

- `.ddev/providers/agency.yaml`
- `.ddev/config.development-seed.yaml`
- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/development-seed-publish.yml`
- `scripts/development-seed/validate-publish-authority.py`
- `scripts/development-seed/remote-readonly-preprod-source.sh`
- `scripts/development-seed/run-publish.sh`
- `scripts/development-seed/remote-storage.sh`
- `scripts/development-seed/remote-reader-key.sh`
- `scripts/development-seed/remote-read-only-scp.sh`
- `scripts/development-seed/sanitization-policy.json`
- `scripts/development-seed/agency-development-sanitize.php`
- `scripts/development-seed/build-seed-metadata.php`
- `scripts/development-seed/verify-seed.php`
- `scripts/development-seed/post-pull.sh`
- `scripts/development-seed/local-converge.php`

No command in this document grants execution authority. Reload live main, #956 and current PREPROD source identities before any real publication.
