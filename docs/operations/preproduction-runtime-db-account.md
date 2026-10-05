# PREPROD runtime MariaDB account contract

Issue #893 fixes an internal repository mismatch in the PREPROD bootstrap and
materializes, without installing it, a separately governable capability for an
already-existing host.

## Canonical runtime identity

The Drupal runtime contract is fixed:

| Field | Value |
| --- | --- |
| Database | `agency_preprod` |
| Database user | `agency_preprod` |
| Database host | `127.0.0.1` |
| Database port | `3306` |
| Protocol | TCP loopback |
| MariaDB account | `agency_preprod@127.0.0.1` |
| Grant scope | `agency_preprod`.* only |

`scripts/preproduction/settings.php.template` remains the authority for the
Drupal TCP-loopback connection. `scripts/preproduction/bootstrap-host.sh`
must create/alter/grant the matching `agency_preprod@127.0.0.1` account. A
fresh bootstrap must not substitute `localhost`, a Unix socket, `%`, a remote
host, a different database, or a different database user.

The previous `agency_preprod@localhost` bootstrap contract was a repository
defect. This diagnosis does not prove what account currently exists on the live
PREPROD host. In particular, the #891 r7 `DRUSH_FAILED` observation remains
fail-closed evidence, not proof of a MariaDB account mismatch.

## Existing-host bridge: design only

Repository source for the bridge lives under:

`/scripts/preproduction/runtime-db-account/`

Its future installed command is fixed to:

`/usr/local/sbin/agency-preprod-runtime-db-account`

The only accepted actions are:

- `PRECHECK`
- `APPLY`
- `VERIFY`

There are no caller-supplied database, user, host, password, SQL, grant, path,
command, or executable parameters.

`PRECHECK` and `VERIFY` expose only bounded metadata:

- `TARGET_DATABASE_PRESENT`
- `ACCOUNT_127_PRESENT`
- `ACCOUNT_LOCALHOST_PRESENT`
- `EXPECTED_DB_GRANT`
- `RUNTIME_ACCOUNT_STATE`

They never output a password, authentication hash, `mysql.user` row, raw grant,
SQL data, `runtime.env`, or `settings.php`.

`APPLY`, if separately authorized and installed in a successor execution, can
only reconcile the fixed `agency_preprod@127.0.0.1` contract. It obtains
`DB_PASSWORD` from the fixed server-owned path
`/var/www/agency-preprod/shared/settings/runtime.env`; the caller cannot pass or
override the secret. The helper requires the runtime state file to be a regular
non-symlink file owned by `agency-preprod:agency-preprod` with mode `0600`, and
accepts only the bootstrap-generated 64-character hexadecimal password shape.

Before granting, the helper removes privileges from the fixed
`agency_preprod@127.0.0.1` account and then grants only
`agency_preprod`.*. If the legacy `agency_preprod@localhost` account is present,
it is removed only after the canonical account has been created/altered and the
fixed grant applied. A `%` account, another account host, a missing runtime
database, or an unobservable/invalid state is `UNSAFE` and fails closed instead
of widening authority.

## Installation boundary

Issue #893 does **not** install or execute the bridge. The repository manifest
pins the intended root-owned helper and sudoers source by SHA-256 and records
their eventual modes:

- helper: `root:root`, `0755`;
- sudoers: `root:root`, `0440`.

The sudoers source permits only the three exact fixed helper commands. It does
not grant direct `mariadb`, shell, SQL, path, or executable authority.

A future installation or `APPLY` requires separate Project Lead authority and
must re-validate the live host before mutation.

## Preserved boundaries

### #849

The #849 privileged MariaDB capability remains staging-only. It derives only
`agency_preprod_stage_<digest>` databases and explicitly refuses the runtime
database `agency_preprod`. #893 neither edits nor widens that capability.

```text
#849_CAN_TARGET_agency_preprod=NO
#849_PRIVILEGE_WIDENING=NONE
```

### #891

The PLAN runtime DB observation remains unprivileged and fail-closed:

```text
OBSERVED + agency_preprod => PASS
OBSERVED + other DB       => FAIL CLOSED
DRUSH_FAILED              => FAIL CLOSED
other unavailable state   => FAIL CLOSED
```

No root/sudo probe or #893 helper execution is added to PLAN. PLAN stays on
`ubuntu-24.04` with PREPROD identity `agency-preprod` and no PLAN mutation.

### #887 / #889 / activation

The existing #887 sudoers file and #889 vhost selector are unchanged. The new
sudoers source is separate and design-only. Data activation authority remains
`DISABLED`.

## #893 real-world execution record

```text
RECONCILIATION_CAPABILITY=DESIGNED_NOT_INSTALLED_NOT_EXECUTED
REAL_PREPROD_SSH_MUTATION=NONE
REAL_MARIADB_MUTATION=NONE
REAL_PROD_ACCESS=NONE
r8=NOT_RUN
PROVISIONING_APPLY=NOT_RUN
DATA_ACTIVATION_AUTHORITY=DISABLED
```
