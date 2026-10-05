# PREPROD runtime DB account transient PRECHECK

Issue #895 materializes a repository route for a future, separately authorized,
single metadata-only observation of the PREPROD runtime MariaDB account. The
issue itself is implementation-only: it does not connect to PREPROD, install the
helper, execute MariaDB queries, install sudoers, or authorize reconciliation.

## Authority split

`#895` is not an execution authority. A future open owner-authored successor
issue must contain exactly one marker:

```text
AGENCY_PREPROD_RUNTIME_DB_PRECHECK_AUTHORITY
parent_issue=816
implementation_issue=895
allowed_action=precheck
```

The owner authorization comment grammar is fixed:

```text
/agency-preprod-runtime-db-precheck issue-<successor>-precheck-<main8>-r<N> <exact-main-sha> agency-preprod-runtime-db-precheck-v1
```

The successor issue must be distinct from #895. The validator requires
`run_attempt=1`, exact current `main`, an owner-authored event, and a request ID
that appears exactly once in the issue comments. There is no action/mode input:
the route is PRECHECK-only by construction.

## Future execution model

After a separately authorized successor command, the workflow will:

1. resolve and checkout exact live `main`;
2. run on `[self-hosted, linux, x64, agency]` and require the already-governed
   `agency-browser-runner-01`;
3. materialize `PREPROD_PROVISIONING_SSH_PRIVATE_KEY` only as a transient `0600`
   file under `RUNNER_TEMP`;
4. construct a transient `known_hosts` from repository-pinned PREPROD host
   material and verify it with the existing fail-closed trust gate;
5. verify the #893 helper source SHA-256 against
   `scripts/preproduction/runtime-db-account/capability.json`;
6. fail before staging if
   `/usr/local/sbin/agency-preprod-runtime-db-account` already exists or is a
   dangling symlink;
7. transfer only the fixed helper, its manifest, and the fixed root wrapper to
   a request-derived root-only temporary directory;
8. atomically link a request-owned root:root `0755` helper into the fixed path,
   without any overwrite operation;
9. invoke only literal `PRECHECK` as root;
10. remove and verify absence of the request-owned helper before metadata is
    emitted;
11. remove the remote request directory;
12. remove the transient root key and trust home on every workflow path; and
13. emit only the five validated metadata fields after terminal cleanup.

If the fixed helper path pre-exists, the route fails closed. It neither replaces
nor deletes that object. Atomic creation uses a no-overwrite hard-link step so a
race that creates the fixed path also fails closed.

## Metadata boundary

The only permitted GitHub evidence is:

```text
TARGET_DATABASE_PRESENT
ACCOUNT_127_PRESENT
ACCOUNT_LOCALHOST_PRESENT
EXPECTED_DB_GRANT
RUNTIME_ACCOUNT_STATE
```

The #893 helper keeps raw MariaDB query output inside the privileged process and
returns only those classifications. The #895 wrapper validates their count,
order and value grammar before they can leave the host.

PRECHECK does not call the helper reconciliation path and does not read
`runtime.env` or `DB_PASSWORD`. The #895 route contains no SQL, MariaDB command,
database/user/host/password input, generic executable input, generic SSH command
input, or caller-controlled filesystem path.

## No sudoers installation

The repository source
`scripts/preproduction/runtime-db-account/agency-preprod-runtime-db-account.sudoers`
is deliberately not part of the #895 execution bundle.

```text
SUDOERS_INSTALL=NONE
DEPLOY_USER_APPLY_AUTHORITY=NONE
```

The transient helper is executed directly through the already-governed root
provisioning SSH identity. No deploy-user privilege is added.

## Preserved authorities

```text
#849=STAGING_ONLY
#891=FAIL_CLOSED
#887=PRESERVED
#889=PRESERVED
#874_AUTHORITY=NOT_WIDENED
#876_AUTHORITY=UNCHANGED
DATA_ACTIVATION_AUTHORITY=DISABLED
```

The #874 workflow and provisioning scripts are not reused as a generic
execution engine. #895 reuses only the established low-level root credential
and pinned host-trust hygiene.

## #895 execution record

```text
REAL_PREPROD_EXECUTION=NONE
REAL_ROOT_PREPROD_SESSION=NONE
REAL_HELPER_INSTALL=NONE
REAL_HELPER_EXECUTION=NONE
REAL_MARIADB_QUERY=NONE
REAL_MARIADB_MUTATION=NONE
REAL_SUDOERS_MUTATION=NONE
REAL_PROD_ACCESS=NONE
r8=NOT_RUN
#876_PLAN=NOT_RUN
#874_APPLY=NOT_RUN
DATA_ACTIVATION_AUTHORITY=DISABLED
```
