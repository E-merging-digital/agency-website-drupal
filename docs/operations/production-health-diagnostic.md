# Production health diagnostic

Status: **INCIDENT-BOUND / READ-ONLY**  
Owner: #590

## Purpose

This route diagnoses the persistent public HTTP 503 blocking the final Browser
Validation of Article #401. It is deliberately separate from deployment,
editorial mutation and the public Playwright proof.

Control command, restricted to OPEN owner-created issue #590:

```text
/agency-production-health diagnose
```

The workflow executes from live `main` on GitHub-hosted Ubuntu and reuses only
the existing production SSH channel. The comment cannot provide a host, URL,
command, path or shell argument.

## Fixed evidence

The remote diagnostic records only bounded read-only evidence:

- `/var/www/agency/current` release and Git SHA;
- Drupal bootstrap status;
- `system.maintenance_mode` through a fixed read-only Drush evaluation;
- count of active `deploy-production.sh` processes;
- `systemctl is-active` for Nginx and PHP 8.4 FPM;
- HTTP status for the fixed FR and EN #401 public URLs;
- HTTP status for the FR URL resolved locally to `127.0.0.1` with the canonical
  hostname/SNI;
- SHA-256 and a small classification hint for the public response bodies;
- the last 12 lines of `/var/www/agency/shared/deployments.log`.

The route may create only ephemeral temporary files for response hashing and the
GitHub Actions evidence artifact. It does not alter Drupal state, deployment
state, services, releases, configuration or content.

## Verdicts

```text
MAINTENANCE_STUCK
MAINTENANCE_WITH_DEPLOY_ACTIVE
HTTP_503_LOCAL_AND_EXTERNAL
HTTP_503_EXTERNAL_ONLY
PUBLIC_HTTP_HEALTHY
SSH_DIAGNOSTIC_FAILED
UNKNOWN
```

A mutation is never inferred from a verdict. If `MAINTENANCE_STUCK` is proven,
a separate reviewed and bounded recovery route is required before changing
`system.maintenance_mode`.

## Forbidden widening

Do not add runtime command input, `sudo`, service restart/reload, deployment,
`drush state:set`, cache rebuild, config import, database update, git mutation or
arbitrary URL input to this route.

Evidence is uploaded as:

```text
artifacts/production-health/result.json
artifacts/production-health/diagnostic.txt
```
