# Successor provisioning execution authority — #877

## Purpose

Issue #874 remains the immutable implementation/capability lineage for
`agency-preprod-refresh-capability-provision-v1`. It is not a live execution
authority after completion.

The provisioning workflow accepts execution authority only from the **event
issue itself**, provided that issue is a fresh owner-created OPEN successor
under #816 and carries exactly one machine-readable marker.

This routing change does not widen helper, sudo, Nginx, database or data
activation authority.

## Exact authority marker

A PLAN authority issue must contain exactly one contiguous block:

```text
AGENCY_PREPROD_REFRESH_CAPABILITY_PROVISION_AUTHORITY
parent_issue=816
implementation_issue=874
allowed_mode=plan
```

A later capability-only APPLY authority issue uses the same block with:

```text
allowed_mode=apply
```

The parser requires exactly one complete marker block. Wrong parent,
implementation lineage, mode, missing marker or duplicate marker fails closed.

Issue #874 itself is explicitly forbidden as live execution authority even if
it were accidentally reopened.

## Exact command grammar

The owner authorization comment is a single line:

```text
/agency-preprod-refresh-capability-provision <mode> <request_id> <main_sha> agency-preprod-refresh-capability-provision-v1
```

No additional token is accepted.

`mode` must be exactly the issue marker's `allowed_mode`.

`main_sha` must be the live `main` SHA observed by the workflow.

The request ID is bound to authority issue, mode and live main prefix:

```text
issue-<authority_issue>-<mode>-<main_sha_8>-r<positive_revision>
```

Example shape for #876 after #877 is merged and the then-current main SHA is
known:

```text
issue-876-plan-<main_sha_8>-r1
```

The exact owner-authored authorization comment must occur exactly once on that
authority issue. Workflow reruns (`GITHUB_RUN_ATTEMPT != 1`) are rejected.

## #876 materialization boundary

#876 is compatible with this contract as the first successor PLAN authority,
but #877 does **not** execute or authorize its real PLAN.

After #877 merges and post-merge main is green, Project Lead may materialize
the exact PLAN marker on #876 and publish one fresh authorization comment bound
to that new live main SHA.

## Frozen separation

```text
IMPLEMENTATION / CAPABILITY LINEAGE = #874
LIVE EXECUTION AUTHORITY            = fresh successor event issue
#874 LIVE EXECUTION AUTHORITY       = FORBIDDEN
DATA_ACTIVATION_AUTHORITY           = DISABLED
```

Capability provisioning authority is not data activation authority.

#877 performs no real PLAN, provisioning APPLY, PREPROD host mutation, PROD
access, PREPROD database mutation, activation or rollback.

Refs: #816, #874, #875, #876, #877.
