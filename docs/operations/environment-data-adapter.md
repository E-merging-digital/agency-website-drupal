# Agency environment-data adapter state

Issue: #1163
Architecture: E-merging-digital/infrastructure#41 / ADR-0005

## Purpose

`agency_operations.environment_data_state` is the read-only Agency adapter state provider for the centralized environment-data cockpit.

It does not expose an HTTP transport yet and it does not authorize or execute a database transfer. It provides the stable non-sensitive state shape that the future central cockpit can consume.

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

## Next step

Infrastructure #43 may add a transport/authentication layer and central UI around this provider. That work must keep the state contract separate from command authority and must not weaken the existing one-shot refresh/seed gates.
