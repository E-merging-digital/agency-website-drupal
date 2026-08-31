# PREPROD refresh — pre-ingress authority abort (#917)

## Purpose

The fixed PREPROD refresh capability can arm one exact transaction before binary ingress starts. If transport or ingress fails before `SNAPSHOT_READY`, the raw ingress primitive cleans partial/final unbound objects but the transaction authority must also reach a durable terminal state without a generic root delete.

#917 adds exactly one state-machine outcome for that boundary:

```text
ARMED / AWAITING_INGRESS
→ ABORTED / TERMINAL
→ durable transactions/<authority_id>.json
→ authority/active.json absent
```

`ABORTED` is terminal and non-reusable. It is **not** `ROLLED_BACK`: no runtime activation or restoration is claimed.

## Fixed privileged surface

The future provisioned executable is:

```text
/usr/local/sbin/agency-preprod-refresh-authority-abort
```

It is repository-owned, installed `root:root` mode `0750`, and is intentionally absent from the normal `agency-preprod` sudoers. The existing normal sudo surface remains limited to the reviewed control and ingress executables.

The abort executable accepts no command-line arguments. Its complete structured stdin schema is exactly:

```text
successor_issue
request_id
main_sha
profile_id
authority_id
```

Caller-selected paths, filenames, SQL, database/table identities, state names, executables and commands are rejected.

## Exact safety preconditions

Abort is permitted only for an exact active authority with:

```text
state = ARMED
phase = AWAITING_INGRESS
terminal = false
human_recovery_required = false
```

The fixed helper derives all transaction paths from the bound `authority_id` and requires absence of the final spool, final manifest, partial spool, temporary manifest, candidate seal and backup. The HTTP fence marker must also be absent. Preactivation runtime/release and backup metadata must all remain null.

Any missing proof, unexpected object, state corruption or identity mismatch causes a fail-closed return and preserves the active authority unchanged.

## Crash-safe terminalization

The existing authority lock `/run/lock/agency-preprod-refresh-authority.lock` serializes install, ingress, control and abort operations.

Successful terminalization is ordered deliberately:

1. atomically replace `active.json` with the exact same binding in `ABORTED / TERMINAL` and fsync the authority directory;
2. create and fsync the exact root-only terminal history object;
3. re-read and compare active/history identities;
4. unlink `active.json` and fsync the authority directory.

If execution stops after step 1 or 2, ingress/control already fail closed because active is terminal. A later invocation of the same fixed helper can only finish the exact same bound authority. It cannot alter the binding, resurrect state or install a second authority.

Once terminal history exists and active state is absent, a second abort fails closed. The authority installer also refuses reinstalling the same consumed authority identity.

## Ingress failure composition

The production-shaped failure path is therefore:

```text
ARM exact one-shot authority
→ failing fixed binary ingress
→ prove raw/partial cleanup
→ fixed root-only pre-ingress abort
→ prove ABORTED history
→ prove active authority absent
→ replay/reinstall fail closed
```

Synthetic tests use this production primitive for ingress-failure terminalization. Direct deletion of `authority/active.json` is reserved only for final teardown of an isolated GitHub-hosted fixture tree, never as production architecture.

## Provisioning and execution boundary

Repository source availability is not live capability state. A future separately authorized capability-provisioning event may install the abort helper, while leaving:

```text
PERSISTENT_DATA_ACTIVATION_AUTHORITY = DISABLED
TRANSACTION_AUTHORITY = ABSENT
ABORT_HELPER = INSTALLED_ROOT_ONLY
NORMAL_SUDO_EXPOSURE = NONE
REAL_DATA_ACTIVATION = FORBIDDEN
```

#917 does not authorize or perform real provisioning, PLAN, APPLY, PROD/PREPROD SSH, data movement, ingress, authority installation, abort, database mutation, activation or rollback.

The #910 JIT-before-secret ordering remains unchanged for any later authorized runtime workflow.
