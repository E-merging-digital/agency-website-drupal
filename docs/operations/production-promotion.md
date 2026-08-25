# Governed production promotion

This document complements `docs/operations/preproduction.md` for the #812
cutover.

## Functional lane

A functional release is promoted only by the exact immutable candidate already
built and proven in PREPROD. The control surface is a newly created owner-authored
comment on an OPEN owner-created issue:

```text
/agency-production-promote go sha=<candidate-sha> artifact=<artifact-sha256> composer=<composer-lock-sha256> build=<build-run-id> preprod=<preprod-run-id>
```

The comment is the explicit human GO. The workflow hashes the exact body and
binds the GitHub comment ID to the detached server request and final receipt.
No `workflow_dispatch` or ordinary `push` can enter the functional lane.

The route is fail-closed unless the builder and PREPROD runs are both terminal
SUCCESS and their downloadable artifacts prove the exact same SHA and digests.
It also rejects a candidate when live `main` contains later functional changes;
only the bounded #812 deployment-tooling cutover delta is tolerated for the
first r7 promotion.

## Same bytes

`scripts/production-promotion/` receives the already-existing release archive.
It verifies `candidate.json`, the payload checksum and the extracted
`composer.lock` digest. It never runs `git clone`, `git pull` or
`composer install`.

Production activation preserves the existing operational boundary:

```text
shared settings/files
-> active Drupal/DB preflight
-> pre-promotion DB backup
-> maintenance ON
-> exact release switch
-> updb
-> cim
-> production Config Split
-> Governed Content validate/dry-run/apply
-> cr
-> maintenance OFF
-> durable promotion receipt
```

The common `/var/www/agency/shared/deploy.lock` serializes this route with the
explicit hotfix/security lane.

## Anti-replay

A successful exact candidate/artifact pair writes a durable receipt under:

```text
/var/www/agency/shared/promotions/<candidate-sha>-<artifact-sha256>.env
```

The server refuses another activation when that receipt already exists. A GitHub
workflow rerun therefore cannot silently double-promote the same successful
identity.

## Post-deploy proof

The GitHub workflow requires terminal success for:

- `/health/live`;
- `/health/ready`;
- canonical `/fr/blog` smoke;
- Playwright desktop/mobile;
- browser result/visual gates.

The final GitHub issue receipt records only non-sensitive identity/run data.

## Rollback boundary

The promotion evidence records the previous absolute release and the
pre-promotion SQL dump. The code rollback boundary is the previous release; the
database recovery boundary is that backup.

No automatic DB rollback is claimed after `updb`, configuration import or
Governed Content mutation. A DB restore is an explicit operator decision based
on the failure phase and captured evidence.

## Emergency hotfix/security lane

`.github/workflows/deploy-production.yml` is retained only as an explicit
`workflow_dispatch` fallback and fails closed unless the selected ref is
`hotfix/*` or `security/*`. It has no `push main` trigger.

Urgent releases must later be reintegrated into every active functional
candidate; prior PREPROD evidence becomes stale until that reintegration is
rebuilt and revalidated.
