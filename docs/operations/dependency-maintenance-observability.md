# Dependency maintenance observability

Agency publishes a read-only dependency maintenance snapshot from repository-owned
Composer and Node metadata. It is evidence for human/Project Lead maintenance
decisions; it never updates dependencies.

## Contract

- Workflow: `Agency dependency maintenance state`
- Workflow path: `.github/workflows/dependency-maintenance-state.yml`
- Artifact: `agency-dependency-maintenance-state`
- Retention: 14 days
- Schema version: `1`
- Schema source: `scripts/dependency-maintenance/schema-v1.json`
- Repository identity: `repository_sha`
- Collection time: `collected_at_utc`
- Execution capability: always `read_only`

The artifact contains only software/package metadata. It must not contain
credentials, environment secrets, database values, customer data or Recipient
data.

## Health semantics

`OK` means no update or material maintenance finding is visible.
`UPDATE_AVAILABLE` means a newer version exists for human review.
`BLOCKED` means another locked project dependency prevents the latest version.
`SECURITY` means Composer reports an advisory affecting the installed package.
`ABANDONED` means Composer reports the package abandoned.
`UNKNOWN` means the sources cannot support a deterministic conclusion.
`EOL` is reserved for a future authoritative lifecycle source; age alone never
creates an EOL claim.

`support_status` remains `UNKNOWN` unless an authoritative source proves a
support lifecycle state.

## Version semantics

Each component exposes `installed_version`,
`compatible_available_version`, `latest_available_version`,
`update_class`, `health`, and `recommended_action`.

A root pin can require human constraint editing without making the package
`BLOCKED`. `BLOCKED` is reserved for an external project dependency
constraint. For example, Coder 9 remains blocked while `drupal/core-dev`
requires the Coder 8 line.

## Collection sources

The collector delegates to Composer native metadata and audit commands, reads
the exact lockfiles, and uses npm metadata for project-owned Node dependencies.
It does not query Drupal application data and does not invoke Drush.

The workflow runs daily and on relevant dependency/collector changes. It
installs the exact existing Composer lockfile only to provide the project's
already-owned Composer libraries; resolution is never changed.

## Infrastructure #75 consumption

Infrastructure/cockpit can consume the artifact without coupling to Agency
internals. It needs only the workflow name, artifact name, schema version,
`repository_sha`, `collected_at_utc`, `overall`, and component
`health`/action fields.

Infrastructure owns host/runtime observability. Agency owns this project
dependency snapshot. No Infrastructure mutation is performed by this workflow.
