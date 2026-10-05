# Deterministic PREPROD staging sanitization proof

Issue: #855. Parent programme: #816.

This tranche implements and proves the Agency-specific sanitization contract only against isolated synthetic fixtures. It does not sanitize a real imported database and it does not authorize any PREPROD runtime mutation.

## Authoritative policy

The only sanitization policy is `scripts/preproduction-refresh/sanitization-policy.json`, policy version `agency-preprod-refresh-v1`. The executable `scripts/preproduction-refresh/sanitize-staging-fixture.py` must fail closed unless every `mandatory_sanitization` class has exactly one explicit handler in `sanitization_execution.mandatory_class_handlers`.

A new mandatory sensitive class added to the policy without an explicit handler is therefore a terminal proof failure. #855 intentionally does not implement a generic sensitive-table scanner.

## Synthetic execution model

The proof executable accepts exactly one mode: `PROVE`.

It creates two independent SQLite databases in memory and populates only obviously synthetic sentinels. There is no CLI input for a host, database, username, password, SQL statement, filesystem dump or request ID. The proof has no SSH, MariaDB, network or subprocess route.

The fixture models the Agency/Drupal sensitive surfaces required by #816 when present:

- `users_field_data`: deterministic `preprod-user-<uid>` / `preprod-user+<uid>@example.invalid` identities; password material is blanked; `init` is replaced with the synthetic non-routable address; imported access/login timestamps are reset;
- Webform submission and submission-data tables: cleared;
- sessions: cleared;
- flood/rate-limit state: cleared;
- watchdog/dblog: cleared;
- all existing `cache_` tables in the fixture schema: cleared;
- batch, semaphore and expiring key/value temporary state: cleared;
- queue: cleared wholesale, so unknown externally acting queue names cannot survive;
- cron/update exact state names and announcements/link-checker state prefixes: removed from the Drupal `state` key/value collection;
- imported Key/OpenAI provider configuration surfaces observed in this repository: removed from the synthetic imported configuration state; the current repository OpenAI key definition is independently asserted to use the environment-backed Key provider;
- non-sensitive editorial/config/state sentinels: preserved to prove the sanitizer is not a broad database wipe.

The PREPROD administrator is not created or restored by #855. The policy records that this route remains `PREPROD_SERVER_OWNED` and `restore_in_issue_855=false`.

## Determinism and idempotence

The proof computes a canonical SHA-256 over each complete sanitized synthetic database state without emitting row values:

1. raw fixture A -> sanitize -> `STATE_A_SHA256`;
2. independent raw fixture B -> sanitize -> `STATE_B_SHA256`;
3. sanitized state A -> sanitize again -> `STATE_C_SHA256`.

The gate requires `STATE_A_SHA256 == STATE_B_SHA256 == STATE_C_SHA256`, plus `DETERMINISTIC_PROOF=PASS` and `SECOND_PASS_IDEMPOTENCE=PASS`.

## Evidence boundary

GitHub Actions may receive only the synthetic proof and metadata. The workflow uploads no artifact. Output is limited to policy identity, PASS/FAIL assertions, zero row counts, runtime `NONE` markers and canonical digests. It does not print fixture values.

Required runtime boundary markers remain:

```text
REAL_PROD_ACCESS=NONE
REAL_PROD_DB_READ=NONE
REAL_PROD_SNAPSHOT=NONE
REAL_PREPROD_DB_MUTATION=NONE
REAL_SANITIZATION=NOT_PERFORMED
ACTIVATION=NOT_PERFORMED
PII_OR_SECRET_IN_EVIDENCE=NONE
```

## Relationship to #834 / #849 / #851

#855 does not modify the real #834 staging import helper, privilege contract, provisioning path or consumed request identity. The #834 route remains `sanitization_path=NONE` and `activation_path=NONE`.

The synthetic proof cannot be repointed at `/usr/local/sbin/agency-preprod-staging-db`. If a future real sanitizer requires a new privileged action or staging lifecycle change, Delivery must stop and return the minimum missing capability to Project Lead before any privileged mutation is introduced.

## Stop boundary

A green #855 PR proves a repository-owned deterministic sanitizer contract and synthetic fixture behavior only. It is not evidence that real PREPROD mail, Config Split, GA4, providers, Basic Auth, noindex, Drupal bootstrap, PREPROD administrator restoration, backup, activation or rollback have been validated. Those remain future #816 runtime tranches.
