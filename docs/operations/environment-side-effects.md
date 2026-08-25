# Environment side-effect matrix

This document is the durable contract for external side effects in Agency. Configuration differences use Config Split only when they are real Drupal configuration differences; runtime credentials and authorization remain in settings/environment; scheduling is owned by the operating environment.

## Matrix

| Capability | Classification | DEV / DDEV | PREPROD | PROD |
| --- | --- | --- | --- | --- |
| GA4 / Google Tag | PROD_OVERRIDE | OFF | OFF | Anonymous users eligible only; authenticated users excluded by native Google Tag `user_role` condition; consent still applies |
| Email | PROD_OVERRIDE + runtime secret | Mailpit | `symfony_mailer` native transport, null credentials, PHP `sendmail_path=/bin/true` | SMTP transport; credential injected from server environment only |
| Drupal automated cron | SAME | OFF (`interval: 0`), manual | OFF (`interval: 0`), manual/bounded | OFF (`interval: 0`); operating-system scheduler owns execution |
| Drupal cron scheduler | PROD runtime | None | None | Exactly one deploy-user crontab installed by governed PROD promotion, every 15 minutes, protected by `flock` |
| OpenAI / external AI provider | runtime gate | OFF by default; explicit `AGENCY_AI_EGRESS_ENABLED` opt-in required | Forced OFF; Key disabled; no provider credential; chatbot guard blocks external calls | OFF by default; activation requires an explicit product/config change plus runtime credential |
| Link Checker | DEV_OVERRIDE / PREPROD_OVERRIDE / PROD_OVERRIDE | DDEV canonical host, manual | PREPROD canonical host, manual/bounded | Production canonical host, scheduler-driven |
| Drupal Update notifications | SAME | No Drupal email recipient | No Drupal email recipient | No Drupal email recipient; dependency/security monitoring is owned outside Drupal mail |
| Announcements / update fetches | SAME + scheduler | Manual only | Manual/bounded only | May run through controlled Drupal cron |
| Webform mail | inherits Email | Mailpit | sink/null behavior through native `/bin/true` | Real SMTP only through PROD runtime |
| Cookie consent | SAME | Testable | Testable; accepting consent must still emit zero GA traffic | Production consent behavior gates anonymous analytics eligibility |
| Simple Sitemap / SEO output | SAME + web runtime | Local only | noindex + Basic Auth | Public canonical output |
| Custom external API / webhook writes | runtime gate | OFF/fake unless explicitly opted in | OFF/sandbox unless an explicit trusted proof authorizes it | Explicit PROD feature only |

## Evidence and current live baseline

The read-only production scheduler audit on 25 August 2026 established `automated_cron interval = 0` and found zero deploy-user, system-cron or systemd `drush cron` schedulers. This is a live gap, not permission to mutate PROD. The repository therefore keeps automated cron disabled everywhere and prepares one controlled scheduler that is installed only by the already governed same-artifact production promotion after an explicit owner GO.

The scheduler reconciliation is fail-closed: it requires `crontab` and `flock`, refuses an unmanaged Drupal cron scheduler, preserves unrelated crontab entries, converges the tagged Agency entry to exactly one instance, and does not run during PREPROD deployment.

OpenAI secret presence is never authority. The common Key entity is disabled by default. DDEV/trusted proof must explicitly opt in; PREPROD forces provider egress OFF. `agency_ai_translation` checks the environment gate before making an HTTP request, while the chatbot Future AI path retains its own environment guard. Normal PREPROD validation must prove: external AI gate blocked, OpenAI Key disabled, `OPENAI_API_KEY` absent, and chatbot external calls disallowed.

Link Checker uses real Drupal configuration differences: common DEV configuration targets the DDEV canonical host, PREPROD split targets `preprod.emergingdigital.be`, and PROD split restores `emergingdigital.be`. Imported queue/history must never be allowed to execute automatically in DEV/PREPROD because no scheduler exists there.

`update.settings` has no notification email recipient. The historical `admin@example.com` placeholder is forbidden from becoming a real production mail destination.

Repository search for `webhook` found no active custom webhook implementation in application code during #815. The identified custom external-provider surfaces are the AI translation client and chatbot Future AI path; both are governed by explicit runtime authorization rather than secret presence.

## Promotion boundary

No change in this issue mutates PROD. The scheduler installer is only invoked inside `scripts/production-promotion/promote-candidate.sh`, whose caller already requires the exact candidate SHA, artifact digest, Composer lock digest, successful PREPROD evidence and an explicit owner-authored GO. Until such GO exists, current PROD remains unchanged.
