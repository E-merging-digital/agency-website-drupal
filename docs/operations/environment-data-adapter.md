# Agency environment-data adapter state

Issues: #1163, #1167, #1174, #1175
Architecture: E-merging-digital/infrastructure#41 / #43 / ADR-0005 / ADR-0006

## Purpose

`agency_operations.environment_data_state` is the read-only Agency adapter state provider for the broader **Agency Operations Control Plane**. Environment data is the first operational domain; this provider is the first real Drupal/project adapter feeding that shared project-oriented model.

The provider itself does not authorize or execute a database transfer. It provides the stable non-sensitive state shape consumed by the central cockpit alongside monitoring, backup/recovery and deployment evidence.

## Sources of truth

The provider reuses existing Agency state only:

- current Drupal runtime metadata from `RuntimeMetadataReader`;
- existing capability registry from `CapabilityRegistryReader`;
- on PREPROD, terminal refresh results from `shared/refresh-jobs/*/result.env`;
- on PREPROD, immutable current Development Seed metadata from `shared/development-seeds/current/seed.json`;
- current repository PREPROD sanitization policy identity for configuration visibility only.

It never queries PROD or PREPROD application database contents.

## Normalized state

Top-level identity:

```text
schema_version
project_id = agency-website
application_type = drupal
adapter.id = agency-drupal-environment-data
adapter.version
runtime
environments
preprod
development
capabilities
```

PREPROD projection includes, when proven locally:

```text
refresh_id
last_refresh_completed_at
main_sha
latest_receipt_ref
```

The historical/current `result.env` contract does not bind the source PROD release or the exact sanitization policy version used by that historical refresh. Those fields are therefore not inferred. The currently configured PREPROD policy version may be displayed separately with `bound_to_current_refresh = false`.

Development Seed projection uses the immutable `seed.json` contract and may expose:

```text
last_seed_published_at
seed_id
database_sha256
source_preprod_refresh
source_preprod_release
sanitization_policy
compatibility
latest_receipt_ref
```

The seed is `current` only when its `source_preprod_refresh` equals the current committed PREPROD refresh identity. Otherwise it is `stale`.

## Machine transport

Issue #1167 adds one read-only route:

```text
GET /api/agency-operations/v1/environment-data-state
Authorization: Bearer <secret>
```

The route returns the existing normalized provider projection only when:

1. a non-exportable bearer credential of at least 32 characters is configured;
2. the presented credential matches using constant-time comparison;
3. the current Drupal runtime is PREPROD.

The secret value is never stored in exported Drupal configuration or this repository. PREPROD reads it from the existing server-owned shared settings area:

```text
/var/www/agency-preprod/shared/settings/cockpit-state-token
```

The file is optional until deliberately provisioned. It must be a regular, non-symlink file containing one credential of at least 32 characters with no whitespace. Missing, unreadable, symlinked or invalid content resolves to an empty Drupal setting, so the controller returns `503 transport_unavailable`.

This deliberately avoids PHP-FPM environment inheritance; `clear_env = yes` remains unchanged. Do not commit the credential value or paste it into tickets/logs. The cockpit-side copy remains runtime configuration under Infrastructure #43 as `AGENCY_STATE_TOKEN`; provisioning the two matching values is a separate live operation.

PREPROD Nginx keeps site-wide Basic Auth for browser-facing routes. The cockpit machine route is an exact-path exception to that outer layer only:

```text
location = /api/agency-operations/v1/environment-data-state
outer Nginx Basic Auth = off for this exact path
Authorization bearer = forwarded to Drupal
Drupal bearer validation = mandatory
```

This exact exception is required because HTTP Basic and Bearer both use the `Authorization` request header. It does not make the route unauthenticated: the existing Drupal controller remains the authentication boundary and denies missing/invalid bearer credentials.

Responses are `Cache-Control: no-store, private`. Missing/weak configuration fails with `503`; invalid credentials with `401`; an authenticated non-PREPROD runtime responds `404`.

The route is a read transport only. Its presence does not grant refresh, seed publication or any other mutation authority.

## Fail-closed behavior

Malformed, unreadable, symlink-escaped or unsupported evidence is reported as `unavailable`; it is never guessed from prose or historical ticket numbers.

A latest unresolved `HUMAN_RECOVERY_REQUIRED` refresh state is surfaced explicitly and no older committed state is presented as current.

## Data boundary

```text
RAW_PROD_READ = NONE
RAW_PREPROD_DB_READ = NONE
DATABASE_QUERY = NONE
DATABASE_MUTATION = NONE
SECRET_OUTPUT = NONE
SANITIZATION_IMPLEMENTATION = EXISTING AGENCY ONLY
```

Semantic personal-data sanitization remains owned by the existing Agency Drupal/Drush/PHP policies and assertions. The central cockpit receives only metadata, versions, checksums, lineage and terminal state.

## Deployment status

Source implementation and CI proof do not equal live PREPROD transport proof. #1174 changes the source Nginx template only; it does not mutate the currently running PREPROD vhost. Live convergence requires separately authorized server work: back up the active vhost, apply only the exact-path rule, run `nginx -t`, reload Nginx, and prove that missing/invalid bearer remains denied.

#1175 provides the source/runtime contract for the PREPROD bearer without provisioning a value. Infrastructure #43 remains fail-closed until the exact Nginx rule is converged live, the same bearer is provisioned into the PREPROD secret file and cockpit `AGENCY_STATE_TOKEN`, and an authenticated cockpit request succeeds without exposing the credential.
