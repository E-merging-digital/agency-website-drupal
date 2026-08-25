# Agency PREPROD

Status: **REAL / PROVISIONED / TERMINAL GREEN**.

Agency has a physically and logically distinct PREPROD environment. The first
terminal end-to-end proof completed on 2026-08-25 with the exact immutable r7
candidate. PREPROD is now the mandatory functional release gate before a human
can authorize production promotion.

```text
PREPROD = REAL / TERMINAL GREEN
functional promotion = SAME ARTIFACT + EXPLICIT HUMAN GO
automatic main -> PROD = RETIRED by #812 cutover
```

### Superseded pre-cutover wording

For auditability, the following repository phrases describe the state that
existed before the real PREPROD proof and #812 cutover. They are retained here
only as historical assertions and are explicitly **not** the current contract:

- `` `main`: current PROD baseline ``;
- `` `release/*`: coherent functional release candidate ``;
- `` `feature/*`: bounded development branches ``;
- `` `hotfix/*` and `security/*` ``;
- `explicit human GO`;
- `candidate Git SHA`;
- `application artifact SHA-256`;
- `PREPROD target -> not provisioned yet`;
- `existing automatic PROD deploy remains unchanged`.

The first six concepts remain recognizable in the evolved model, but the last
two statements are retired facts: PREPROD is now real and terminal green, and
the automatic functional `main -> PROD` trigger is removed by #812.

## Purpose

PREPROD is an independent risk barrier between a coherent functional release
candidate and production. It executes the exact application artifact intended
for production while keeping data, files, settings, credentials and external
side effects isolated from PROD.

`READY FOR PROD` never means `DEPLOY PROD`. A functional production mutation
requires a separate explicit human GO bound to the exact candidate identity.

## Real topology

The current Agency PREPROD is a distinct Gandi Cloud VM:

```text
hostname = preprod.emergingdigital.be
provider = Gandi Cloud
OS       = Ubuntu 24.04 LTS
runtime  = Nginx + PHP-FPM 8.4 + MariaDB 11.8
DB packet= max_allowed_packet=64M
root     = /var/www/agency-preprod
user     = agency-preprod
```

PREPROD and PROD do not share runtime paths, databases, credentials, persistent
files, settings or deploy locks. PREPROD has its own TLS identity and Basic
Authentication. `/health/live` and `/health/ready` remain public minimal health
signals and expose no secret.

The PREPROD host has a deny-by-default firewall, public HTTPS, no public MariaDB
and `X-Robots-Tag: noindex, nofollow, noarchive` on normal PREPROD responses.

## Capacity: observed and accepted

The first real PREPROD proof intentionally used the smaller provisioned host:

```text
CPU              = 1 vCPU
RAM              = ~2 GiB
root storage     = ~25 GB
swap             = 0
terminal r7 OOM  = 0
terminal r7 disk = 23% used
RAM available    = ~969 MB after validation
capacity decision= KEEP
```

The earlier conservative planning profile of 2 vCPU / 4 GiB / 40 GiB is no
longer a mandatory minimum. Real evidence wins: Agency keeps 1 vCPU / 2 GiB /
25 GB until OOM, disk pressure, unstable runtime or unacceptable execution time
proves that a resize is needed.

## Filesystem contract

Agency PREPROD owns:

```text
/var/www/agency-preprod/
├── releases/
├── current -> releases/<release>
└── shared/
    ├── artifacts/
    ├── backups/
    ├── deploy-jobs/
    ├── files/
    ├── private/
    ├── logs/
    └── settings/
```

Exact candidate archives are retained under `shared/artifacts/<sha>/<digest>` so
PREPROD can preserve the bytes it actually validated independently of GitHub
artifact retention.

## Access and side-effect isolation

Normal PREPROD pages are protected by HTTP Basic Authentication using PREPROD-
only credentials. Secrets are never committed or copied into evidence.

The runtime remains fail-safe:

| Capability | PREPROD policy |
| --- | --- |
| Email/Webform | PHP `sendmail_path=/bin/true`; no real delivery. |
| Analytics | Production Config Split OFF; Google Tag disabled. |
| OpenAI/Drupal AI | No production/provider key injected by default. |
| Automated cron | OFF. |
| Queues | No independent worker enabled for the release proof. |
| Webhooks/APIs | No PROD credential provisioned. |
| DB/files/settings | Separate from PROD. |

The `production` Config Split is forced OFF and the `preproduction` split is ON.
The deployment workflow records non-sensitive runtime evidence proving these
conditions.

## Branch and release model

The durable model is:

- `main`: durable repository baseline and production tooling baseline; merging
  ordinary functional work into it no longer authorizes or triggers production.
- `release/*`: coherent functional candidate built exactly once and validated in
  PREPROD.
- `feature/*`: bounded development branches.
- `hotfix/*` and `security/*`: separate urgent production lane from the current
  production baseline, explicitly dispatched and fail-closed to those refs.

There is no long-lived `develop` or `preprod` integration branch.

After an urgent hotfix/security deployment, every active functional candidate
must reintegrate that change and obtain fresh PREPROD evidence before promotion.

## Immutable candidate identity

`.github/workflows/build-release-candidate.yml` builds a `release/*` candidate
once in production Composer mode and uploads:

- `agency-release-candidate.tar.gz`;
- `agency-release-candidate.tar.gz.sha256`;
- `candidate.json`.

The immutable identity is:

```text
candidate Git SHA
+ artifact SHA-256
+ composer.lock SHA-256
+ successful builder run
```

The payload excludes environment settings, public files, local DDEV state and
secrets. PREPROD and functional PROD promotion consume the same existing
payload; neither route rebuilds it.

## First terminal PREPROD candidate

The first terminal candidate is:

```text
release_branch        = release/agency-preprod-initial-r7
candidate_sha         = 07dd43d3e5923c22de2202dc59ffa70b5100332d
build_run             = 32860381783 / SUCCESS
artifact_sha256       = 6e87dfc8c44cf6e471bcde933129d959005e3fe77a81ee79fb75445997d423fb
composer_lock_sha256  = f33e8adb64d92003b1888159443d3765e48c110b45486600b2057d27a1999db7
preprod_run           = 32860454416 / SUCCESS
release_state         = READY FOR PROD / WAITING FOR HUMAN GO
```

Terminal proof includes exact artifact activation, detached worker, Governed
Content, side-effect isolation, Basic Auth, live/ready health, protected smoke,
Playwright desktop/mobile, DOM, visual, console and network checks. Unexpected
4xx, 5xx and failed browser requests were all zero.

## PREPROD deployment sequence

A successful release build triggers `.github/workflows/deploy-preproduction.yml`.
The route:

1. checks out exact candidate tooling;
2. downloads the existing candidate artifact from the successful builder;
3. verifies candidate SHA, payload SHA-256 and composer.lock SHA-256;
4. transfers exact bytes and bounded scripts over the PREPROD SSH identity;
5. uses a PREPROD-specific detached worker and lock;
6. archives the immutable payload;
7. attaches PREPROD shared settings/files;
8. creates a DB backup when an active release exists;
9. runs maintenance, `updb`, `cim`, PREPROD Config Split, Governed Content and
   `cr`;
10. validates side-effect isolation and capacity;
11. validates Basic Auth, health and protected HTTP smoke;
12. executes Playwright desktop/mobile and publishes non-sensitive evidence.

No `git clone` or `composer install` occurs on PREPROD.

## Governed same-artifact functional PROD promotion

#812 introduces `.github/workflows/promote-production.yml`. It is intentionally
not a `push` or `workflow_dispatch` route. A functional promotion can start only
from a newly created owner-authored issue comment with this exact shape:

```text
/agency-production-promote go sha=<40hex> artifact=<64hex> composer=<64hex> build=<run-id> preprod=<run-id>
```

The command itself is the human GO. It is valid only on an OPEN issue created by
the repository owner and is bound to:

- exact candidate SHA;
- exact artifact SHA-256;
- exact composer.lock SHA-256;
- exact successful builder run;
- exact successful PREPROD run.

The workflow hashes the exact authorization body and binds the GitHub comment ID
to the remote request/evidence. Re-running the same successful candidate is
blocked by a durable production receipt under `shared/promotions`; the common
server deploy lock also serializes functional promotions and emergency
hotfix/security deployments.

Before any production SSH mutation, the route:

1. verifies the owner/open-issue GO contract;
2. verifies the builder run belongs to `build-release-candidate.yml`, succeeded,
   targets the candidate SHA and came from `release/*`;
3. downloads the already-existing artifact and re-verifies all three digests;
4. verifies the exact PREPROD run is terminal SUCCESS;
5. downloads its evidence and requires matching candidate/artifact/composer
   identity plus Governed Content, side-effects, Basic Auth, health, protected
   smoke and desktop/mobile browser PASS;
6. rejects a candidate when live `main` contains later functional changes. The
   only tolerated main-only delta is the bounded #812 promotion/cutover tooling
   itself.

Only after all gates pass can exact bytes be staged to production.

### Production activation sequence

`scripts/production-promotion/promote-candidate.sh` never clones Git and never
runs Composer. It:

```text
verify candidate.json + payload checksum
-> archive exact bytes
-> unpack exact payload
-> attach PROD shared settings/files
-> preflight active Drupal + DB
-> DB backup
-> maintenance ON
-> switch to exact candidate release
-> updb
-> cim
-> production Config Split
-> Governed Content validate + dry-run + apply
-> cr
-> maintenance OFF
-> write immutable promotion receipt/evidence
```

The post-deploy workflow then requires `/health/live`, `/health/ready`, canonical
`/fr/blog` smoke and Playwright desktop/mobile PASS. Only non-sensitive identity
and validation evidence is uploaded.

## Legacy automatic PROD cutover

Before #812, `.github/workflows/deploy-production.yml` ran on ordinary `push` to
`main`, cloned the repository on PROD and executed Composer there. That behavior
could produce bytes different from PREPROD.

After #812:

```text
ordinary push/merge main -> NO PROD DEPLOY
functional release       -> PREPROD -> exact human GO -> SAME ARTIFACT PROD
hotfix/security           -> explicit workflow_dispatch on hotfix/*|security/* only
```

The emergency lane intentionally remains separate and explicit while same-
artifact functional promotion becomes the normal route. It is not a backdoor
for ordinary `main` changes.

## Rollback boundary

The promotion script records both:

```text
previous_release=<absolute previous release path>
database_backup=<pre-promotion SQL dump>
rollback_boundary=PREVIOUS_RELEASE_PLUS_DB_BACKUP
```

The previous release is retained as the code rollback boundary. The SQL dump is
the database recovery boundary. Agency does **not** claim an automatic DB
rollback after `updb`, configuration import or Governed Content mutation because
schema/config/content changes may not be safely reversible by blindly restoring
code alone.

A failed post-switch deployment attempts to leave maintenance mode, captures
what happened and stops. Any DB restore is a separate, explicit operator
decision based on the failure phase and backup evidence.

## Data and files

PREPROD uses an independent DB and files. If production fidelity is needed, the
only accepted data path remains:

```text
one-way PROD snapshot
-> sanitize/anonymize
-> independent PREPROD database
-> deterministic test users/fixtures
```

PREPROD never receives runtime access to the production DB. Private files remain
excluded unless a specific sanitized need is approved.

## Platform Ops registration

#758 requires the real PREPROD environment to be represented in
`E-merging-digital/platform-ops` without secrets. Registration belongs to the
platform-ops service/environment registry and may include only non-sensitive
facts such as service name, environment type, public hostname, health endpoints,
provider class and ownership. SSH keys, Basic Auth values, DB credentials,
Drupal secrets and provider tokens must never be copied into that repository.

## Current release state

At the #812 implementation gate:

```text
PREPROD                    = REAL / TERMINAL GREEN
first terminal candidate   = r7
candidate                  = 07dd43d3e5923c22de2202dc59ffa70b5100332d
artifact                   = 6e87dfc8c44cf6e471bcde933129d959005e3fe77a81ee79fb75445997d423fb
PREPROD proof              = 32860454416 / SUCCESS
capacity                   = KEEP 1 vCPU / 2 GiB / 25 GB
functional PROD promotion  = READY FOR HUMAN GO after #812 merge proof
PROD mutation under #812   = NONE until an exact human GO is issued
```
