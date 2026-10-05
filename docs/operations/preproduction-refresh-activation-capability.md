# PREPROD refresh activation capability — #874, revision #915

## Scope

Issue #915 completes the repository-owned fixed capability originally introduced by #874 under parent #816. This revision remains **implementation + static/synthetic/ephemeral validation only**. It does not provision PREPROD, read PROD, transfer a snapshot, mutate PREPROD, activate data, execute rollback, or merge.

The persistent provisioned authority remains `DISABLED`. Provisioning the capability is not execution authority.

## Canonical predecessors reused

The revision deliberately reuses rather than duplicates:

- the canonical `agency-preprod-refresh-v1` sanitization policy;
- `agency-preprod-staging-sanitizer.py` for sensitive-data sanitization;
- `side_effect_hardening.py` for deterministic PREPROD side-effect hardening;
- `runtime_state_digest.py` for deterministic PREPROD backup/state identity and exact restore;
- the #868 atomic base-table swap model;
- the root-owned Nginx HTTP/runtime fence and loopback readiness route;
- the canonical active PREPROD release and server-owned settings;
- the #910 JIT-before-secret orchestration principle.

There is no second sanitizer, second backup engine, generic root shell, generic SQL path or generic remote command.

## One-shot transaction authority

A real future refresh transaction requires an independently installed root-owned authority envelope. The normal `agency-preprod` identity cannot install it and the authority installer is intentionally absent from `agency-preprod` sudoers.

The authority binds exactly:

- successor issue;
- request ID;
- exact main SHA;
- `agency-preprod-refresh-capability-v1` profile;
- the fixed action sequence (`IMPORT_SANITIZE_HARDEN_RETAIN`, `BACKUP_ACTIVATE_CONVERGE_VALIDATE`, `ROLLBACK_RECORDED`);
- expected snapshot byte count and SHA-256;
- one SHA-256 authority identity;
- current transaction state and phase.

Conceptual lifecycle:

```text
persistent capability authority = DISABLED
transaction authority absent
  -> ARMED / AWAITING_INGRESS
  -> IN_PROGRESS / phase-specific state
  -> COMMITTED | ROLLED_BACK | FAILED_RECOVERY
  -> terminal history / non-reusable identity
```

There is no generic or permanent `ENABLED` state. Wrong issue/request/main/profile/action/phase, state corruption, active transaction collision, replay and terminal reuse fail closed.

## Fixed binary ingress

The only future root-owned raw snapshot ingress is `/usr/local/sbin/agency-preprod-refresh-ingress`. It accepts no command-line arguments. Its stdin framing is one bounded JSON header line followed by exactly the authority-bound raw byte count and EOF.

The caller cannot choose a filesystem path or filename. The final raw object is internally derived as:

```text
/var/lib/agency-preprod-refresh/incoming/<authority_id>.sql
```

Required invariants are enforced by the executable:

- `root:root`;
- `0600`;
- regular file only;
- link count exactly one;
- symlink/hardlink/type confusion refused;
- fixed 1 TiB maximum;
- exact authority-bound byte count;
- exact authority-bound SHA-256;
- same-directory atomic rename after verification;
- partial/hash/byte/framing failure cleanup;
- no raw bytes in stdout/stderr/evidence;
- raw spool and manifest removed when candidate sealing terminates.

The ingress sudo rule grants only that fixed executable and `NOSETENV`. It grants no path, shell, Python, MariaDB, environment or arbitrary command capability.

## Fixed PREPROD admin reconciliation

A PROD-derived candidate is never assumed to contain the PREPROD administrative account.

The fixed reconciliation step uses only:

```text
account name = preprod-admin
role         = administrator
password     = /var/www/agency-preprod/shared/settings/runtime.env:DRUPAL_ADMIN_PASSWORD
```

`DRUPAL_ADMIN_PASSWORD` is the existing server-generated PREPROD-owned secret. The fixed wrapper sources it from `runtime.env`, passes it only in the root process environment to a fixed Drupal script, and suppresses script output. The caller cannot choose username, role or password. No PROD credential is reused.

If `preprod-admin` is absent it is created/activated and given the administrator role. If present it is activated, its password is reconciled to the server-owned PREPROD secret, and the administrator role is ensured. The script re-reads the account and fails closed unless the fixed identity is active with required administrative access.

## Fixed Drupal convergence

After the atomic candidate swap, and while the public fence remains closed, the helper runs only fixed repository-owned operations in this order:

1. `updatedb`;
2. canonical config import;
3. PREPROD split partial import;
4. fixed `preprod-admin` reconciliation;
5. governed-content validation;
6. governed-content dry-run;
7. cache rebuild;
8. canonical PREPROD runtime side-effect validation;
9. loopback `/health/ready` validation.

Governed-content APPLY is deliberately **not** performed by data-refresh authority.

The active application release does not change. PREPROD settings and hash salt remain server-owned and preserved.

## Phase-aware terminal rollback

Before closing the fence the helper captures the deterministic PREPROD runtime state SHA-256 and an immutable identity hash of the active release target. After the fence closes it drains runtime DB sessions and creates/verifies the exact preactivation backup before swap.

Rollback selection is deterministic from the recorded transaction phase:

- `FENCE_CLOSED`, `BACKUP_VERIFIED`, `SWAP_ATTEMPTED`: prove the previous runtime digest unchanged, using the exact backup if a verified backup is available and required;
- `SWAPPED`: reverse the atomic table swap, with exact verified backup fallback;
- `CONVERGENCE_STARTED` or later: restore the exact verified preactivation backup.

Rollback success requires all of the following before the fence can reopen:

- runtime state SHA-256 equals the preactivation SHA-256;
- active application release identity is unchanged;
- canonical PREPROD runtime invariants pass;
- fixed `preprod-admin` administrative route exists.

A fresh PROD snapshot is never rollback.

If rollback or its verification fails, the fence stays closed, the authority becomes `FAILED_RECOVERY`, `HUMAN_RECOVERY_REQUIRED=true`, and root-only recovery metadata retains transaction identity, preactivation runtime digest, release identity, backup SHA-256/size and derived backup identity.

## Privilege surface

`agency-preprod` may invoke only the fixed root-owned executables:

```text
/usr/local/sbin/agency-preprod-refresh-control
/usr/local/sbin/agency-preprod-refresh-ingress
```

The independent authority installer is `root:root 0750` and **not** in that sudo surface.

Forbidden permanently:

```text
NOPASSWD: ALL
generic shell/bash sudo
generic Python sudo
generic MariaDB sudo
generic env sudo
caller path/filename
caller SQL/database/table
caller executable/arbitrary command
caller authority installation
```

## Provisioning revision

Future #874-lineage provisioning installs the exact #915-reviewed helper, ingress, authority installer, shared transaction contract, PREPROD admin reconciliation scripts, unchanged sanitizer/hardening/backup primitives, both exact sudo rules, and the existing Nginx fence/readiness integration.

After provisioning:

```text
PERSISTENT_DATA_ACTIVATION_AUTHORITY = DISABLED
TRANSACTION_AUTHORITY                = ABSENT
REAL_DATA_ACTIVATION                 = FORBIDDEN
```

The provisioning APPLY remains behind the existing #910 checkout -> JIT live-main/authority revalidation -> root-secret materialization -> fixed APPLY -> cleanup ordering. #915 does not move secret consumption before the JIT gate.

## #915 validation boundary

PR validation may use GitHub-hosted ephemeral root filesystem/MariaDB fixtures only. It must never use PREPROD/PROD SSH credentials or contact PREPROD/PROD.

```text
REAL_PROVISIONING        = NONE
REAL_PLAN                = NONE
REAL_PROD_READ           = NONE
REAL_PROD_TRANSFER       = NONE
REAL_PREPROD_EXECUTION   = NONE
REAL_PREPROD_MUTATION    = NONE
DATA_ACTIVATION_AUTHORITY = DISABLED
MERGE                    = NOT_PERFORMED
```

Refs: #816 #834 #849 #855 #857 #859 #866 #868 #874 #876 #902 #905 #910 #914 #915.
