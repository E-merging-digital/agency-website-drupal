# Agency execution capabilities

Status: **AUTHORITATIVE CAPABILITY REGISTRY**
Repository: `E-merging-digital/agency-website-drupal`
Registry owner: #421
Current lifecycle index: `docs/operations/agency-environment-data-lifecycle.md`
Last materialized: 2026-09-01

## 1. Purpose and interpretation

This registry answers a narrow question: **which useful machine/operational capabilities currently exist, where do they execute, and what authority do they have?** Route-specific documents own detailed recipes and historical evidence.

Every agent must read this file before concluding that a runner, browser, MCP, DDEV environment or governed mutation route is absent or requires a human.

```text
operator-surface capability
!= project-executor capability

tool not exposed in the current ChatGPT cockpit
!= tool absent from Agency
!= HUMAN_REQUIRED
```

A registered capability is not execution authority. Live issue/PR/main/run state must still be reloaded before execution or mutation.

Status vocabulary for the lifecycle-sensitive rows:

- `DESIGN_ONLY`
- `SOURCE_IMPLEMENTED`
- `SYNTHETICALLY_PROVEN`
- `PROVISIONED`
- `EXECUTABLE`
- `REAL_EXECUTION_PROVEN`
- `EXECUTION_PENDING`

## 2. Current operational capability index

| Capability | Owner issue | Current status | Execution surface | Read / write scope | PLAN / dry-run | APPLY | Human boundary | Primary workflow | Primary script | Primary doc |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Immutable code/config candidate build | release system | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Repository read; immutable artifact creation only | CI/build validation | Build artifact only | Functional PROD mutation requires later GO | `.github/workflows/build-release-candidate.yml` | workflow-owned | `docs/operations/preproduction.md` |
| Exact candidate PREPROD deployment | release system | `PROVISIONED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PREPROD | PREPROD code/config/runtime only | Candidate identity validation | Exact artifact deploy + PREPROD convergence | No PROD authority | `.github/workflows/deploy-preproduction.yml` | `scripts/preproduction/deploy-candidate.sh` | `docs/operations/preproduction.md` |
| Same-artifact functional PROD promotion | production promotion program | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD | Exact approved production artifact + bounded release convergence | Fresh authority/evidence checks | Same artifact PROD promotion | Exact owner GO bound to candidate/evidence | `.github/workflows/promote-production.yml` | `scripts/production-promotion/promote-candidate.sh` | `docs/operations/preproduction.md` |
| Governed Article publication | #576 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD Drupal | Bounded `article` Entity API mutation only | `inspect` / `dry-run` | Exact payload-hash `apply` | Owner request; exact dry-run/main binding | `.github/workflows/trusted-editorial-publication.yml` | route-specific Drupal helper | `docs/operations/governed-editorial-publication.md` |
| Governed Article feature image | #584 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD Drupal | Bounded existing Article feature-image mutation | `inspect` / `dry-run` | Exact closed-profile `apply` | Owner request + exact prior proof | `.github/workflows/trusted-editorial-feature-image.yml` | route-specific helper | `docs/operations/governed-editorial-feature-image.md` |
| PROD -> PREPROD sanitized DB refresh | #914 under #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTION_PENDING` | GitHub-hosted authority -> trusted Agency runner -> PREPROD | PROD read-only; raw trusted-only; sanitized PREPROD DB write only | Mutation-free `PLAN` | Separately authorized bounded `APPLY` | Fresh #816 child authority; unprovable rollback => `HUMAN_RECOVERY_REQUIRED` | `.github/workflows/preprod-914-governed-successor.yml` | `scripts/preproduction-refresh/governed-successor/run-plan.sh`, `run-apply.sh` | `docs/operations/preproduction-refresh-governed-successor.md` |
| Development Seed -> DDEV pull-only | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Local/trusted DDEV consumer | Database seed -> local DDEV only; no upstream writes | Hash/compatibility validation | `ddev pull agency` after real service provisioning | Real seed source/storage/distribution still pending #816 + separate authority | DDEV provider, not a GitHub workflow | `.ddev/providers/agency.yaml` + `scripts/development-seed/` | `docs/operations/development-seed.md` |
| Editorial Candidate | #872 | `DESIGN_ONLY` | None | Future bounded editorial candidate only | Not implemented | Not implemented | Future exact human approval by candidate hash | None | None | issue #872; no current operational doc claim |

Dynamic workflow triggers may be consolidated by #922. The **path currently present on live `main` must be reloaded before use**; the route-specific authorization remains authoritative even if dispatch topology changes.

## 3. Self-hosted Agency executor

Agency has a repository-scoped self-hosted runner on the managed Linux host:

```text
host                 = preflight-runner-01
runner               = agency-browser-runner-01
account              = agency-runner
runner directory     = /opt/actions-runner-agency
workdir              = /opt/actions-runner-agency/_work
labels               = self-hosted, linux, x64, agency, ddev, browser
```

Durable observed runtime baseline includes:

```text
Ubuntu                = 24.04 LTS class
GitHub Actions runner = 2.336.0
Docker                = 29.7.2
DDEV                  = 1.25.3
DDEV database         = MariaDB 11.8
PHP in DDEV            = 8.4
Node for browser jobs  = 24
Chromium               = Playwright-managed
```

The Agency runner is distinct from the Preflight runner even if they share a host. Accounts, runner directories, workdirs and services are separate.

Capability registration is durable; current online/busy state is dynamic. A queued job or offline runner does not prove the capability is absent and does not authorize moving privileged/raw-data work to GitHub-hosted infrastructure.

## 4. Browser and UI capabilities

| Capability | Status | Primary use |
| --- | --- | --- |
| Playwright Test | AVAILABLE | Deterministic desktop/mobile browser validation, DOM, console, network, screenshot/trace evidence |
| Chromium | AVAILABLE | Real browser for validation |
| Playwright MCP | AVAILABLE | Interactive agent browser inspection on the project executor |
| Chrome DevTools MCP | AVAILABLE | Interactive DevTools diagnosis |
| DDEV | AVAILABLE | Real Agency Drupal runtime and dependency execution |
| Docker | AVAILABLE | DDEV/container execution |

A ChatGPT conversation without direct MCP transport must report:

```text
MCP AVAILABLE ON PROJECT EXECUTOR
DIRECT COCKPIT ROUTE NOT CURRENTLY EXPOSED
```

rather than `MCP UNAVAILABLE`.

The unattended browser route and artifact contract are documented in:

- `docs/operations/agency-self-hosted-browser-runner.md`
- `docs/operations/governed-content-transition-proof.md`

Browser proof is complementary to Drupal/PHPUnit tests; neither replaces the other.

## 5. Governed Composer materialization

Issue #531 provides a bounded dependency-only materialization route when a reviewed `composer.json` change needs a real `composer.lock` generated on managed DDEV.

It preserves privilege separation:

```text
GitHub-hosted request/PR/HEAD/profile validation
-> trusted self-hosted DDEV Composer resolution with repository read only
-> lockfile/result artifact
-> GitHub-hosted package/hash/live-HEAD revalidation
-> bounded fast-forward composer.lock publication
```

The self-hosted runner receives no generic repository write authority. Package names/constraints/commands are repository-owned profiles, not arbitrary request input.

Full contract: `docs/operations/governed-composer-materialization.md`.

## 6. Governed editor-owned Article publication

Issue #576 is the current bounded production publication route for ordinary Blog Articles that remain editor-owned in Drupal and outside Governed Content.

Current commands:

```text
/agency-editorial inspect
/agency-editorial dry-run
/agency-editorial apply
```

`dry-run` is read-only and binds the canonical payload SHA-256. `apply` requires the exact prior dry-run/live-main identity, performs a fresh preflight, verifies a SQL backup and mutates only the fixed Article profile through Drupal Entity API.

This capability cannot deploy code, run `cim`/`updb`, invoke arbitrary shell/Drush, promote a database or create a generic entity writer.

Full contract: `docs/operations/governed-editorial-publication.md`.

The feature-image increment #584 remains a separate bounded route with its own profile/evidence: `docs/operations/governed-editorial-feature-image.md`.

## 7. Configuration language audit

The established configuration-language audit route rebuilds Agency from repository configuration in isolated DDEV and captures repository/active language metadata without production SSH/provider credentials or production mutation.

It is an audit/evidence capability, not an enforcement route. Candidate evaluations such as Configuration Language Lock remain governed by their own issue/contract and must not be upgraded to a general configuration mutation capability from this registry.

Full current references:

- `docs/operations/configuration-language-audit.md`
- `docs/operations/config-language-lock-evaluation.md`

## 8. Governed PROD -> PREPROD refresh (#914 / #816)

This section supersedes the old registry wording that presented #874 activation/fence provisioning as the current operational route.

Current decision:

```text
DECISION = EXTEND_EXISTING
CURRENT_IMPLEMENTATION = #914
PARENT = #816
REAL_END_TO_END_REFRESH = EXECUTION_PENDING
```

The implementation reuses Drupal/Drush/DDEV/MariaDB/SSH and existing Agency environment safety controls. No custom GitHub transaction/recovery framework exists in the current route.

### PLAN

A separately authorized one-shot PLAN is metadata/readiness only. It creates no PROD snapshot, raw staging import, PREPROD backup, maintenance or database mutation.

### APPLY

A separately authorized one-shot APPLY follows:

```text
JIT exact main/authority before secrets
-> trusted Agency runner
-> exact source release in DDEV
-> isolate Docker web/db onto internal-only network BEFORE raw import
-> PROD read-only Drush sql:dump
-> Drush sql:sanitize
-> thin Agency sanitizer/assertions
-> sanitized SQL only to PREPROD
-> exact PREPROD Drush backup
-> bounded maintenance
-> sql:drop + sql:cli
-> updb + cim + PREPROD split/admin convergence
-> validate
-> COMMITTED
```

Failure after destructive replacement restores and validates the exact PREPROD backup. Proven restore => `ROLLED_BACK`; unprovable restore => `HUMAN_RECOVERY_REQUIRED` with maintenance remaining ON.

Hard boundaries:

```text
PROD_WRITE = NONE
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_IN_GITHUB_ARTIFACT/LOG = NONE
PREPROD_RECEIVES = SANITIZED_SQL_ONLY
PERSISTENT_DATA_ACTIVATION_AUTHORITY = DISABLED
```

The former `RECOVER_ABORT`, `RECOVER_CURRENT`, GitHub recovery records, historical target reconstruction and near-zero-downtime #915 transaction model are not current capabilities. #915/#917 are merged history, not #914 dependencies.

Primary sources:

- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `.github/workflows/preprod-914-governed-successor.yml`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`

## 9. Development Seed -> DDEV pull-only (#873)

#873 merged the repository-supported developer consumption contract:

```text
immutable sanitized Development Seed
-> future authenticated read-only SSH/SCP distribution
-> .ddev/providers/agency.yaml
-> ddev pull agency
-> SHA-256 + code compatibility guard
-> DDEV native snapshot/import
-> Drush updb/cim/cr
-> local-only admin + side-effect assertions
```

Boundaries:

```text
DDEV -> seed storage                = FORBIDDEN
DDEV -> PREPROD                     = FORBIDDEN
DDEV -> PROD                        = FORBIDDEN
seed -> DDEV                        = ONLY ALLOWED DATA DIRECTION
files                               = NONE in v1
private files                       = NEVER
consumer PROD credential            = NONE
consumer PREPROD runtime credential = NONE
```

The provider contains no push stanza. DDEV 1.25.3 owns pull/import and local snapshot recovery. Drush 13.7.6 + #914 Agency sanitization + the thin development sanitizer own the sanitization stack.

Current status is **SOURCE_IMPLEMENTED + SYNTHETICALLY_PROVEN**, not a live seed service. Until #816 terminally establishes the real source boundary and a separate authorization provisions a dedicated read-only seed-storage/distribution identity:

```text
REAL_PREPROD_DATA_READ = NONE
REAL_SEED_GENERATION = NONE
REAL_STORAGE_PROVISIONING = NONE
REAL_SEED_DISTRIBUTION = NONE
```

Full contract: `docs/operations/development-seed.md`.

## 10. Side effects, secrets and environment ownership

The environment matrix is authoritative in `docs/operations/environment-side-effects.md` and the cross-environment ownership model in `docs/operations/agency-environment-data-lifecycle.md`.

Core rules:

- PREPROD settings/secrets are PREPROD-owned;
- PROD settings/secrets are PROD-owned;
- DDEV settings/secrets are local-owned;
- secret presence is never execution/provider authority;
- raw production data is never a GitHub-hosted materialization/artifact;
- normal PREPROD external side effects remain hardened/disabled;
- no Development Seed contains PREPROD runtime credentials.

## 11. Reload rule for future agents

Before statements such as:

```text
there is no runner
DDEV is unavailable
Playwright/MCP is unavailable
Composer requires human lockfile copying
ordinary Article publication requires mechanical human entry
PREPROD refresh uses the old activation/fence transaction model
a real Development Seed service is already available
Jonathan must perform this manually
```

reload, in order:

1. `docs/operations/agency-environment-data-lifecycle.md`;
2. this registry;
3. the route-specific operations document;
4. live `main` + relevant issue/PR + exact workflow/run/jobs;
5. only then classify capability/authority/executor availability.

If documentation and live repository/evidence disagree, live evidence wins and the documentation must be corrected.

`recoverable technical failure != HUMAN_REQUIRED`. Operator/tool limitations do not automatically become project execution limitations.