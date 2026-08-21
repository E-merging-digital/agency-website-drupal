# Production maintenance recovery

Status: **INCIDENT-BOUND / ONE-SHOT / MUTATING**  
Owner: #592  
Source diagnosis: #590

## Purpose

Issue #590 proved that production is stuck in Drupal maintenance mode while no
deployment is active. This route performs the smallest reviewed recovery needed
to return the already-deployed runtime to public service.

It is intentionally separate from the read-only production health diagnostic.
The diagnostic must never acquire mutation capability.

## Control surface

The only command is:

```text
/agency-production-maintenance recover
```

It is accepted only on OPEN owner-created issue #592, from actor and comment
author `E-merging-digital`, and only when the workflow revision is current live
`main`.

The comment supplies no host, URL, runtime SHA, path, shell command or recovery
parameter.

## Pinned incident state

The route is pinned to the runtime SHA proven by #590:

```text
37214cb0913339829b97e3576443712e8a6a24f9
```

Before mutation, all of these conditions must pass:

- `/var/www/agency/current` exists and contains Drush;
- current runtime SHA equals the pinned SHA;
- Drupal bootstrap succeeds;
- `system.maintenance_mode == 1`;
- zero `deploy-production.sh` processes are active;
- Nginx is active;
- PHP 8.4 FPM is active.

The race-sensitive runtime SHA, deployment-process count and maintenance state
are checked a second time immediately before mutation. Any mismatch returns
`NO_MUTATION` and fails closed.

## Only allowed mutation

Exactly two Drupal operations are authorized:

```text
vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer
vendor/bin/drush cr
```

No deployment, service restart/reload, Git mutation, database update, config
import, content mutation or arbitrary command is permitted.

## Postconditions

After the two operations, the route requires:

- maintenance mode is `0`;
- runtime SHA is unchanged;
- no deployment process is active;
- fixed FR Article URL returns 2xx/3xx;
- fixed EN Article URL returns 2xx/3xx.

Success is `RECOVERED`. If mutation occurred but a postcondition fails, the
result is `RECOVERY_POSTCHECK_FAILED`; the route does not hide that partial
state or retry additional mutations.

Evidence is persisted as:

```text
artifacts/production-maintenance-recovery/result.json
artifacts/production-maintenance-recovery/recovery.txt
```

## Required follow-up

After `RECOVERED`:

1. rerun `/agency-production-health diagnose` on #590 for independent read-only
   confirmation;
2. rerun `/agency-production-browser validate` on #401;
3. inspect the FR/EN desktop/mobile Browser Validation artifacts;
4. close #401 only when the full browser/SEO/visual DoD is green.

Once recovery succeeds, close #592. A repeated recovery against maintenance
mode `0` must fail `NO_MUTATION`; it is not an idempotent mutation endpoint.
