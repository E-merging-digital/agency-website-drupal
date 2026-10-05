# Development Seed reader onboarding

Status: bounded operator capability for long-lived human/agent consumers.

This page extends the Development Seed capability documented by #873/#956. It does not create a new seed store or data path.

## Security model

```text
agent-dev local key
  -> SSH as agency-preprod
  -> authorized_keys restrict + forced command
  -> /var/www/agency-preprod/shared/development-seeds/read-only-scp.sh
  -> current/seed.json
  -> current/database-mariadb_11.8.zst
```

The key cannot obtain a shell, PTY, port forwarding, SFTP/upload mode or arbitrary file access. The forced reader command remains the only data surface.

## Operator workflow

Use `.github/workflows/development-seed-reader-onboard.yml` after merge. Inputs are:

- `action`: `INSTALL`, `VERIFY` or `REMOVE`;
- `label`: stable bounded consumer identity, for example `agent-dev`;
- `public_key`: OpenSSH ed25519 public key only.

The workflow validates public inputs before materializing the existing PREPROD deployment credential, pins the PREPROD host key from the repository and executes only `remote-long-lived-reader.sh` as `agency-preprod`.

Private consumer keys never enter GitHub. The public key may appear in workflow metadata because it is intentionally public material.

## Local consumption

After `VERIFY` succeeds, configure the local SSH client to use the dedicated private key for `preprod.emergingdigital.be` and set:

```text
AGENCY_SEED_SSH_TARGET=agency-preprod@preprod.emergingdigital.be
```

Then use the canonical `scripts/development-seed/use-native-seed.sh fresh` or explicit `reset` route. The existing helper verifies seed metadata and SHA-256 before DDEV native import.

The local host must have the documented prerequisites, including PHP CLI, Git, OpenSSH/SCP and DDEV >= 1.25.4.

## Revocation

Run the same workflow with `REMOVE`, the same `label` and the exact same public key. Removal touches only that exact bounded authorized-keys entry.

```text
PROD_ACCESS = NONE
PREPROD_DB_WRITE = NONE
SEED_UPLOAD = NONE
GENERAL_SHELL = NONE
PULL_ONLY = YES
```
