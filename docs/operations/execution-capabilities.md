# Agency execution capabilities

Status: **AUTHORITATIVE CURRENT CAPABILITY REGISTRY**
Repository: `E-merging-digital/agency-website-drupal`
Registry owner: #421
Current lifecycle index: `docs/operations/agency-environment-data-lifecycle.md`
Last materialized: 2026-09-01

## 1. Purpose and interpretation

This registry answers: **which useful capabilities currently exist, where do they execute, what can they read/write, and which current route owns authorization?** It is not an archive of every historical Agency command or one-shot workflow.

```text
operator-surface capability
!= project-executor capability

registered capability
!= currently online executor

command dispatch
!= execution authority
```

A tool not visible in the current ChatGPT cockpit does not prove it is absent from the managed Agency executor. Conversely, an old workflow/doc/run does not make an obsolete command current.

Lifecycle-sensitive status vocabulary:

- `DESIGN_ONLY`
- `SOURCE_IMPLEMENTED`
- `SYNTHETICALLY_PROVEN`
- `PROVISIONED`
- `EXECUTABLE`
- `REAL_EXECUTION_PROVEN`
- `EXECUTION_PENDING`

## 2. Current command dispatcher (#922 completed)

#922 is **COMPLETED**. Current issue-comment control is:

```text
owner command
-> .github/workflows/agency-command-dispatch.yml
-> exact syntax classification
-> exactly one reusable workflow when matched
-> capability-owned authorization
```

`agency-command-dispatch.yml` is the only top-level `issue_comment` listener. It routes syntax only; authorization remains downstream.

Current reusable command capabilities are exactly five:

| Route | Reusable workflow | Current purpose |
| --- | --- | --- |
| Production promotion | `.github/workflows/promote-production.yml` | Exact same-artifact functional PROD promotion after its own owner/evidence gates. |
| Production scheduler | `.github/workflows/production-scheduler-change.yml` | Separate bounded scheduler transition authority. |
| Editorial publication | `.github/workflows/trusted-editorial-publication.yml` | Bounded Article inspect/dry-run/apply. |
| Editorial feature image | `.github/workflows/trusted-editorial-feature-image.yml` | Bounded feature-image inspect/dry-run/apply. |
| PREPROD refresh | `.github/workflows/preprod-914-governed-successor.yml` | One-shot #914 PLAN/APPLY route under #816. |

Historical issue-comment commands removed by #922 are not current capabilities and are intentionally not enumerated here.

## 3. Current operational capability index

| Capability | Owner issue | Current status | Execution surface | Read / write scope | PLAN / dry-run | APPLY | Human boundary | Primary workflow | Primary script | Primary doc |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Immutable code/config candidate build | release system | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Repository read; immutable artifact creation | CI/build validation | Build artifact only | Later functional PROD mutation requires GO | `.github/workflows/build-release-candidate.yml` | workflow-owned | `docs/operations/preproduction.md` |
| Exact candidate PREPROD deployment | release system | `PROVISIONED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PREPROD | PREPROD code/config/runtime only | Candidate identity validation | Exact artifact deploy + PREPROD convergence | No PROD authority | `.github/workflows/deploy-preproduction.yml` | `scripts/preproduction/deploy-candidate.sh` | `docs/operations/preproduction.md` |
| Single Agency command dispatcher | #922 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Issue-comment syntax classification only | Classification | None | None; route authority remains downstream | `.github/workflows/agency-command-dispatch.yml` | inline classifier | `docs/operations/agency-environment-data-lifecycle.md` |
| Same-artifact functional PROD promotion | production promotion program | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> reusable GitHub-hosted control -> PROD | Exact approved production artifact + bounded release convergence | Fresh authority/evidence checks | Same artifact PROD promotion | Exact owner GO bound to candidate/evidence | `.github/workflows/promote-production.yml` | `scripts/production-promotion/promote-candidate.sh` | `docs/operations/preproduction.md` |
| Production scheduler change | scheduler governance | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> reusable GitHub-hosted control -> PROD | Bounded scheduler CREATE/UPDATE/REMOVE only | State/authority validation | Exact transition | Separate owner authority | `.github/workflows/production-scheduler-change.yml` | route-specific scheduler script | `docs/operations/environment-side-effects.md` |
| Governed Article publication | #576 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> reusable GitHub-hosted control -> PROD Drupal | Bounded `article` Entity API mutation | `inspect` / `dry-run` | Exact payload-hash `apply` | Owner request; exact prior proof | `.github/workflows/trusted-editorial-publication.yml` | route-specific Drupal helper | `docs/operations/governed-editorial-publication.md` |
| Governed Article feature image | #584 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> reusable GitHub-hosted control -> PROD Drupal | Bounded existing Article feature-image mutation | `inspect` / `dry-run` | Exact profile `apply` | Owner request + exact prior proof | `.github/workflows/trusted-editorial-feature-image.yml` | route-specific helper | `docs/operations/governed-editorial-feature-image.md` |
| PROD -> PREPROD sanitized DB refresh | #914 under #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTION_PENDING` | dispatcher -> GitHub-hosted authority -> trusted Agency runner -> PREPROD | PROD read-only; raw trusted-only; sanitized PREPROD DB write only | Mutation-free one-shot `PLAN` | Separately authorized bounded `APPLY` | Fresh #816 child authority; unprovable rollback => `HUMAN_RECOVERY_REQUIRED` | `.github/workflows/preprod-914-governed-successor.yml` | `scripts/preproduction-refresh/governed-successor/run-plan.sh`, `run-apply.sh` | `docs/operations/preproduction-refresh-governed-successor.md` |
| Development Seed -> DDEV pull-only | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Local/trusted DDEV consumer | Database seed -> local DDEV only; no upstream writes | Hash/compatibility validation | `ddev pull agency` after real service provisioning | Real source/storage/distribution pending #816 + separate authority | DDEV provider, not GitHub workflow | `.ddev/providers/agency.yaml` + `scripts/development-seed/` | `docs/operations/development-seed.md` |
| Editorial Candidate | #872 | `DESIGN_ONLY` | None | Future bounded editorial candidate only | Not implemented | Not implemented | Future exact human approval by candidate hash | None | None | issue #872 |
| GitHub-hosted metadata-only PLAN adaptation | #927 | `DESIGN_ONLY` / `EXECUTION_PENDING` | Proposed GitHub-hosted PLAN only | Proposed metadata/readiness only; raw PROD forbidden | Not current until merged | APPLY explicitly unchanged/self-hosted | New execution authority after implementation | proposed change to current #914 reusable workflow | existing #914 PLAN scripts | issue #927 |

## 4. Self-hosted Agency executor

Agency has a repository-scoped registered runner capability:

```text
host                 = preflight-runner-01
runner               = agency-browser-runner-01
account              = agency-runner
runner directory     = /opt/actions-runner-agency
workdir              = /opt/actions-runner-agency/_work
labels               = self-hosted, linux, x64, agency, ddev, browser
```

Durable observed baseline:

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

Capability registration/provisioning is durable. **Availability is dynamic.** A temporarily unavailable physical runner does not erase the capability and does not authorize moving APPLY/raw PROD data onto GitHub-hosted infrastructure.

Current #914 `main` truth before #927 merges:

```text
validate-authority = GitHub-hosted ubuntu-24.04
PLAN               = [self-hosted, linux, x64, agency]
APPLY              = [self-hosted, linux, x64, agency]
```

## 5. Browser, DDEV and developer execution

| Capability | Status | Primary use |
| --- | --- | --- |
| Playwright Test | AVAILABLE on registered executor | Deterministic desktop/mobile browser validation, DOM, console, network, screenshot/trace evidence |
| Chromium | AVAILABLE on registered executor | Real browser validation |
| Playwright MCP | AVAILABLE on registered executor | Interactive agent browser inspection where a control surface can invoke it |
| Chrome DevTools MCP | AVAILABLE on registered executor | Interactive DevTools diagnosis |
| DDEV | AVAILABLE on registered executor/local developer hosts | Agency Drupal runtime and dependency execution |
| Docker | AVAILABLE on registered executor | DDEV/container execution |

A conversation without direct MCP transport should report that the project executor capability exists but current cockpit transport may not expose it; it must not invent a new route merely to compensate.

Browser proof references:

- `docs/operations/agency-self-hosted-browser-runner.md`
- `docs/operations/governed-content-transition-proof.md`

Governed Composer materialization remains a bounded repository capability documented by `docs/operations/governed-composer-materialization.md`; it is not a current issue-comment command and therefore is not part of the five dispatcher routes.

## 6. Governed PROD -> PREPROD refresh (#914 / #816)

This supersedes old registry wording that presented #874/#907 activation/fence provisioning or #915/#917 transaction recovery as the current operational route.

```text
DECISION = EXTEND_EXISTING
CURRENT_IMPLEMENTATION = #914
PARENT = #816
REAL_END_TO_END_REFRESH = EXECUTION_PENDING
```

### Current PLAN

Current `main` routes a valid refresh command through the single dispatcher to the reusable #914 workflow. The reusable workflow performs GitHub-hosted authority validation, then executes PLAN on `[self-hosted, linux, x64, agency]`.

PLAN is metadata/readiness-only. It creates no PROD snapshot, raw import, PREPROD backup, maintenance state or PREPROD DB mutation.

#926 is CLOSED / NOT_PLANNED and is not a reusable operational route. Its emitted request identities are consumed. #927 is **PENDING / PROPOSED ADAPTATION** to move only PLAN execution to GitHub-hosted metadata-only infrastructure; current repository behavior remains self-hosted until that work is merged.

### Current APPLY source implementation

```text
JIT exact main/authority before secrets
-> trusted Agency runner
-> exact source release in DDEV
-> isolate Docker web/db internal-only BEFORE raw import
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

Failure after destructive replacement restores and validates exact PREPROD backup. Proven restore => `ROLLED_BACK`; unprovable restore => `HUMAN_RECOVERY_REQUIRED` with maintenance ON.

Hard boundaries:

```text
PROD_WRITE = NONE
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW_PROD_IN_GITHUB_ARTIFACT/LOG = NONE
PREPROD_RECEIVES = SANITIZED_SQL_ONLY
PERSISTENT_DATA_ACTIVATION_AUTHORITY = DISABLED
```

No `RECOVER_ABORT`, `RECOVER_CURRENT`, GitHub recovery record, historical target reconstruction or near-zero-downtime #915 transaction model is current.

Primary sources:

- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/preprod-914-governed-successor.yml`
- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`

## 7. Development Seed -> DDEV pull-only (#873)

#873 merged the repository-supported consumer contract:

```text
immutable sanitized Development Seed
-> future authenticated read-only distribution
-> .ddev/providers/agency.yaml
-> ddev pull agency
-> SHA-256 + code compatibility guard
-> DDEV native snapshot/import
-> Drush updb/cim/cr
-> local-only admin + side-effect assertions
```

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

The provider has no push stanza. DDEV 1.25.3 owns pull/import and local snapshot recovery. Drush 13.7.6 + #914 Agency sanitization + development sanitizer own the sanitization stack.

Current state is source/synthetic, not a live seed service:

```text
REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
SYNTHETIC_PROOF = COMPLETE
REAL_PREPROD_SEED_GENERATION = PENDING #816
REAL_STORAGE_PROVISIONING = PENDING
REAL_SEED_DISTRIBUTION = PENDING
DDEV_PUSH = NONE
```

Full contract: `docs/operations/development-seed.md`.

## 8. Reload rule

Before saying a route, workflow, runner or command exists today:

1. reload `main`;
2. reload `.github/workflows/agency-command-dispatch.yml` for issue-comment commands;
3. reload the selected reusable workflow and route-specific doc;
4. reload the governing issue/authority and exact execution evidence;
5. only then classify status.

If this registry and current repository differ, current repository wins and this registry must be corrected. Do not resurrect deleted historical commands to satisfy obsolete tests or prose.