# Agency Development Seed

Status: **SOURCE_IMPLEMENTED / SYNTHETICALLY_PROVEN / REAL PROOF PENDING**  
Owner: #873; native DDEV simplification: #1108; first real publisher/proof tranche: #956.  
Architecture: `EXTEND_EXISTING / SIMPLIFY` under `docs/decisions/ADR-003-use-existing-first.md`.

This runbook covers the Development Seed flow for local Agency DDEV only. It does not replace code/config deployment, PROD → PREPROD refresh or editorial publication.

## Current truth

```text
#816 = CLOSED / COMPLETED
PROD_TO_PREPROD_REFRESH = REAL_EXECUTION_PROVEN
PREPROD = CURRENT SANITIZED SOURCE AVAILABLE

#1108_REPOSITORY_WORK = NATIVE DDEV SNAPSHOT / LOCAL-FIRST
#956_REAL_SEED_PROOF = PENDING

PRIMARY_CONSUMER = JONATHAN_LOCAL_DDEV
SECONDARY_CONSUMERS = LOCAL_WORKTREES / LOCAL_AGENTS
DDEV_MINIMUM = 1.25.4
DATABASE = mariadb:11.8
```

Repository/static proof is not real seed publication. Until an explicitly authorized post-merge #956 execution succeeds, `REAL_SEED_GENERATION`, `REAL_STORAGE`, `REAL_DISTRIBUTION` and `REAL_LOCAL_CONSUMPTION` remain `PENDING`.

## Security boundary

```text
PROD_ACCESS = NONE
PROD_CREDENTIALS = NONE
SOURCE = CURRENT SANITIZED PREPROD ONLY
PREPROD_RUNTIME_DB_WRITE = NONE
PREPROD_RUNTIME_SETTINGS_MUTATION = NONE
RAW_PREPROD_ON_GITHUB_HOSTED = NONE
UNSANITIZED_PREPROD_COPY_DISTRIBUTED = NONE
DATABASE_ARTIFACT_IN_GITHUB_ARTIFACTS = NONE
SANITIZED_SEED_IN_GIT_BY_DEFAULT = NONE
SECRETS_IN_SEED = NONE
PUBLIC_FILES_V1 = NONE
PRIVATE_FILES = NONE
DDEV_PUSH = NONE
```

The immutable Development Seed is a database-only **DDEV native snapshot** created after all existing sanitization and assertions have passed in an isolated DDEV database.

```text
RAW_SNAPSHOT != SANITIZED_SEED
```

A raw PREPROD logical stream is source acquisition material only. It is never a distributable Development Seed. The distributable seed exists only after Drush sanitization, the existing Agency sanitizer and the Development Seed sanitizer/assertions all succeed.

## Target flow

```text
CURRENT SANITIZED PREPROD runtime DB
→ fixed read-only PREPROD logical stream
→ trusted self-hosted Agency/DDEV generation surface
→ isolated temporary DDEV database
→ existing Drush sql:sanitize
→ existing #914 Agency sanitizer
→ existing agency-development-seed-v1 sanitizer/assertions
→ ddev snapshot --name=<seed-id>
→ database-mariadb_11.8.zst
→ seed.json + SHA-256
→ fixed external immutable seed storage
→ restricted read-only SCP identity
→ verified local cache outside Git
→ fresh local: ddev start --seed-snapshot=<verified-local-path>
→ existing local reset: ddev start --reset-database --seed-snapshot=<verified-local-path>
→ updb / cim / cr / local convergence / side-effect assertions
```

There is no post-sanitization `sql:dump | gzip` distribution stage; the former provider-based SQL pull consumer path is removed. The initial logical stream remains necessary only to copy the already-sanitized PREPROD source into the isolated generation DDEV database; it is deleted immediately after that import.

## How to create a sanitized seed

Only the existing governed #956 publisher may perform real generation after separate Project Lead authority. Its durable sequence is:

1. JIT-prove the exact current sanitized PREPROD refresh identity and application release;
2. stream the fixed read-only source into trusted `RUNNER_TEMP`;
3. import it once into an isolated DDEV generation worktree;
4. delete the raw stream;
5. run `drush sql:sanitize`;
6. run the existing Agency sanitizer `scripts/preproduction-refresh/governed-successor/agency-sanitize.php` unchanged;
7. run `scripts/development-seed/agency-development-sanitize.php`, including its existing assertions;
8. only then create a DDEV snapshot with `ddev snapshot --name=<seed-id> -y`;
9. retain the native MariaDB 11.8 suffix as `database-mariadb_11.8.zst`;
10. create `seed.json`, calculate SHA-256 and run `verify-seed.php` before publication;
11. publish only the verified snapshot + metadata to immutable external storage.

```text
SANITIZATION_BEFORE_SNAPSHOT = REQUIRED
POST_SANITIZATION_SQL_ROUNDTRIP = NONE
```

No real generation is authorized merely by this runbook.

## Seed metadata and compatibility

`build-seed-metadata.php` records:

- immutable seed ID;
- source PREPROD refresh identity;
- source application release SHA;
- sanitization policy ID/version;
- snapshot byte size and SHA-256 (`database_sha256` is retained as the existing metadata key);
- minimum DDEV `1.25.4`;
- exact database compatibility `mariadb:11.8`;
- exact native snapshot filename `database-mariadb_11.8.zst`;
- Drush compatibility.

`verify-seed.php` fails closed unless the metadata, artifact hash, snapshot filename, MariaDB version and Git ancestry all match. It supports both normal clones and Git worktrees.

```text
checkout == seed release           -> allowed
seed release ancestor of checkout -> allowed
checkout older/diverged           -> fail closed
DATABASE_COMPATIBILITY             -> mariadb:11.8 / fail closed
SEED_SHA256                        -> required / verified
```

## Where the seed may live

Server-side canonical storage remains outside Git under the existing PREPROD project-owned shared root:

```text
/var/www/agency-preprod/shared/development-seeds/
  immutable/
    <seed-id>/
      database-mariadb_11.8.zst
      seed.json
  current -> immutable/<seed-id>
  .incoming/<request-id>/
  read-only-scp.sh
```

Local consumers cache verified immutable seeds outside the repository. Default:

```text
${XDG_CACHE_HOME:-$HOME/.cache}/agency-development-seeds/<seed-id>/
  database-mariadb_11.8.zst
  seed.json
```

`AGENCY_SEED_CACHE_DIR` may select another local directory, but `use-native-seed.sh` rejects a cache located inside the Git checkout.

## Where it must not live

```text
Git tracked files                     = FORBIDDEN BY DEFAULT
GitHub Actions artifacts              = FORBIDDEN
GitHub-hosted runner database storage = FORBIDDEN
PROD runtime                           = FORBIDDEN
PREPROD runtime database directory     = FORBIDDEN
public/private Drupal files            = OUT OF SCOPE
```

Only non-sensitive reproducibility metadata may be copied transiently to the ignored `.ddev/.downloads` / `.ddev/.state-agency-seed.json` working area for local convergence/state recording.

## Restricted reader identity

The reader continues to use the existing `agency-preprod` Unix account with a distinct restricted SSH key, never the deployment credential. Its forced command permits legacy SCP server read mode for exactly:

```text
.../development-seeds/current/seed.json
.../development-seeds/current/database-mariadb_11.8.zst
```

Upload mode, general shell and every other path are rejected. No PROD or PREPROD deployment credential belongs in local DDEV configuration.

## How to consume a sanitized seed

Prerequisites on the local host:

- DDEV >= 1.25.4;
- Git, PHP CLI and OpenSSH `scp`;
- the dedicated restricted reader identity available to the local SSH client;
- `AGENCY_SEED_SSH_TARGET=agency-preprod@<approved-host>`.

The canonical local helper performs download, pinned-host verification, SHA-256/metadata/compatibility verification and then invokes DDEV's native primitive.

Fresh local database volume:

```bash
export AGENCY_SEED_SSH_TARGET='agency-preprod@<approved-host>'
bash scripts/development-seed/use-native-seed.sh fresh
```

Its database action is exactly:

```bash
ddev start --seed-snapshot=<verified-local-path>/database-mariadb_11.8.zst
```

`--seed-snapshot` is explicit and command-scoped. The repository does not use DDEV's reserved implicit `seed` snapshot, so ordinary `ddev start` never silently resets or reseeds an existing database.

## How to reset to the known baseline

Reset is deliberately a separate human command:

```bash
export AGENCY_SEED_SSH_TARGET='agency-preprod@<approved-host>'
bash scripts/development-seed/use-native-seed.sh reset
```

Its database action is exactly:

```bash
ddev start --reset-database --seed-snapshot=<verified-local-path>/database-mariadb_11.8.zst
```

The helper intentionally does **not** bypass reset confirmation or DDEV's default pre-reset safety snapshot. Therefore reset remains explicit and the database being replaced is backed up by DDEV before reset.

```text
IMPLICIT_RESET = NONE
RESET_DEFAULT_BACKUP = PRESERVED
```

## Local convergence and side-effect assertions

After native seed/reset succeeds, the existing `scripts/development-seed/post-pull.sh` convergence surface is reused directly; its historical filename is retained to avoid unnecessary churn. It runs:

```text
drush updb -y
drush cim -y
drush cr
drush php:script scripts/development-seed/local-converge.php
drush cr
Drupal bootstrap assertion
```

`local-converge.php` continues to require:

- production Config Split OFF;
- PREPROD Config Split OFF;
- analytics OFF;
- provider/AI egress OFF;
- secret-free local mail baseline;
- sensitive runtime state empty;
- local-only `agency-local-admin` created only after seed consumption.

No local admin is transported inside the seed.

## Git worktrees and agents

A worktree or local agent may point `AGENCY_SEED_CACHE_DIR` to the same verified external cache. DDEV 1.25.4 can also discover snapshots from sibling worktrees, but that is convenience only. Correctness never depends on sibling-worktree discovery or shared project names.

The authoritative path remains the explicit verified local snapshot path.

## Phase A vs first real proof

Repository implementation and synthetic/static validation do **not** constitute real operation.

During #1108 Delivery:

```text
REAL_PREPROD_ACCESS = NONE
REAL_PREPROD_DB_READ = NONE
REAL_SEED_GENERATION = NONE
REAL_SEED_PUBLICATION = NONE
REAL_LOCAL_CONSUMPTION = NONE
PROD_ACCESS = NONE
```

The later separately authorized #956 proof must establish all of:

```text
SOURCE_PREPROD_REFRESH_ID = CURRENT / PROVEN
SOURCE_PREPROD_RELEASE_SHA = CURRENT / PROVEN
PREPROD_RUNTIME_DB_WRITE = NONE
DEVELOPMENT_SANITIZATION = PASS
DDEV_NATIVE_SNAPSHOT = PASS
SEED_ID = IMMUTABLE
DATABASE_SHA256 = VERIFIED
SEED_STORAGE = PUBLISHED
CURRENT_POINTER = VERIFIED
READ_ONLY_DISTRIBUTION = PROVEN
DDEV_NATIVE_SEED = REAL SUCCESS
LOCAL_SIDE_EFFECT_ASSERTIONS = PASS
TEMPORARY_GENERATION_MATERIAL = ABSENT
```

## Authoritative files

- `.ddev/config.development-seed.yaml`
- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/development-seed-publish.yml`
- `scripts/development-seed/use-native-seed.sh`
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

`.ddev/providers/agency.yaml` is intentionally removed because retaining it would preserve the obsolete SQL-import distribution path alongside native DDEV snapshots.

No command in this document grants execution authority. Reload live main, #956 and current source identities before any real publication.
