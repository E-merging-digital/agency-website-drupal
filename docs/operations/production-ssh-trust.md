# Pinned production SSH host trust

Issue: #830. Related snapshot authority: #826. Parent program: #816.

## Trust source

The production SSH server identity is established out-of-band from the real production server console, not from the network path used by GitHub Actions.

Source observed on 26 August 2026:

```text
REAL PROD SERVER CONSOLE
ubuntu@emergingdigital
```

Pinned public identity:

```text
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHBzADlwNcSFBP6sYfGBH2SvD5NymTF6n2Ze996V4TqR root@emergingdigital
SHA256:Pflpbgh2vc9dUYe4fpXPxdwzhPqyy8vmbAOcS+BRLDQ
```

The key is public trust material, not a credential. Repository-owned anchors are:

```text
scripts/production-ssh-trust/prod-ed25519.pub
scripts/production-ssh-trust/prod-ed25519.sha256
```

The repository does not version the production host name, SSH user, private key or any credential.

## Prohibited trust bootstrap

The production snapshot route and trust provisioning route must never derive trust from the network. In particular, they do not use network key discovery, first-connect acceptance, disabled strict checking or automatic acceptance of a previously unseen key.

The snapshot transport continues to require `StrictHostKeyChecking=yes`.

## Exact verification contract

`scripts/production-ssh-trust/manage-known-host.sh` is the fixed repository-owned verifier/provisioner. `VERIFY_ONLY` requires all of the following before the #826 snapshot can reach its SSH/database step:

- `SERVER_HOST` resolves to exactly one entry in the runner account's `~/.ssh/known_hosts`;
- the entry is `ssh-ed25519`;
- its public-key blob exactly equals the repository-pinned public key;
- its SHA256 fingerprint exactly equals `SHA256:Pflpbgh2vc9dUYe4fpXPxdwzhPqyy8vmbAOcS+BRLDQ`.

Absence, a different key, a different key type or multiple matching entries is terminal. The verifier does not connect to PROD.

## Trusted runner provisioning

The only authorized provisioning target is:

```text
runner = agency-browser-runner-01
labels = self-hosted, linux, x64, agency
account = agency-runner
```

The governed #830 workflow uses the existing `SERVER_HOST` runtime secret only to form the local known_hosts record. It does not use the production SSH private key, `SERVER_USER`, SSH, SCP, rsync or any network lookup.

Before mutation, the script counts matching entries. The transition is fail-closed:

- zero entries: append exactly one `SERVER_HOST + ssh-ed25519 + pinned blob` record;
- one exact pinned entry: treat provisioning as already satisfied and only normalize safe local permissions;
- one mismatched entry or more than one matching entry: fail without replacing or deleting host trust.

Unrelated known_hosts records are preserved. The managed local permissions are `0700` for `~/.ssh` and `0600` for `~/.ssh/known_hosts`.

## Evidence

Successful trusted-runner verification may publish only non-sensitive metadata:

```text
RUNNER=agency-browser-runner-01
ENTRY_COUNT_FOR_SERVER_HOST=1
KEY_TYPE=ssh-ed25519
KEY_BLOB_MATCH=PASS
FINGERPRINT=SHA256:Pflpbgh2vc9dUYe4fpXPxdwzhPqyy8vmbAOcS+BRLDQ
TRUST_SOURCE=OUT_OF_BAND_PROD_CONSOLE
KNOWN_HOSTS_MATCH=PASS
```

`SERVER_HOST` is deliberately omitted from evidence.

## First failed #826 run

Run `32952334445` failed before the real snapshot operation because the pinned host identity had not yet been provisioned on `agency-browser-runner-01`. The snapshot execution step was skipped; no PROD database bytes were read and independent cleanup proved no raw snapshot remained.

The run also showed a diagnostic limitation: generic `always()` evidence validation/upload steps fail when a pre-snapshot guard stops execution before `evidence.env` exists. This does not weaken the security boundary and is deferred from #830 rather than fabricating snapshot evidence for an operation that never occurred.

## Stop boundary

Provisioning and exact trust proof do not authorize a second #826 snapshot attempt. After #830 is merged and the actual runner proves the exact pinned identity, #826 still requires a fresh human snapshot GO and a fresh snapshot request ID.
