# Agency PREPROD

Status: implementation prepared in the repository. The PREPROD host, DNS,
credentials and database are not created by Git. Until the external host is
provisioned and bootstrapped, the durable state remains:

```text
PREPROD target -> not provisioned yet
```

## Purpose

PREPROD is an independent risk barrier between a coherent functional release
candidate and production. It executes the exact application artifact intended
for production while keeping data, files, settings, credentials and external
side effects isolated from PROD.

## Approved topology

PREPROD infrastructure is distinct from production infrastructure. Several
small PREPROD environments may share one dedicated PREPROD host only when each
site has isolated Unix accounts, release/shared roots, databases and database
users, credentials, files, locks, vhosts, TLS identities, cron/queues and
resource limits.

PROD and Agency PREPROD never share runtime paths, databases, credentials,
persistent files or deploy locks.

## Capacity contract

The provider-neutral host contract is:

| Profile | CPU | RAM | Local storage | Intended use |
| --- | ---: | ---: | ---: | --- |
| Minimum | 2 vCPU | 4 GiB | 40 GiB NVMe/SSD | Agency PREPROD only. |
| Recommended | 4 vCPU | 8 GiB | 75-80 GiB NVMe/SSD | Agency plus 1-2 other small Drupal PREPROD environments. |

The recommended profile is the normal purchase target. The minimum profile is
valid only when Agency is the sole relevant workload and database/files remain
small. A future move to roughly 6 vCPU, 12 GiB RAM and 100+ GiB storage is a
capacity decision, not a prerequisite for #797.

Storage is split logically per application. Agency owns:

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

The immutable candidate archive under `shared/artifacts` preserves the exact
payload and metadata proven on PREPROD even after the GitHub Actions artifact
retention window expires.

## Network, DNS and TLS

Agency PREPROD uses the stable hostname:

```text
preprod.emergingdigital.be
```

A public IPv4 address is required for predictable GitHub Actions, ACME and
operator access. IPv6 is recommended and should be published with an `AAAA`
record when the provider supplies a stable address, but PREPROD must not rely on
IPv6 as its only public route.

Required DNS records are:

- `A preprod.emergingdigital.be` to the PREPROD IPv4 address;
- `AAAA preprod.emergingdigital.be` to the stable PREPROD IPv6 address when
  available.

TLS is a valid public certificate managed by Certbot/Let's Encrypt. DNS must
resolve to the host before the bootstrap requests the certificate.

The host firewall defaults to deny inbound and allows only SSH, HTTP and HTTPS.
MariaDB listens for the application locally and is never exposed as a public
service by the Agency contract.

## Access protection and noindex

Normal PREPROD pages use HTTP Basic Authentication. The credentials are unique
to PREPROD and are never committed or uploaded as evidence.

`/health/live` and `/health/ready` are deliberately exempt from Basic Auth so
provider-neutral external monitoring can read the minimal health contract. The
endpoints expose no credential, version, path, database detail or customer data.
They must answer directly with HTTP 200 and `{"status":"ok"}` when healthy.

Nginx adds this response header on PREPROD:

```text
X-Robots-Tag: noindex, nofollow, noarchive
```

Basic Auth plus this header are the PREPROD anti-indexing boundary. Production
`robots.txt` is not modified for this purpose.

Browser Validation accepts the optional secret environment pair:

```text
BROWSER_VALIDATION_HTTP_USERNAME
BROWSER_VALIDATION_HTTP_PASSWORD
```

The readiness probe and Playwright share those credentials without persisting
them in result JSON, screenshots or repository files.

## Runtime

The bootstrapped runtime is:

- Ubuntu 24.04 LTS;
- Nginx;
- PHP-FPM 8.4 using a dedicated `agency-preprod` pool;
- MariaDB 11.8;
- `max_allowed_packet=64M`;
- Unix user `agency-preprod`;
- project root `/var/www/agency-preprod`.

The bootstrap installs the PHP extensions required by the current Drupal and
Composer application surface, including MySQL, cURL, GD, Intl, Mbstring, XML,
Zip, BCMath and OPcache.

`scripts/preproduction/bootstrap-host.sh` is idempotent for the declared Agency
resources. It requires root privileges only for host provisioning and consumes
four non-versioned values:

```text
AGENCY_PREPROD_DEPLOY_PUBLIC_KEY
PREPROD_BASIC_AUTH_USER
PREPROD_BASIC_AUTH_PASSWORD
PREPROD_TLS_EMAIL
```

It generates the PREPROD database password, Drupal hash salt and initial admin
password locally on the server in:

```text
/var/www/agency-preprod/shared/settings/runtime.env
```

That file is mode 0600 and is not copied into GitHub artifacts.

## Branch and release model

The durable branch model is:

- `main`: current PROD baseline. The existing automatic production deployment
  remains unchanged until a later, separately validated promotion cutover.
- `release/*`: coherent functional release candidate intended for PREPROD
  validation. A release branch starts from the current PROD baseline and
  receives the feature work selected for that candidate.
- `feature/*`: bounded development branches. Functional work destined for an
  active candidate is integrated into its `release/*` branch rather than being
  treated as production-ready merely because CI is green.
- `hotfix/*` and `security/*`: urgent production-baseline lanes. They may be
  promoted independently of the active functional candidate, then must be
  reintegrated into every still-active release candidate.

A functional candidate is not permission to deploy production. Production
promotion requires an explicit human GO after PREPROD evidence is complete.

## Immutable candidate identity

`.github/workflows/build-release-candidate.yml` runs only on `release/**` pushes
and builds the candidate once in production Composer mode.

The uploaded candidate contains:

- `agency-release-candidate.tar.gz`;
- `agency-release-candidate.tar.gz.sha256`;
- `candidate.json`.

The candidate identity is:

```text
candidate Git SHA
+
application artifact SHA-256
```

The versioned `settings.php` scaffold may exist in the Git source tree, but the
release builder excludes it from the candidate payload. The payload also
excludes `.env`, DDEV state and public files.

A PREPROD deploy verifies the candidate SHA, Composer lock digest and payload
digest before unpacking. It then archives and consumes those exact bytes. It
never executes `composer install` or `git clone` on PREPROD.

A future PROD promotion must consume the same already-proven candidate payload
and digest. Rebuilding independently at promotion time is not the target design.

## PREPROD deployment route

After a successful `Build Agency release candidate` run, the default-branch
`.github/workflows/deploy-preproduction.yml` workflow accepts only a same-repo
`release/*` source. It:

1. checks out the exact candidate SHA for deployment tooling and lock identity;
2. downloads the exact artifact from the triggering build run;
3. re-verifies SHA, payload digest and Composer lock digest;
4. transfers the candidate and bounded deploy scripts over the dedicated
   PREPROD SSH identity;
5. launches a detached worker protected by a PREPROD-specific `flock` lock;
6. archives the immutable candidate on the PREPROD host;
7. switches a release only after candidate verification;
8. runs Drupal update/configuration operations;
9. polls terminal deployment evidence;
10. verifies health, protected HTTP smoke and Playwright desktop/mobile;
11. uploads only non-sensitive evidence.

The GitHub repository secrets used by this route are PREPROD-only:

```text
PREPROD_SERVER_HOST
PREPROD_SERVER_USER
PREPROD_SSH_PRIVATE_KEY
PREPROD_BASIC_AUTH_USER
PREPROD_BASIC_AUTH_PASSWORD
```

None may contain or reuse the production SSH, SMTP, OpenAI or database
credentials.

## Drupal deployment sequence

The candidate deploy sequence is:

```text
verify artifact identity
-> archive exact candidate bytes
-> unpack release
-> attach shared settings/files
-> DB backup when an active release exists
-> maintenance ON when applicable
-> initialize fresh independent DB from existing config when needed
-> switch current
-> drush updb -y
-> drush cim -y
-> PREPROD split import
-> Governed Content validate + dry-run + apply when available
-> drush cr
-> maintenance OFF
```

Governed Content is retained because the current governed catalogue is a small,
explicit and idempotent application contract. It is preceded by validation and
dry-run. It is not used to copy ordinary production editorial data.

Failure recovery attempts to leave maintenance mode and keeps the previous
absolute release available. Automatic database rollback is intentionally not
performed after a schema/config mutation; the pre-deploy DB dump is the
operator rollback boundary.

## Environment isolation and side effects

PREPROD is fail-safe by default:

| Capability | PREPROD policy |
| --- | --- |
| Email/Webform | Native mailer is sinked by PHP `sendmail_path=/bin/true`; no real delivery. |
| Analytics | Production Config Split forced OFF; Google Tag default forced empty. |
| OpenAI/Drupal AI | `OPENAI_API_KEY` is not injected; deploy worker also unsets it. |
| Link checker | Current `check_links_types: 0` remains non-active. |
| Automated cron | Interval forced to `0`; no system cron is installed by bootstrap. |
| Queues | No independent worker is enabled during the first PREPROD proof. |
| Webhooks/other APIs | No PREPROD credential is provisioned by the bootstrap. |
| Monitoring | Separate PREPROD monitor identity only after the URL exists. |

The `production` Config Split is always forced OFF. The `preproduction` split is
forced ON from the environment-owned settings and contains the safe mail/cron
state needed by PREPROD.

The dedicated PHP-FPM pool uses `clear_env=yes`. The deployment worker also
explicitly removes known production mail/OpenAI variables before Drupal
operations.

## Data and files

The approved strategy is hybrid. The first infrastructure proof may use a fresh
independent database installed from committed configuration and deterministic
fixtures. This is preferred for #797 because it proves infrastructure without
copying customer or editorial production data.

When production fidelity is required later, the only accepted path is:

```text
one-way PROD snapshot
-> sanitize/anonymize
-> independent PREPROD database
-> deterministic test users/fixtures
```

Runtime access from PREPROD to the production database is forbidden. PREPROD
files use separate storage. Public files are copied only when necessary and
safe. Private files are excluded by default and require a specific sanitized
need before copying.

## Backups and storage retention

The PREPROD contract combines:

- provider-level daily backup or snapshot capability;
- a DB dump before each deployment when an active release exists;
- the three newest unpacked releases locally;
- the ten newest deployment DB dumps locally;
- immutable candidate archives under `shared/artifacts/<sha>/<digest>`.

Provider backups are recovery protection, not a replacement for deployment
rollback evidence. For longer-lived or larger PREPROD data, encrypted off-host
backup can be added as a separate operations tranche.

## Required PREPROD evidence before functional PROD GO

A functional candidate is promotable only when all applicable evidence is tied
to the same candidate SHA and artifact digest:

1. PREPROD deploy succeeded for the exact candidate artifact.
2. Drupal bootstrap and configuration/update sequence succeeded.
3. `/health/live` is direct HTTP 200 with `{"status":"ok"}`.
4. `/health/ready` is direct HTTP 200 with `{"status":"ok"}`.
5. Protected HTTP smoke passes.
6. Playwright desktop and mobile validation passes.
7. External side-effect isolation remains effective.
8. Rollback/redeploy capability is proven for PREPROD.
9. Evidence records candidate SHA and artifact digest.
10. A human explicitly gives GO for functional production promotion.

CI success alone is not a production promotion approval.

## Hotfix and security lane

Urgent fixes start from the current `main` production baseline and do not have
to wait for a functional `release/*` candidate.

After an urgent fix reaches production, every active release candidate must
integrate that fix before it can be considered coherent again. Candidate
evidence generated before reintegration is stale and must not authorize later
production promotion.

## Current and future transitions

Current state after repository bootstrap preparation:

```text
main -> existing automatic PROD deploy remains unchanged
release/* -> immutable candidate build
successful candidate build -> PREPROD deploy/validation route prepared
PREPROD target -> not provisioned yet
PROD promotion of artifact -> not implemented yet
```

After the real PREPROD proof is terminal, the next #758 tranche may implement an
explicit human-GO production promotion route consuming the same SHA and digest,
then retire the legacy functional `main -> PROD` behavior without weakening the
hotfix/security lane.
