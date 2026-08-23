# Production runtime permissions

## Invariant

Nginx and PHP-FPM run as an unprivileged web runtime (`www-data` on the current production host). A release under `/var/www/agency/releases/<timestamp>` must therefore remain readable and traversable by that runtime before `/var/www/agency/current` is switched to it.

The deployment script enforces this independently of the caller's inherited shell configuration:

- `umask 022` is set at script startup;
- the release root is made readable/traversable;
- directories under `vendor/` and `web/` are made readable/traversable;
- files under `vendor/` and `web/` are made readable;
- `web/index.php` and `web/robots.txt` are checked before the atomic `current` switch;
- the deploy fails closed before the switch if these runtime invariants are not met.

The normalization uses `find` without `-L`. It must not follow the persistent symlinks under `web/sites/default`.

## Shared paths are separate

Runtime-code permissions and shared-data permissions are intentionally different concerns.

The deploy must not apply the release normalization recursively to the targets of:

- `/var/www/agency/current/web/sites/default/settings.php` -> `/var/www/agency/shared/settings/settings.php`;
- `/var/www/agency/current/web/sites/default/files` -> `/var/www/agency/shared/files`.

`shared/files` keeps its dedicated `deploy:www-data`, group-write and setgid policy in `scripts/deploy-production.sh`. Production settings remain managed separately and must never be made public merely to satisfy release-code readability.

## Incident reference — 23 August 2026

The release `/var/www/agency/releases/20260822135335` was observed with directories `700 deploy:deploy` and files `600 deploy:deploy` while Nginx/PHP-FPM ran as `www-data`. Nginx consequently returned `403` for `robots.txt` and Drupal HTTP routes returned `404`, although Drupal CLI, the route provider and the published content were healthy.

Issue #678 restored only the required runtime read/traverse permissions, producing:

- release root: `700 -> 755`;
- `web/`: `700 -> 755`;
- `web/index.php`: `600 -> 644`;
- `web/robots.txt`: `600 -> 644`;
- local `robots.txt`: `403 -> 200`;
- public FR/EN article URLs: `200`.

The pre-change permission manifest is stored outside Git at:

`/var/www/agency/shared/backups/issue678-permissions-20260823100645.tsv`

Follow-up health, Browser Validation and database diagnostics all passed. See #676, #678, #590, #401, #658 and #680.

## Operator check

If a future release unexpectedly returns HTTP 403/404 while Drupal CLI still boots, compare the Nginx docroot and release traversal permissions before restarting services or modifying Drupal. A release mode of `700` or runtime files at `600` owned only by `deploy` is incompatible with an unprivileged `www-data` web runtime unless an equivalent ACL/group policy is deliberately configured.
