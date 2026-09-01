# Agency execution capabilities

Status: **AUTHORITATIVE CAPABILITY REGISTRY**  
Repository: `E-merging-digital/agency-website-drupal`  
Registry owner: #421  
Last materialized: 2026-09-01

## 1. Purpose

This file is the durable source of truth for Agency machine execution and UI
inspection capabilities.

Every agent working on this repository MUST read this file before concluding
that an executor, browser, MCP, DDEV environment or governed mutation route is
missing, unavailable or requires a human.

Fundamental invariant:

```text
operator-surface capability
!=
project-executor capability
```

Therefore:

```text
tool not exposed directly in the current ChatGPT cockpit
!= tool absent from Agency
!= HUMAN_REQUIRED
```

If a governed machine route exists, use or extend that route rather than asking
Jonathan to perform a mechanical step.

## 2. Self-hosted Agency runner

Agency has a dedicated repository-scoped self-hosted runner on the managed Linux
host:

```text
host                 = preflight-runner-01
runner               = agency-browser-runner-01
account              = agency-runner
runner directory     = /opt/actions-runner-agency
workdir              = /opt/actions-runner-agency/_work
labels               = self-hosted, linux, x64, agency, ddev, browser
```

The Agency runner is distinct from the Preflight runner even though both use the
same host. Their Unix accounts, runner installation directories, workdirs and
services are separate.

Observed runtime evidence from trusted Agency runs includes:

```text
Ubuntu               = 24.04 LTS class host
GitHub Actions runner= 2.336.0
Docker               = 29.7.2
DDEV                 = 1.25.3
DDEV database image  = MariaDB 11.8
PHP in DDEV           = 8.4
Node for browser jobs = 24 via actions/setup-node
Chromium              = Playwright-managed
```

The host account is not required to expose a standalone PHP binary. PHP checks
for Drupal/runtime routes must use DDEV unless a workflow explicitly provisions
host PHP.

The repository `.ddev/config.yaml` also targets MariaDB 11.8. Any older
reference to MariaDB 10.11 in DDEV documentation is historical debt, not the
current Agency DDEV runtime.

## 3. Browser and UI capabilities available on the runner

| Capability | Status | Primary use |
| --- | --- | --- |
| Playwright Test | AVAILABLE | Reproducible browser validation, desktop/mobile, DOM, console, network, screenshots and traces |
| Chromium for Playwright | AVAILABLE | Real browser used by the validation suite |
| Playwright MCP | AVAILABLE | Interactive agent-driven browser inspection on the runner |
| Chrome DevTools MCP | AVAILABLE | Interactive DevTools-level diagnosis for console/network/CSS/JS/runtime work |
| DDEV | AVAILABLE | Real Agency Drupal runtime and trusted dependency resolution |
| Docker | AVAILABLE | DDEV/container execution |

Playwright MCP and Chrome DevTools MCP are **project-executor capabilities**.
They remain available even when the current ChatGPT product surface does not
present them as direct top-level tools.

An agent MUST NOT infer `MCP unavailable` from `no MCP tool visible in this
chat`.

## 4. Governed browser validation route

The unattended route is:

```text
ChatGPT / authorized operator
-> GitHub control branch request
-> GitHub-hosted validation gateway
-> trusted workflow on main
-> agency-browser-runner-01
-> exact checkout
-> Node / Playwright / Chromium
-> isolated DDEV
-> Drupal site:install --existing-config
-> final drush config:import
-> fail-closed config:status
-> Content Sync validate / dry-run / apply
-> fail-closed config:status
-> browser validation
-> GitHub artifacts
-> DDEV/workspace cleanup
```

The final `config:import` is intentional. Install-profile and module lifecycle
work can mutate active configuration after the install-time import. A browser
proof is only canonical when active configuration is converged back to the
repository sync directory and `config:status` reports no differences.

The runner is never reached directly by an untrusted pull request. Agency is a
public repository, so self-hosted execution remains trusted-dispatch only.

Implementation:

```text
docs/operations/agency-self-hosted-browser-runner.md
```

### Governed Content transition proof route

Issue #446 adds a trusted-dispatch route for migrations that must preserve the
**same Drupal database** while repository governance changes across commits.
It is not interchangeable with the fresh-install browser route.

```text
agency/governed-content-transition-dispatch-control
-> strict JSON request
-> GitHub-hosted PR/base/release/exact-HEAD validation
-> trusted workflow on main
-> agency-browser-runner-01
-> one isolated DDEV database
-> base state + active mappings
-> reviewed release candidate + explicit release
-> exact final HEAD + Content Sync persistence proof
-> browser evidence
-> controlled rollback / readmission
-> artifact
-> cleanup always()
```

For the #440 pilot the profile is fixed to `case-studies-440`; callers may not
supply arbitrary content IDs or shell commands. The request carries an exact PR
HEAD and a reviewed `release_sha`, and the trusted gateway proves ancestry and
the expected path set before the self-hosted runner is allocated.

Implementation:

```text
docs/operations/governed-content-transition-proof.md
```

## 5. Governed Composer materialization route

Issue #531 adds a reusable route for dependency-only Composer resolution when a
reviewed `composer.json` change needs a real lockfile generated by the managed
DDEV executor.

This route closes the historical gap where a self-hosted run could generate a
`composer.lock` artifact but a human still had to copy it back to the branch.
It does **not** grant repository write credentials to the self-hosted runner.

```text
agency/composer-materialization-dispatch-control
-> .agency/composer-materialization-request.json
-> GitHub-hosted actor/schema/PR/HEAD/profile validation
-> semantic proof that composer.json contains only the profiled change
-> trusted workflow from main
-> self-hosted DDEV with contents:read
-> targeted Composer resolution --no-scripts
-> composer.lock + result.json artifact
-> GitHub-hosted artifact/package/hash revalidation
-> final live-HEAD check
-> fast-forward composer.lock commit to the PR branch
```

The request may contain only a request ID, PR number, exact HEAD SHA and a
repository-owned profile identifier. Package names, constraints, Composer
arguments and commands are not request inputs.

Profiles are versioned in:

```text
scripts/runner/composer-materialization-profiles.sh
```

The initial profile is `canvas-ai-agents-530`, owned by product issue #530. New
packages require an explicit reviewed profile change; never generalize the
request schema into arbitrary Composer or shell input.

The self-hosted job checks out trusted code and the target with
`persist-credentials: false` and `contents: read`. The only write-capable step
runs later on GitHub-hosted infrastructure and may publish only the validated
`composer.lock` after proving that the PR has not advanced.

Implementation and full contract:

```text
docs/operations/governed-composer-materialization.md
```

## 6. Governed editor-owned Article publication route

Issue #576 adds a bounded production mutation route for ordinary Blog Articles
that are explicitly editor-owned in Drupal and outside Content Sync / Governed
Content.

This is **not** a generic production shell or content API. The only control
surface is an exact issue comment authored by `E-merging-digital` on an OPEN
owner-created issue:

```text
/agency-editorial inspect
/agency-editorial dry-run
/agency-editorial apply
```

The trusted workflow runs from the live `main` revision on GitHub-hosted
infrastructure and reuses the existing deployment SSH secrets and production
channel. Payload values are transferred only as a canonical JSON file and are
never interpolated into shell or Drush commands.

The v1 contract is fixed to:

```text
bundle                 = article
source language        = fr
required translation   = en
text format            = basic_html
category vocabulary    = blog_categories
technical author       = uid 1
aliases                 = Pathauto-owned
```

`dry-run` is read-only and publishes the canonical payload SHA-256. `apply`
requires a previous bot-authored dry-run PASS for the same payload hash and the
same live `main` SHA, performs a fresh Drupal preflight, creates a SQL backup,
then mutates content only through Drupal Entity API. An issue-to-node state
mapping is used only for idempotence/audit; it is not an editorial source of
truth.

The route may not invoke Content Sync, deployment, arbitrary Drush/shell,
configuration import/update, taxonomy creation, another bundle or another text
format.

Implementation and full contract:

```text
docs/operations/governed-editorial-publication.md
```

## 7. Governed editor-owned Article feature-image route

Issue #584 adds the bounded media increment deliberately excluded from the
Article publication v1 payload. It can attach or repair only the reviewed
`field_feature_image` state of an Article already mapped by the publication
route.

The control surface is limited to exact owner-authored comments:

```text
/agency-editorial-image inspect
/agency-editorial-image dry-run
/agency-editorial-image apply
```

The workflow executes from live `main`, reuses the existing production SSH
channel and resolves a repository-owned closed profile. The initial profile is
#401 only and fixes the original Article payload hash, repository asset path,
final filename, PNG SHA-256, MIME, dimensions, byte limit and FR/EN ALT values.
No runtime URL, arbitrary path, uploaded attachment, shell argument or alternate
field is accepted.

The helper verifies the existing `agency_editorial.issue.<N>` mapping, stores the
exact file under `public://articles/` through Drupal File API, uses the same FID
for FR/EN and preserves distinct mandatory translated ALT values. `apply`
requires a prior bot-authored dry-run PASS for the same profile hash, asset hash
and live main, performs a fresh dry-run and creates a SQL backup before any
READY/REPAIR_REQUIRED mutation. A converged second apply is write-free and
revision-stable.

Implementation and full contract:

```text
docs/operations/governed-editorial-feature-image.md
```

## 8. What the agent can verify visually today

The browser workflow publishes evidence including:

```text
artifacts/browser-validation/result.json
artifacts/browser-validation/evidence/*.json
screenshots
Playwright test screenshots on failure
Playwright traces on failure
playwright-report
```

These GitHub Actions artifacts can be fetched by the agent and the screenshots
can be inspected visually. This means an agent can perform a real UI review from
the runner output rather than relying only on DOM assertions or log text.

The final #399 proof on 2026-08-15 demonstrated this end-to-end. The #519 and
#526 governed SDC/Canvas proofs subsequently reused the same route for exact-HEAD
desktop/mobile validation and human visual review.

## 9. Interactive MCP versus artifact review

Do not conflate these two paths.

### Artifact-based review

```text
runner executes Playwright
-> artifact uploaded to GitHub
-> agent fetches artifact
-> agent examines screenshot / trace / result
```

This path is reachable from the ChatGPT/GitHub control surface and gives the
agent final rendered browser evidence.

### Interactive Playwright MCP / Chrome DevTools MCP

Both MCP servers are available on the self-hosted runner. They support a more
interactive diagnostic loop from an agent runtime attached to the runner and
permitted to invoke them.

Availability on the runner does **not** imply that every ChatGPT conversation
has a direct MCP transport attached to that runner. If the current cockpit lacks
that direct transport, the correct conclusion is:

```text
MCP AVAILABLE ON PROJECT EXECUTOR
DIRECT COCKPIT ROUTE NOT CURRENTLY EXPOSED
```

not:

```text
MCP UNAVAILABLE
```

When a task benefits from interactive MCP and the current operator surface lacks
an invocation route, first look for an existing governed executor/agent route.
Only create a new route under a dedicated issue if the existing routes truly
cannot invoke the installed capability.

## 10. Tool responsibilities

```text
Drupal BrowserTestBase
  -> server-side/runtime Drupal functional confidence

Playwright Test
  -> deterministic real-browser delivery evidence

Playwright MCP
  -> interactive browser navigation and inspection by an agent

Chrome DevTools MCP
  -> deeper DevTools diagnosis for console/network/CSS/JS/runtime work

Governed Composer materializer
  -> dependency-only lock resolution with separated write privilege

Governed editorial publisher
  -> bounded editor-owned Article inspect/dry-run/apply via trusted main

Governed editorial feature-image publisher
  -> bounded repository-owned field_feature_image inspect/dry-run/apply via trusted main

Configuration language audit
  -> fresh-DDEV read-only repository/active config snapshot and langcode evidence
```

MCP is not a replacement for deterministic tests, and deterministic tests are
not a replacement for visual inspection. The Composer materializer is not a
generic command executor. The editorial publisher and feature-image publisher
are not generic production content, upload or shell APIs. The configuration
language audit is not an enforcement or migration route.

## 11. Secrets and authenticated UI

Public validation requires no secret.

For future authenticated Drupal scenarios:

- use ephemeral credentials supplied through a governed secret surface;
- use Playwright `storageState` only as ephemeral runtime state;
- keep `tests/browser/.auth/` ignored;
- never commit cookies, passwords or browser profiles;
- never upload auth state as a normal artifact;
- exclude sensitive/private pages from screenshots unless explicitly required
  and safe.

The Composer materialization route carries no provider secret and must never be
extended to transport one.

The editorial publication and feature-image routes reuse only the existing
production SSH secrets. They must never expose those values in artifacts or
comments and must not be widened to provider keys, Drupal passwords or arbitrary
credentials.

The configuration language audit uses no production SSH or provider credential.
Its generated Drupal administrator password exists only for the isolated DDEV
installation and is not persisted as evidence.

## 12. Reload rule for future agents

Before any statement such as:

```text
there is no runner
Playwright is unavailable
Playwright MCP is unavailable
Chrome DevTools MCP is unavailable
Composer cannot be materialized without human file copying
ordinary Drupal Article publication requires mechanical human entry
Article feature-image publication requires mechanical human upload
configuration language cannot be audited on a fresh Drupal without a human
I cannot validate the UI
Jonathan must run this manually
```

reload, in this order:

1. `docs/operations/execution-capabilities.md`;
2. the route-specific operations document;
3. the relevant open issue/PR and latest trusted workflow runs;
4. only then decide whether a capability or governed route is actually missing.

If repository documentation and live executor evidence disagree, live evidence
wins and this registry must be corrected rather than forgetting the capability.

## 13. Proven configuration language audit route

Issue #609/#614 provides a trusted read-only audit of configuration language on a
fresh Drupal rebuilt from repository-owned configuration.

Control surface:

```text
/agency-config-language inspect
```

The command is accepted only from `E-merging-digital` on open owner-created
issue #609. The GitHub-hosted gateway pins live `main`, then the Agency
self-hosted runner executes with `contents: read`, `persist-credentials: false`,
no production SSH and no provider secret.

Execution:

```text
live main
-> isolated DDEV
-> PHP lint in DDEV
-> composer install from existing lock
-> site:install --existing-config
-> cim
-> clean config:status
-> repository + active config language snapshot
-> artifact
-> cleanup
```

Admission proof:

```text
run                  = 32528341256
trusted main         = c6d77fd109aa40cc6cf5849249d04e3d87bae65e
verdict              = PASS / SNAPSHOT_CAPTURED
snapshot SHA-256     = df4d389eafaad6135fcd7d995354ff433111be62f745208ac0a65ddf8783629d
repository/active    = 595 / 595, zero langcode mismatch
```

The baseline confirms mixed FR/EN technical configuration and therefore does not
authorize enforcement. Durable evidence summary:

```text
docs/evidence/configuration-language-baseline-609.yml
```

Full route contract:

```text
docs/operations/configuration-language-audit.md
```

## 14. Configuration Language Lock non-enforcing evaluation

Issue #630 adds a bounded **candidate** evaluation route for #628 / PR #629.
It does not adopt or enforce Configuration Language Lock merely by existing on
`main`.

Composer materialization gains one explicit reviewed profile:

```text
config-language-lock-628
-> drupal/config_language_lock:^1.0
-> stable 1.0.x only
```

The generic Composer gateway remains closed to arbitrary packages and explicitly
allowlists only repository-owned profiles. The #629 product PR must be
`composer.json`-only before lock resolution.

After the true lock is published, the DDEV proof is triggered only by:

```text
/agency-config-language-lock evaluate
```

on exact draft PR #629. The self-hosted runner then rebuilds the exact HEAD,
audits the lock, fingerprints every active config object, enables
`config_language_lock` with no language lock configured, permits no pre-existing
config mutation except `core.extension`, then uninstalls the module and requires
an exact return to the pre-enable active-config fingerprint.

The proof also requires `system.menu.footer` to remain `und`, preserves
`language.entity.und` and `language.entity.zxx`, performs no `cex`, and leaves
`config/sync` untouched. No production SSH, provider secret or repository write
credential is available to the self-hosted job.

This capability remains **CANDIDATE / NOT YET PROVEN** until it executes
successfully on the exact #629 HEAD after #630 has been merged.

Full route contract:

```text
docs/operations/config-language-lock-evaluation.md
```

## 15. PREPROD refresh activation capability source

Issue #874 adds the repository source for the bounded PREPROD refresh helper,
root-owned runtime fence and governed capability provisioning route required by
#868.

Current state:

```text
capability source = AVAILABLE after #874 merge
real host provisioning = separately governed #874 PLAN/APPLY
data activation authority after provisioning = DISABLED
issue #874 data activation authority = NONE
real refresh/backup/activation/rollback = FORBIDDEN
```

The fixed helper is `/usr/local/sbin/agency-preprod-refresh-control`; the existing
#859/#866 `/usr/local/sbin/agency-preprod-staging-db` remains unchanged. The
refresh helper reuses the pinned `agency-preprod-staging-sanitizer.py` and
`agency-preprod-refresh-v1`, then applies only fixed PREPROD side-effect
hardening.

The fence uses root-owned state `/var/lib/agency-preprod-refresh` mode `0711`, a
`0600` marker, a pinned Nginx public-503 snippet and loopback-only
`/health/ready` on `127.0.0.1:18087`. Sensitive incoming/candidate/backup
subdirectories remain `0700`.

`CAPABILITY_PROVISIONING != DATA_ACTIVATION_AUTHORITY`. A future explicit #816
child/successor must establish the first real data-execution authority. #871 owns
the consolidated operator documentation.

Full contract:

```text
docs/operations/preproduction-refresh-activation-capability.md
```

## 16. Development Seed -> DDEV pull-only

Issue #873 adds the repository-supported developer consumption route:

```text
immutable sanitized Development Seed
-> authenticated read-only SSH/SCP distribution
-> .ddev/providers/agency.yaml
-> ddev pull agency
-> SHA-256 + code compatibility guard
-> DDEV native snapshot/import
-> Drush updb/cim/cr
-> local-only admin + side-effect assertions
```

Boundaries:

```text
DDEV -> seed storage      = FORBIDDEN
DDEV -> PREPROD runtime   = FORBIDDEN
DDEV -> PROD              = FORBIDDEN
seed -> DDEV              = ONLY ALLOWED DATA DIRECTION
files                     = NONE in v1
private files             = NEVER
consumer PROD credential  = NONE
consumer PREPROD runtime credential = NONE
```

The provider contains no push stanza. DDEV 1.25.3 owns pull/import and local
snapshot recovery; Drush 13.7.6 plus the existing #914 Agency sanitizer own the
generic/PREPROD sanitization layers. #873 adds only the stricter distributable-
development pass, immutable metadata/hash, simple same-or-ancestor compatibility
guard and local safety assertions.

This capability is **SOURCE + SYNTHETIC PROOF ONLY** until #816 terminally
establishes the real source boundary and a separate authorization provisions a
dedicated read-only seed-distribution identity/storage. #873 itself performs no
real PREPROD data read, seed generation or seed distribution.

Full contract:

```text
docs/operations/development-seed.md
```
