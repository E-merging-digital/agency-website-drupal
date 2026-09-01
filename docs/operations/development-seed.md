# Development Seed — DDEV pull-only

Status: **source/configuration contract + synthetic proof only**  
Owner: **#873**  
Parent: **#870**  
Source dependency: **#816**

## Purpose

Agency developers and AI agents consume one immutable, privacy-safe database
baseline without PROD access, PROD credentials, PREPROD runtime credentials, or
a shared mutable development database.

The v1 direction is intentionally one-way and database-only:

```text
sanitized PREPROD (future source, only after terminal #816 proof)
-> bounded read-only dump on an isolated seed-generation surface
-> Drush sql:sanitize
-> existing #914 Agency sanitizer
-> thin development sanitizer
-> immutable database.sql.gz + seed.json + SHA-256
-> controlled SSH/SCP read-only storage
-> DDEV provider agency
-> ddev pull agency
-> DDEV snapshot of current local DB
-> native DDEV import
-> drush updb / cim / cr
-> local-only administrator + side-effect assertions
```

There is no reverse path. `.ddev/providers/agency.yaml` contains no database or
files push stanza.

## Current execution boundary

#816 is not yet terminally proven with a real refresh, so #873 does **not**
perform or authorize a real PREPROD dump, real PREPROD data read, seed
publication, or distribution. The repository materializes the future contract
and proves it only with synthetic/local data.

```text
PROD access                     = NONE
real PREPROD data read          = NONE
real Development Seed generated = NONE
real Development Seed published = NONE
real storage/account provisioned = NONE
```

A future real publisher must be separately authorized after #816 establishes
the safe source boundary.

## USE EXISTING FIRST

The implementation extends existing primitives rather than replacing them:

- Drush 13.7.6 `sql:sanitize` owns generic user/password/session sanitization;
- `scripts/preproduction-refresh/governed-successor/agency-sanitize.php` owns the
  existing Agency PREPROD-specific pass;
- the development pass only removes additional distributable-development state;
- DDEV 1.25.3 owns `pull`, database import and `snapshot` / `snapshot restore`;
- standard Git ancestry owns the simple code/seed compatibility guard;
- standard SSH/SCP is the selected distribution contract; no seed API/registry
  or cloud platform is introduced.

## Future seed generation recipe

This recipe is **documentation of the bounded future generation surface**, not
an authorization to execute it against PREPROD today. The source database must
already be a separately authorized sanitized PREPROD-derived copy isolated from
the PREPROD runtime.

The sanitization order is mandatory:

```bash
drush sql:sanitize -y
drush php:script scripts/preproduction-refresh/governed-successor/agency-sanitize.php
drush php:script scripts/development-seed/agency-development-sanitize.php
drush sql:dump --gzip --result-file=/protected/temp/database.sql.gz
php scripts/development-seed/build-seed-metadata.php \
  --database=/protected/temp/database.sql.gz \
  --seed-id=agency-development-seed-v1-<immutable-id> \
  --created-at=<UTC-RFC3339> \
  --source-refresh=<preprod-refresh-identity> \
  --source-release=<40-hex-application-release> \
  --output=/protected/temp/seed.json
```

The seed DB and metadata are immutable after publication. `database_sha256` is
the authoritative byte identity. Metadata contains no DB values or PII.

## Storage/distribution contract and provisioning gap

Prefer existing Agency/PREPROD server storage with a **dedicated seed-reader
SSH identity** that can read only the sanitized seed directory. This identity is
a distribution credential, not a PREPROD runtime credential. It must have no
shell/database/runtime authority and no write route from DDEV.

Expected storage shape:

```text
/srv/agency-development-seeds/
  immutable/<seed-id>/database.sql.gz
  immutable/<seed-id>/seed.json
  current -> immutable/<seed-id>
```

`current` is only a small storage pointer; the addressed seed remains immutable.
If the pointer changes between the metadata and DB transfers, SHA-256
verification fails closed.

**Provisioning is intentionally absent in #873.** No seed-reader account/key,
real directory or external infrastructure is created until separately
authorized. A sanitized DB must never be committed, placed in a public GitHub
release, or uploaded as a normal GitHub Actions artifact.

## First use

```bash
git clone <repository>
cd agency-website-drupal
ddev start
```

Configure the future read-only seed location in the gitignored
`.ddev/config.local.yaml`, for example:

```yaml
web_environment:
  - AGENCY_SEED_SSH_TARGET=agency-seed-reader@seed-host.example
  - AGENCY_SEED_REMOTE_DIR=/srv/agency-development-seeds
```

Load the dedicated read-only SSH key into the host agent, then expose the agent
to DDEV using the native command:

```bash
ddev auth ssh
ddev restart
ddev pull agency
```

Do not place the SSH private key in Git or in provider YAML.

After a successful pull, the non-sensitive consumed seed metadata is recorded in
`.ddev/.state-agency-seed.json` (already covered by the repository's `.state*`
ignore rule). Obtain a local one-time login without transporting a password in
the seed:

```bash
ddev drush uli --name=agency-local-admin
```

## Routine refresh

When the authorized storage pointer moves to a newer compatible immutable seed:

```bash
ddev pull agency
```

DDEV snapshots the current local database before import. The pulled bytes are
verified before DDEV's native import. Normal Drupal convergence then runs:
`updb`, `cim`, `cr`, local admin recreation and side-effect assertions.

## Compatibility

```text
seed release == checkout release
  -> supported

seed release is an ancestor of checkout
  -> supported; normal updb/cim handles forward convergence

feature branch descended from seed release
  -> supported through the same deterministic convergence

checkout older than seed release
  -> FAIL CLOSED

divergent/unknown seed history
  -> FAIL CLOSED
```

There is no downgrade engine. If local Git history does not contain the seed
source release, fetch the required history and retry rather than bypassing the
guard.

## Recovery

No custom recovery engine exists. Use the standard DDEV snapshot created just
before pull:

```bash
ddev snapshot list
ddev snapshot restore <agency-before-pull-...>
```

or, when the immediately previous snapshot is the intended recovery point:

```bash
ddev snapshot restore --latest
```

If convergence failed, restore the snapshot, fix the code/compatibility problem,
and rerun `ddev pull agency`.

## Local safety after import

The local convergence script fails closed unless all of these hold:

- DDEV is the active environment;
- production and PREPROD Config Splits are OFF;
- Analytics container is OFF;
- external AI/provider egress is OFF by default;
- mail remains the secret-free native DDEV/Mailpit baseline;
- sessions, Webform submissions, flood state, watchdog and queues are empty;
- `agency-local-admin` is recreated locally with random authentication material.

The seed never carries the local administrator password/account state.

## Reproduce a bug

Record only non-sensitive identity:

```text
CODE_SHA=<git rev-parse HEAD>
SEED_ID=<seed_id from .ddev/.state-agency-seed.json>
SEED_SOURCE_PREPROD_REFRESH=<source_preprod_refresh_identity>
SANITIZATION_POLICY=agency-development-seed-v1:1
DDEV_VERSION=1.25.3
DATABASE=mariadb:11.8
DRUSH_VERSION=13.7.6
```

Another human or agent can consume the same immutable seed in an independent
DDEV project/worktree. No shared mutable DB and no PROD access are required.

## Files

Development Seed v1 contains **database only**.

```text
PUBLIC_FILES = NONE
PRIVATE_FILES = NEVER
```

Public-file fidelity, if later required, belongs to a separate issue and should
first evaluate Stage File Proxy or another existing primitive.

## Synthetic proof

Run locally or in canonical CI:

```bash
php scripts/development-seed/test-contract.php
```

The proof uses a generated synthetic SQL gzip and a temporary Git repository. It
also reruns the existing #816 synthetic sanitization fixture. It proves metadata
reproducibility, SHA-256 refusal on corruption, fail-closed downgrade handling,
pull-only provider topology and local side-effect contract without network,
PROD/PREPROD credentials or real data.
