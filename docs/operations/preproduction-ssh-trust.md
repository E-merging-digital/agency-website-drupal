# PREPROD SSH host trust

Issue: #836. Parent: #816. Blocked successor: #834.

## Trust authority

The Agency PREPROD SSH host identity is pinned from evidence obtained out of band from the real PREPROD server console on 2026-08-26. The network path being authenticated is not an authority for this identity.

The bounded console commands used to expose only the public host identity were:

```bash
sudo cat /etc/ssh/ssh_host_ed25519_key.pub
sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256
```

The corresponding private key `/etc/ssh/ssh_host_ed25519_key` must never be read, copied, logged, committed or published.

## Canonical public identity

Key type:

```text
ssh-ed25519
```

Public host key:

```text
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGh7BlJYlVE0rWVDBbzvV8alYpn+1ue1WnplrhavORWA root@agency-preprod-01
```

SHA-256 fingerprint:

```text
SHA256:eVHEHd6Xakhu6ZCeGCNzhCO62aSR81bq8E4j34Z1nhU
```

Repository trust material:

- `scripts/preproduction-ssh-trust/preprod-ed25519.pub`
- `scripts/preproduction-ssh-trust/preprod-ed25519.sha256`
- `scripts/preproduction-ssh-trust/manage-known-host.sh`

The public-key fingerprint is recomputed with `ssh-keygen -lf ... -E sha256` and must exactly match the repository-pinned fingerprint before provisioning or verification can succeed.

## Trusted runner provisioning

`.github/workflows/preprod-ssh-trust-provision.yml` is restricted to issue #836, a fresh owner-authored request, the exact live `main` SHA and the exact Agency runner `agency-browser-runner-01` with labels `self-hosted`, `linux`, `x64`, `agency`.

Provisioning accepts no remote command, SSH username, credential, host key or fingerprint from the authority comment. The host name comes from the existing `PREPROD_SERVER_HOST` secret; the identity comes only from the repository-pinned public files above.

`manage-known-host.sh PROVISION` preserves unrelated entries, refuses ambiguity, and is idempotent. `VERIFY_ONLY` requires exactly one matching entry for `PREPROD_SERVER_HOST`, key type `ssh-ed25519`, exact key-blob equality and exact SHA-256 fingerprint equality.

The following are forbidden as trust authority or fallback:

- `ssh-keyscan`;
- TOFU / first-connect acceptance;
- `accept-new`;
- `StrictHostKeyChecking=no`;
- any network-derived host identity.

## #834 boundary

#836 only removes the missing PREPROD host-trust blocker. The #834 operation profile remains independently human-gated:

```text
preprod_trust.current_state = PINNED
authority.first_real_apply = HUMAN_REQUIRED_AFTER_PROJECT_LEAD_REVIEW
```

The #836 provisioning workflow must never emit, manufacture or treat an `/agency-preprod-staging-import apply ...` comment as authorized. A future real #834 APPLY requires a separate fresh human authority after Project Lead review.

Trust establishment does not authorize `/agency-preprod-staging-import apply ...` and does not authorize a PROD snapshot, PROD DB read, PROD→PREPROD transfer, PREPROD staging DB creation/import, sanitization, activation, deployment, file synchronization or any PROD/PREPROD database mutation.
