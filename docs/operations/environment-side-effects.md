# Environment side-effect matrix

This document is the durable contract for external side effects in Agency. Configuration differences use Config Split only when they are real Drupal configuration differences; runtime credentials and authorization remain in settings/environment; scheduling is owned by the operating environment.

## Matrix

| Capability | Classification | DEV / DDEV | PREPROD | PROD |
| --- | --- | --- | --- | --- |
| GA4 / Google Tag | PROD_OVERRIDE | OFF | OFF | Anonymous users eligible only; authenticated users excluded by native Google Tag `user_role` condition; consent still applies |
| Email | PROD_OVERRIDE + runtime secret | Mailpit | `symfony_mailer` native transport, null credentials, PHP `sendmail_path=/bin/true` | SMTP transport; credential injected from server environment only |
| Drupal automated cron | SAME | OFF (`interval: 0`), manual | OFF (`interval: 0`), manual/bounded | OFF (`interval: 0`); operating-system scheduler owns execution |
| Drupal cron scheduler | PROD runtime + separate authority | None | None | Exactly one deploy-user crontab, every 15 minutes, protected by `flock`; functional promotion is VERIFY_ONLY and cannot create/update/remove it |
| OpenAI / external AI provider | runtime gate | OFF by default; explicit `AGENCY_AI_EGRESS_ENABLED` opt-in required | Forced OFF; Key disabled; no provider credential; chatbot guard blocks external calls | OFF by default; activation requires an explicit product/config change plus runtime credential |
| Link Checker | DEV_OVERRIDE / PREPROD_OVERRIDE / PROD_OVERRIDE | DDEV canonical host, manual | PREPROD canonical host, manual/bounded | Production canonical host, scheduler-driven |
| Drupal Update notifications | SAME | No Drupal email recipient | No Drupal email recipient | No Drupal email recipient; dependency/security monitoring is owned outside Drupal mail |
| Announcements / update fetches | SAME + scheduler | Manual only | Manual/bounded only | May run through controlled Drupal cron |
| Webform mail | inherits Email | Mailpit | sink/null behavior through native `/bin/true` | Real SMTP only through PROD runtime |
| Cookie consent | SAME | Testable | Testable; accepting consent must still emit zero GA traffic | Production consent behavior gates anonymous analytics eligibility |
| Simple Sitemap / SEO output | SAME + web runtime | Local only | noindex + Basic Auth | Public canonical output |
| Custom external API / webhook writes | runtime gate | OFF/fake unless explicitly opted in | OFF/sandbox unless an explicit trusted proof authorizes it | Explicit PROD feature only |

## Current production scheduler baseline

The first read-only scheduler audit under #815 established `automated_cron interval = 0` and found no external Drupal scheduler before the first governed same-artifact production promotion.

The successful r10 promotion of candidate `a2e28b5c9206e0c5f2b97c81d871f30cbae1a4e9` then created one deploy-user scheduler as an implicit side effect. The application promotion itself remained healthy, but scheduler creation was outside the exact release GO and is classified under #812/#823 as `POST_MUTATION_POLICY_DEVIATION`.

A fresh read-only rerun of scheduler audit `32892375791` after r10 proved the current technical state:

- Drupal automated cron = `0`;
- deploy-user `drush cron` entries = `1`;
- system cron duplicates = `0`;
- systemd duplicates = `0`;
- external Drupal scheduler count = `1`;
- `crontab` and `flock` available;
- audit = PASS.

The controlled contract is:

```text
marker   = # agency-drupal-cron
schedule = */15 * * * *
lock     = /var/www/agency/shared/cron.lock
command  = cd /var/www/agency/current && flock -n <lock> vendor/bin/drush cron -q
```

The current scheduler is therefore technically aligned with the desired architecture. Its governance disposition remains a Project Lead decision: KEEP/RATIFY or REMOVE. Repository convergence does not retroactively authorize the r10 scheduler write and does not itself mutate the current scheduler.

## Scheduler authority boundary

`scripts/production-promotion/reconcile-cron.sh` keeps its historical filename for compatibility with the established promotion route, but its contract is now strictly `VERIFY_ONLY`:

- default action = `VERIFY_ONLY`;
- any other action is rejected;
- it never writes `crontab`;
- it requires automated cron to remain disabled;
- it requires exactly one marker and exactly one exact controlled deploy-user scheduler;
- it rejects unmanaged deploy-user, system cron and systemd schedulers;
- drift or duplicates fail closed.

Functional release promotion invokes this verifier and therefore cannot CREATE, UPDATE or REMOVE the PROD scheduler.

Scheduler mutation is a separate owner-authorized operation through `.github/workflows/production-scheduler-change.yml`. The exact authorization command binds:

- requested transition: `CREATE`, `UPDATE` or `REMOVE`;
- current production release SHA;
- expected scheduler state;
- owner-authored GitHub comment ID;
- SHA-256 digest of the authorization body.

Allowed transitions are deliberately bounded:

```text
ABSENT        --CREATE--> CONTROLLED
MANAGED_DRIFT --UPDATE--> CONTROLLED
CONTROLLED    --REMOVE--> ABSENT
```

`UNMANAGED` and `INVALID` are never writable preconditions. The mutator also resolves the current production symlink back to exactly one successful production promotion receipt and refuses a release identity mismatch before any crontab write.

A scheduler mutation is never inferred from a functional release GO, from secret availability, from the desired architecture or from an existing scheduler. It requires its own exact owner authority.

## External provider and environment notes

OpenAI secret presence is never authority. The common Key entity is disabled by default. DDEV/trusted proof must explicitly opt in; PREPROD forces provider egress OFF. `agency_ai_translation` checks the environment gate before making an HTTP request, while the chatbot Future AI path retains its own environment guard. Normal PREPROD validation must prove: external AI gate blocked, OpenAI Key disabled, `OPENAI_API_KEY` absent, and chatbot external calls disallowed.

Link Checker uses real Drupal configuration differences: common DEV configuration targets the DDEV canonical host, PREPROD split targets `preprod.emergingdigital.be`, and PROD split restores `emergingdigital.be`. Imported queue/history must never be allowed to execute automatically in DEV/PREPROD because no scheduler exists there.

`update.settings` has no notification email recipient. The historical `admin@example.com` placeholder is forbidden from becoming a real production mail destination.

Repository search for `webhook` found no active custom webhook implementation in application code during #815. The identified custom external-provider surfaces are the AI translation client and chatbot Future AI path; both are governed by explicit runtime authorization rather than secret presence.

## Repository-change boundary

The #823 recovery PR performs `PROD_MUTATION = NONE`. Its PR-time scheduler audit is read-only and executes the same verify-only contract against PROD. An ordinary merge to `main` must not deploy the application and must not mutate the scheduler.

After repository convergence, #812 remains open until the Project Lead explicitly dispositions the already-present scheduler. If the decision is KEEP/RATIFY, no runtime write is required merely to preserve the existing entry. If the decision is REMOVE, that is a real production mutation and must use the separate explicit scheduler authority.
