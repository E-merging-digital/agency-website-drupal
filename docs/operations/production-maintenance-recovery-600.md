# Production maintenance recovery — incident #600

Status: **INCIDENT-BOUND / ONE-SHOT / MUTATING**  
Owner: #600  
Source diagnosis: #590 / run `32505201409`  
Related deployment: #599

## Purpose

After #599 deployed runtime
`ffccfc35c24805c4ae973cbd847b827b21a04184`, the read-only production
health diagnostic proved that Drupal remained in maintenance mode while no
deployment process was active. Nginx and PHP 8.4 FPM were active, while the
fixed FR/EN Article #401 URLs returned HTTP 503.

The historical recovery v1 for #592 remains unchanged. This v2 route is a
separate one-shot surface for the new incident and runtime.

## Control surface

The only accepted command is:

```text
/agency-production-maintenance recover-600
```

It is accepted only on OPEN owner-created issue #600, from actor and comment
author `E-merging-digital`, and only when the workflow revision is current live
`main`.

The comment cannot supply a host, URL, runtime SHA, path, shell command or any
recovery parameter.

## Pinned incident state

The recovery is pinned to production runtime:

```text
ffccfc35c24805c4ae973cbd847b827b21a04184
```

Before mutation, all of these conditions must pass:

- `/var/www/agency/current` exists and contains Drush;
- current runtime SHA equals the pinned SHA;
- Drupal bootstrap succeeds;
- `system.maintenance_mode == 1`;
- zero `deploy-production.sh` processes are active;
- Nginx is active;
- PHP 8.4 FPM is active.

The runtime SHA, deployment-process count and maintenance state are checked a
second time immediately before mutation. Any mismatch returns `NO_MUTATION`.

## Only allowed mutation

Exactly two Drupal operations are authorized:

```text
vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer
vendor/bin/drush cr
```

No deployment, service restart/reload, Git mutation, database update, config
import, content mutation or arbitrary command is permitted.

## Postconditions

After mutation, the route requires:

- maintenance mode is `0`;
- runtime SHA is unchanged;
- no deployment process is active;
- fixed FR Article #401 URL returns 2xx/3xx;
- fixed EN Article #401 URL returns 2xx/3xx.

Success is `RECOVERED`. A postcondition failure after mutation is reported as
`RECOVERY_POSTCHECK_FAILED`; no additional mutation is attempted.

Evidence is persisted under:

```text
artifacts/production-maintenance-recovery-600/result.json
artifacts/production-maintenance-recovery-600/recovery.txt
```

## Required follow-up

After `RECOVERED`:

1. rerun `/agency-production-health diagnose` on #590;
2. execute `/agency-production-image repair-401-source` on #596;
3. prove the new source and derivative are healthy;
4. rerun `/agency-production-browser validate` on #401 exactly once after the
   image source is healthy;
5. inspect the FR/EN desktop/mobile Browser Validation evidence before closing
   #401.

Once recovery succeeds and the independent health diagnostic is green, close
#600. A repeated recovery against maintenance mode `0` must fail
`NO_MUTATION`.
