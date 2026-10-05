# Temporary PREPROD operator SSH access — #899

Issue #899 is repository implementation only. It defines a future, separately
authorized, non-root route that can add one pinned temporary operator public key
for `agency-preprod`. Delivery under #899 does not execute the route.

## Fixed target

```text
SSH_USER=agency-preprod
AUTHORIZED_KEYS=/home/agency-preprod/.ssh/authorized_keys
TEMP_PUBLIC_KEY=ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIArdJ26K9/VGRXKED9m9/dji80VjuY+0NTC9ANRV25fP agency-preprod-temp-2026-08-31
TEMP_KEY_FINGERPRINT=SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg
ACTION=ADD
```

The corresponding private key remains exclusively on the temporary operator
machine. It is not a GitHub secret and must never be requested, copied, logged,
persisted, reconstructed, or uploaded.

## Future authority

`#899` cannot authorize its own execution. A later owner-created successor issue
must contain exactly:

```text
AGENCY_PREPROD_TEMP_KEY_ADD_AUTHORITY
parent_issue=816
implementation_issue=899
allowed_action=add
```

The one-shot owner comment grammar is:

```text
/agency-preprod-temp-key-add issue-<successor>-add-<main8>-r<N> <exact-main-sha> agency-preprod-temp-key-add-v1
```

The route revalidates live `main` before materializing `PREPROD_SSH_PRIVATE_KEY`.

## Security behavior

The future job runs on GitHub-hosted `ubuntu-24.04`, reuses repository-pinned
PREPROD host trust, and connects only as `agency-preprod`. It never uses root,
sudo, the provisioning root secret, MariaDB, PROD, or a caller-supplied user,
path, public key, action, or remote command.

The remote script has no arguments. Before mutation it requires `.ssh` to be an
owned non-symlink directory with mode `0700` and `authorized_keys` to be an
owned non-symlink regular file with mode `0600` and a single link. Unexpected
or missing state is fail-closed; #899 does not repair unrelated host state.

The file is opened without truncation, locked, and read under a bounded size.
The script appends only the exact pinned line when absent. It refuses duplicate
pinned lines and refuses a non-empty file without a terminal newline rather than
altering unrelated bytes. A second ADD is byte-for-byte idempotent.

Only bounded metadata is allowed to leave the route:

```text
REQUEST_ID
MAIN_SHA
SSH_USER=agency-preprod
TEMP_KEY_FINGERPRINT=SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg
HOST_TRUST=PASS
KEY_PRESENT_BEFORE=YES|NO
KEY_PRESENT_AFTER=YES
RESULT=PASS
```

No unrelated authorized-key line is printed.

## Removal

REMOVE is intentionally **not implemented** in #899. Adding a second mutation
mode would broaden this recovery tranche. Exact-key cleanup should be authorized
and implemented by a separate bounded successor issue after normal access is
recovered.

## Delivery record

```text
REAL_PREPROD_KEY_ADD=NOT_EXECUTED
REAL_PREPROD_MUTATION=NONE
ROOT_SECRET=FORBIDDEN
SUDO=FORBIDDEN
MARIADB=FORBIDDEN
PROD_ACCESS=NONE
#898_PRECHECK=NOT_RUN
#876_PLAN_R8=NOT_RUN
DATA_ACTIVATION_AUTHORITY=DISABLED
```
