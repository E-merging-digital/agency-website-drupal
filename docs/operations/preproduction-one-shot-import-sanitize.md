# PREPROD one-shot import + sanitization capability

Issue: #859. Parent: #816.

This tranche prepares repository and synthetic-CI contracts only. It does not authorize or perform any real PROD/PREPROD access, helper installation, staging sanitization, runtime database switch or activation.

## Atomic lifecycle

The fixed helper action `IMPORT_SANITIZE_PROVE` accepts only:

`ACTION REQUEST_ID SNAPSHOT_BYTES`

The request identity deterministically derives `agency_preprod_stage_<12hex>` and its ephemeral DB account. The caller cannot supply a database name, SQL command, shell, executable, host, credential or filesystem path.

Within one privileged invocation the lifecycle is:

1. require the derived database/account absent;
2. create only that derived database and restricted account;
3. consume exactly `SNAPSHOT_BYTES` from stdin, bounded by the existing 1 TiB maximum;
4. sanitize with the pinned root-owned byte-identical copy of `agency-preprod-refresh-v1`;
5. assert sanitized state and produce metadata-only hashes/counts;
6. destroy the derived database/account in the mandatory finalizer;
7. require both absent before return.

The finalizer executes after success, broken/incorrect stdin, sanitization failure, assertion failure and handled termination. There is no caller-controlled action boundary where an unsanitized staging DB is intentionally retained.

## Root-owned bundle

A root process must never execute or trust mutable checkout material. The future installed bundle is therefore exactly:

- `/usr/local/sbin/agency-preprod-staging-db` — root:root 0755 — SHA-256 `a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71`;
- `/usr/local/lib/agency-preprod-staging/agency-preprod-staging-sanitizer.py` — root:root 0644 — SHA-256 `fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f`;
- `/usr/local/lib/agency-preprod-staging/sanitization-policy.json` — root:root 0644 — byte-identical copy of `scripts/preproduction-refresh/sanitization-policy.json`, SHA-256 `cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb`.

The authoritative manifest is `scripts/preproduction-staging-import/privileged/bundle.json`.

## Privilege consequence

The sudoers command does not change. It remains `NOPASSWD: NOSETENV` for the single fixed `/usr/local/sbin/agency-preprod-staging-db` path and exposes neither direct `mariadb` nor a generic root shell.

`capability.json` changes because a new fixed action and root-owned bundle contract are added. The #851 provisioning profile is revised to install/rollback the exact bundle in a future separately authorized tranche, but #859 does not execute that provisioning.

## Synthetic proof

The #859 targeted workflow uses an isolated MariaDB 11.8 container and synthetic SQL only. It proves success cleanup, exact-byte failure cleanup, incompatible-schema cleanup, independent-run determinism, unknown mandatory policy fail-closed, runtime DB rejection and absence of generic privileged surfaces. No raw fixture values are uploaded as evidence.
