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

The secret value is never stored in exported Drupal configuration or this repository. PREPROD PHP-FPM uses `clear_env = yes`, so the Drupal side deliberately does not depend on inherited process environment.

Issue #1175 materializes the source/configuration path through a server-owned file:

```text
/etc/agency-preprod/cockpit-state-token
owner = root
reader group = www-data
mode = 0640
```

`scripts/preproduction/settings.php.template` reads that exact file only when it is a regular readable non-symlink. If it is absent, unreadable, empty or weaker than the controller contract, the route remains fail-closed with `503 transport_unavailable`.

Provisioning is an explicit host operation through `scripts/preproduction/provision-cockpit-state-token.sh`. The helper accepts the bearer only through an interactive hidden prompt or stdin, never as a command-line argument, does not generate a secret, does not print the value, and atomically converges the root-owned file. Ordinary PREPROD deployments do not require the bearer to exist.

The cockpit side remains a separate runtime secret boundary. Infrastructure config supplies the same credential through `AGENCY_STATE_TOKEN`; the value must not be copied into Git, tickets, logs or artifacts.

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

Source implementation and CI proof do not equal live PREPROD transport proof. #1174 changes the source Nginx template only; it does not by itself prove the currently running PREPROD vhost. Live convergence must preserve the exact-path bearer pass-through and prove that missing/invalid bearer remains denied.

#1175 owns the Drupal-side runtime materialization path described above. Live credential provisioning remains a separate human-authorized operation: provision one matching credential on PREPROD and the Infrastructure cockpit runtime without exposing its value, then execute one authenticated read-only request. Infrastructure #43 remains fail-closed until that live proof succeeds.
