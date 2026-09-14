# Agency environment-data adapter state

Issues: #1163, #1167  
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

The secret value is never stored in exported Drupal configuration or this repository. PREPROD `settings.php` should map an environment-owned secret into:

```php
$settings['agency_operations_cockpit_state_token'] = getenv('AGENCY_COCKPIT_STATE_TOKEN') ?: '';
```

Do not commit the environment variable value or paste it into tickets/logs.

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

Source implementation and CI proof do not equal live PREPROD transport proof. #1167 must not be considered operational until the route and credential are deliberately configured/deployed on PREPROD and an authenticated read is proven without exposing the secret.

Infrastructure #43 remains fail-closed until that live proof exists.
