# PREPROD staging sanitization — ephemeral MariaDB proof

Issue: #857. Parent: #816.

This tranche bridges the #855 in-memory fixture proof to MariaDB semantics while remaining repository/CI-only. It does not authorize or implement any real PROD or PREPROD database operation.

## Authority

The sole sanitization policy remains:

`scripts/preproduction-refresh/sanitization-policy.json` / `agency-preprod-refresh-v1`.

The #857 proof consumes the same mandatory class handlers and mappings. It does not introduce a second sanitization policy or a second business-domain model.

## MariaDB model

The repository DDEV contract uses MariaDB 11.8, therefore the targeted proof runs against an ephemeral `mariadb:11.8` service on GitHub-hosted CI with synthetic-only fixture data.

GitHub-hosted execution is allowed here only because no PROD/PREPROD data is present. The existing rule remains unchanged: raw PROD data on GitHub-hosted infrastructure is forbidden.

The proof executable:

- accepts only `PROVE`;
- accepts no host, DB name, SQL, shell, executable, credential, filesystem path or request ID from the caller;
- derives `agency_preprod_stage_<12 hex>` database names internally from fixed proof identities;
- rejects `agency_preprod` and any non-derived target before SQL execution;
- constructs all MariaDB statements internally from repository-owned policy mappings;
- emits only counts, PASS/FAIL states, policy identity/digest and non-sensitive state digests;
- removes every ephemeral proof database before successful evidence is emitted.

## Fail-closed schema contract

`users_field_data` is required for this Drupal sanitization proof. If it is absent, or if any required user/auth column is missing, sanitization fails closed.

Other project-sensitive surfaces are explicitly optional-if-absent in the policy because their tables can depend on enabled modules/runtime state. When present, they are handled only through the versioned policy mappings. This is not generic table scanning.

The proof contains a deliberately incompatible synthetic `users_field_data` schema and requires rejection before the run can pass.

## Required proof

The MariaDB proof demonstrates:

- deterministic `preprod-user-<uid>` and `preprod-user+<uid>@example.invalid` user identities;
- imported password/init/login/access material invalidated;
- Webform submission/data purge;
- sessions, flood, watchdog/dblog, cache, batch/temp and queue purge;
- cron/update/announcements/link-checker imported state reset;
- credential-like config removal according to the policy;
- non-sensitive editorial/config/state fixture preservation;
- identical canonical sanitized digests from two independently created MariaDB fixtures;
- unchanged digest after a second sanitization pass;
- unknown mandatory policy class rejection;
- incompatible known sensitive schema rejection;
- runtime DB target rejection.

## #834 / #849 / #851 boundary

#857 does not modify the privileged helper, helper digest, capability JSON, sudoers contract or provisioning profile.

The currently installed/repository helper remains limited to:

`PRECHECK`, `IMPORT`, `CLEANUP`, `VERIFY_ABSENCE`.

No real `SANITIZE` action is added in this tranche because MariaDB compatibility can be proven without widening runtime privilege. A future real runtime sanitization tranche may still decide that a fixed helper action is required, but that would require a new reviewed helper digest and governed provisioning decision before any server mutation.

## Explicit non-authority

```text
REAL_PROD_ACCESS=NONE
REAL_PROD_DB_READ=NONE
REAL_PROD_SNAPSHOT=NONE
REAL_PROD_TO_PREPROD_TRANSFER=NONE
REAL_PREPROD_DB_MUTATION=NONE
REAL_STAGING_SANITIZATION=NOT_PERFORMED
REAL_HELPER_PROVISIONING=NOT_PERFORMED
PREPROD_RUNTIME_DB_SWITCH=NONE
ACTIVATION=NOT_PERFORMED
```

A successful #857 CI proof is a repository contract proof, not real PREPROD runtime evidence.
