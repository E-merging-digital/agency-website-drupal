# PREPROD refresh activation capability — #874

## Scope

Issue #874 is the bounded capability/provisioning tranche under #816. It supplies repository-owned source, tests, a root-owned HTTP/runtime fence, deterministic runtime-state identity, and a separately governed provisioning route.

It does **not** authorize a real data refresh or data activation.

## Frozen authority

- Canonical sanitizer: `agency-preprod-staging-sanitizer.py`.
- Canonical policy: `agency-preprod-refresh-v1`.
- Duplicate sanitization authority: absent/forbidden.
- Installed data activation authority: `DISABLED`.
- #874 grants data activation authority: **no**.
- First real data activation requires a separate explicit #816 child/successor authority.

The repository marker `successor-data-activation-authority.json` is descriptive only; it does not enable authority.

## Host capability

Provisioning installs only the bounded capability:

- `/usr/local/sbin/agency-preprod-refresh-control` as `root:root 0755`;
- `/usr/local/lib/agency-preprod-refresh` as the pinned bundle;
- `/var/lib/agency-preprod-refresh` as `root:root 0711`;
- `incoming`, `candidates`, and `backups` as `root:root 0700`;
- `data-activation-authority.json` as exact disabled state, `root:root 0600`;
- sudo policy fixed to the single helper with `NOSETENV`;
- public Nginx fence returning HTTP 503 whenever the root-owned maintenance marker exists;
- loopback-only readiness at `127.0.0.1:18087/health/ready`.

There is no public bypass.

## Locking and activation model

The outer deploy lock remains `/var/www/agency-preprod/shared/deploy.lock`.
The privileged refresh lock is `/run/lock/agency-preprod-refresh.lock`.

The only supported future activation primitive is one internally generated atomic multi-table `RENAME TABLE` over an exact compatible `BASE TABLE` subset. Database rename is forbidden. Views, triggers, events, routines and foreign keys are outside the supported activation subset.

Governed-content APPLY is forbidden by this data-refresh authority.

## Backup and rollback identity

A pre-activation backup is server-local, root-only and never a GitHub artifact. Its identity is the SHA-256 of a canonical deterministic MariaDB logical dump. Rollback is valid only when the restored runtime-state SHA-256 equals the pre-activation digest exactly.

Provisioning itself is transactional: any post-install failure restores the exact prior helper/bundle/state/sudoers/Nginx/vhost state, re-runs `nginx -t`, reloads Nginx, and reports fail-closed recovery failure explicitly.

## Validation

PR exact-head validation executes:

- canonical sanitizer/policy reuse and duplicate-authority absence;
- activation-authority default-disabled and deploy-user negative privilege tests;
- public 503 fence and loopback-only `/health/ready`;
- deploy/refresh lock exclusion;
- `BASE TABLE` subset and atomic rename model;
- deterministic MariaDB **11.8** state A/B proof;
- exact backup → mutation → restore state A/C digest proof;
- provisioning rollback contract;
- #816/#849/#859/#861/#864/#866/#868 regression surfaces;
- no real PROD access and no real PREPROD mutation.

## #874 delivery boundary

For this PR and its CI:

```text
REAL_PLAN = NOT_PERFORMED
REAL_PROD_DATA_READ = NONE
REAL_PROD_DATA_TRANSFER = NONE
REAL_PREPROD_HOST_MUTATION = NONE
REAL_PREPROD_DB_MUTATION = NONE
REAL_PREPROD_PROVISIONING = NOT_PERFORMED
REAL_PREPROD_BACKUP = NOT_PERFORMED
REAL_ACTIVATION = NOT_PERFORMED
REAL_ROLLBACK = NOT_PERFORMED
PROD_WRITE = NONE
MERGE = NOT_PERFORMED
```

Refs: #816, #868, #859, #861, #864, #866, #870, #871.
