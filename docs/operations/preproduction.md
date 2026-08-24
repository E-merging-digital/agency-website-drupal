# Agency PREPROD foundation

Status: repository foundation only. No PREPROD host, DNS, credential, database or deployment target is created by this document or by the release-candidate workflow.

## Purpose

PREPROD is an independent risk barrier between a coherent functional release candidate and production. It must eventually execute the exact application artifact that is approved for production, while keeping data, files, settings, credentials and external side effects isolated from PROD.

## Approved topology

PREPROD infrastructure is distinct from production infrastructure. Small PREPROD environments may later share a dedicated PREPROD host if each site has isolated Unix accounts, release/shared roots, databases and database users, credentials, files, locks, vhosts, TLS identities, cron/queues and resource limits.

PROD and PREPROD for Agency must not share runtime paths, databases, credentials or persistent files.

No provider or server size is selected in this foundation tranche.

## Branch and release model

The durable branch model is:

- `main`: current PROD baseline. The existing automatic production deployment remains unchanged until a later, separately validated promotion cutover.
- `release/*`: coherent functional release candidate intended for PREPROD validation. A release branch starts from the current PROD baseline and receives the feature work selected for that candidate.
- `feature/*`: bounded development branches. Functional work destined for an active candidate is integrated into its `release/*` branch rather than being treated as production-ready merely because CI is green.
- `hotfix/*` and `security/*`: urgent production-baseline lanes. They may be promoted independently of the active functional candidate, then must be reintegrated into every still-active release candidate.

A functional candidate is not permission to deploy production. Production promotion requires an explicit human GO after PREPROD evidence is complete.

## Immutable candidate identity

`.github/workflows/build-release-candidate.yml` runs only on `release/**` pushes and builds the candidate once in production Composer mode.

The uploaded candidate contains:

- `agency-release-candidate.tar.gz`: application payload built from the exact Git SHA with `composer install --no-dev --optimize-autoloader`;
- `agency-release-candidate.tar.gz.sha256`: payload checksum;
- `candidate.json`: non-sensitive provenance containing repository, release branch, exact candidate SHA, Composer lock digest, payload digest, PHP minor and GitHub run identity.

The candidate identity is therefore:

```text
candidate Git SHA
+
application artifact SHA-256
```

A future PREPROD deploy must verify both values before switching a release. A future PROD promotion must consume the same already-proven candidate payload and digest; rebuilding independently at promotion time is not the target design.

The workflow deliberately has:

- no SSH;
- no production or PREPROD deployment target;
- no GitHub deployment secret;
- no Better Stack token;
- no OpenAI key;
- no `settings.php`;
- no public files payload;
- no `.env` payload.

## Candidate contents versus environment state

The immutable application artifact may contain code, committed Drupal configuration, Composer metadata and installed production dependencies. It must not contain environment-owned state.

Environment-owned state remains outside the artifact:

- `settings.php`;
- database credentials;
- SMTP/API credentials;
- public/private uploaded files;
- database content;
- deploy locks and logs;
- runtime caches.

This separation allows the same application payload to be promoted while PREPROD and PROD retain independent state and secrets.

## PREPROD runtime contract

When infrastructure exists, PREPROD must remain compatible with the production-relevant runtime:

- PHP 8.4 and required extensions;
- MariaDB 11.8 with significant application parameters, including `max_allowed_packet=64M`;
- Nginx/PHP-FPM behavior relevant to Drupal;
- release/current/shared deployment pattern with PREPROD-specific paths and locks;
- `drush updb -> drush cim -> PREPROD environment overrides -> drush cr`;
- `/health/live` and `/health/ready` using the same provider-neutral Agency contract as PROD;
- Playwright against the real PREPROD URL through `BROWSER_VALIDATION_BASE_URL`/`PLAYWRIGHT_BASE_URL`.

PREPROD may use smaller resources than PROD only if it can still execute these gates reliably.

## Environment isolation and side effects

The implementation target remains fail-safe by default:

| Capability | PREPROD policy |
| --- | --- |
| Email/Webform | Sink/catcher or dedicated PREPROD SMTP; never production Proton credentials; no real delivery by default. |
| Analytics | Disabled; never send PREPROD traffic into production analytics. |
| OpenAI/Drupal AI | Disabled by default; provider-backed validation requires separate PREPROD credentials, budget and test data. |
| Link checker | Disabled or explicitly bounded until outbound effects are reviewed. |
| Cron/queues | Independent state; enable only after external side effects are neutralized. |
| Webhooks/other APIs | Disabled/sandbox by default unless explicitly approved. |
| Monitoring | Separate PREPROD monitor identity only after a real environment exists. |

The existing `production` Config Split must never be reused as PREPROD configuration. A PREPROD-specific split/override set will be materialized only with the environment implementation, when the exact neutralization settings can be tested against a real target.

## Data and files

The approved strategy is hybrid:

```text
one-way PROD snapshot
-> sanitize/anonymize
-> independent PREPROD database
-> deterministic test users/fixtures
```

Runtime access from PREPROD to the production database is forbidden. Any PROD-derived data or files must be sanitized before use.

PREPROD files use separate storage. Public files are copied only when necessary and safe. Private files are excluded by default and require a specific, sanitized need before copying.

## Required PREPROD evidence before functional PROD GO

A functional candidate is promotable only when all applicable evidence is tied to the same candidate SHA and artifact digest:

1. PREPROD deploy succeeded for the exact candidate artifact.
2. Drupal bootstrap and configuration/update sequence succeeded.
3. `/health/live` is healthy.
4. `/health/ready` is healthy.
5. Bounded HTTP smoke passes.
6. Playwright desktop and mobile validation passes.
7. External side-effect isolation remains effective.
8. Rollback/redeploy capability is proven for PREPROD.
9. Release-candidate evidence records SHA and artifact digest.
10. A human explicitly gives GO for functional production promotion.

CI success alone is not a production promotion approval.

## Hotfix and security lane

Urgent fixes start from the current `main` production baseline and do not have to wait for a functional `release/*` candidate.

After an urgent fix reaches production, every active release candidate must integrate that fix before it can be considered coherent again. Candidate evidence generated before reintegration is stale and must not authorize later production promotion.

## Current and future transitions

Current state after this foundation tranche:

```text
main -> existing automatic PROD deploy remains unchanged
release/* -> immutable candidate build only
PREPROD target -> not provisioned yet
PROD promotion of artifact -> not implemented yet
```

The next bounded #758 implementation slices are:

1. provision/select the distinct PREPROD target and establish its isolated settings/secrets/storage/database;
2. materialize PREPROD-specific Config Split/side-effect neutralization against that real target;
3. deploy the exact candidate artifact to PREPROD with environment-specific release/shared paths and evidence;
4. run health, smoke and Playwright gates against PREPROD;
5. only after those proofs, implement the explicit human-GO production promotion route consuming the same SHA+digest and retire the legacy functional `main -> PROD` behavior without weakening the hotfix/security lane.
