# Agency execution capabilities

Status: **AUTHORITATIVE CURRENT CAPABILITY REGISTRY**
Repository: `E-merging-digital/agency-website-drupal`
Registry owner: #421
Current lifecycle index: `docs/operations/agency-environment-data-lifecycle.md`
Last materialized: 2026-09-01

## 1. Purpose and interpretation

This registry answers: **which useful capabilities currently exist, where do they execute, what can they read/write, and which route owns authorization?** It is not an archive of every historical Agency command.

```text
operator-surface capability != project-executor capability
registered capability != currently online executor
command dispatch != execution authority
```

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
| Production promotion | `.github/workflows/promote-production.yml` | Exact same-artifact functional PROD promotion after owner/evidence gates. |
| Production scheduler | `.github/workflows/production-scheduler-change.yml` | Separate bounded scheduler transition authority. |
| Editorial publication | `.github/workflows/trusted-editorial-publication.yml` | Bounded Article inspect/dry-run/apply. |
| Editorial feature image | `.github/workflows/trusted-editorial-feature-image.yml` | Bounded feature-image inspect/dry-run/apply. |
| PREPROD refresh | `.github/workflows/preprod-914-governed-successor.yml` | One-shot #914 PLAN/APPLY route under #816. |

Historical issue-comment commands removed by #922 are intentionally not current capabilities.

## 3. Current operational capability index

| Capability | Owner issue | Current status | Execution surface | Read / write scope | PLAN / dry-run | APPLY | Human boundary | Primary workflow | Primary script | Primary doc |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Immutable code/config candidate build | release system | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Repository read; immutable artifact creation | CI/build validation | Build artifact only | Later PROD mutation requires GO | `.github/workflows/build-release-candidate.yml` | workflow-owned | `docs/operations/preproduction.md` |
| Exact candidate PREPROD deployment | release system | `PROVISIONED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PREPROD | PREPROD code/config/runtime only | Candidate identity validation | Exact artifact deploy + convergence | No PROD authority | `.github/workflows/deploy-preproduction.yml` | `scripts/preproduction/deploy-candidate.sh` | `docs/operations/preproduction.md` |
| Single Agency command dispatcher | #922 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Issue-comment syntax only | Classification | None | Route authority downstream | `.github/workflows/agency-command-dispatch.yml` | inline classifier | `docs/operations/agency-environment-data-lifecycle.md` |
| Same-artifact functional PROD promotion | production promotion program | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> GitHub-hosted control -> PROD | Exact approved artifact | Fresh authority/evidence checks | Same artifact PROD promotion | Exact owner GO | `.github/workflows/promote-production.yml` | `scripts/production-promotion/promote-candidate.sh` | `docs/operations/preproduction.md` |
| Production scheduler change | scheduler governance | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> GitHub-hosted control -> PROD | Bounded scheduler transition | State/authority validation | Exact transition | Separate owner authority | `.github/workflows/production-scheduler-change.yml` | route-specific script | `docs/operations/environment-side-effects.md` |
| Governed Article publication | #576 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> GitHub-hosted control -> PROD Drupal | Bounded `article` Entity API mutation | `inspect` / `dry-run` | Exact payload-hash `apply` | Owner request + exact prior proof | `.github/workflows/trusted-editorial-publication.yml` | route-specific Drupal helper | `docs/operations/governed-editorial-publication.md` |
| Governed Article feature image | #584 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | dispatcher -> GitHub-hosted control -> PROD Drupal | Bounded existing Article image mutation | `inspect` / `dry-run` | Exact profile `apply` | Owner request + exact prior proof | `.github/workflows/trusted-editorial-feature-image.yml` | route-specific helper | `docs/operations/governed-editorial-feature-image.md` |
| PROD -> PREPROD sanitized DB refresh | #914 under #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTABLE` | dispatcher -> hosted authority; PLAN hosted; APPLY trusted self-hosted | PLAN metadata-only; APPLY PROD read-only/raw trusted-only/sanitized PREPROD write | One-shot `PLAN` | Separately authorized bounded `APPLY` | Fresh #816 child; unprovable rollback => `HUMAN_RECOVERY_REQUIRED` | `.github/workflows/preprod-914-governed-successor.yml` | `run-plan.sh`, `run-apply.sh` | `docs/operations/preproduction-refresh-governed-successor.md` |
| GitHub-hosted metadata-only PLAN | #927 / real evidence #929 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | GitHub-hosted `ubuntu-24.04` | Metadata/readiness only; no raw PROD materialization | Real #929 PLAN reached readiness and returned `FAIL_CLOSED` | None | Fresh one-shot PLAN authority | `.github/workflows/preprod-914-governed-successor.yml` | `scripts/preproduction-refresh/governed-successor/run-plan.sh` | `docs/operations/preproduction-refresh-governed-successor.md` |
| PLAN bounded diagnostics recovery | #930 | `DESIGN_ONLY` / `EXECUTION_PENDING` | Repository/static/synthetic only while in progress | Future bounded metadata-only reason enums | Not current yet | None | No real execution authority in #930 | no workflow change claimed yet | existing #914 observers/scripts | issue #930 |
| Development Seed -> DDEV pull-only | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Local/trusted DDEV consumer | Database seed -> local DDEV only; no upstream writes | Hash/compatibility validation | `ddev pull agency` after real source provisioning | Real source/storage/distribution pending #816 + separate authority | DDEV provider | `.ddev/providers/agency.yaml` + `scripts/development-seed/` | `docs/operations/development-seed.md` |
| Editorial Candidate | #872 | `DESIGN_ONLY` | None | Future bounded editorial candidate only | Not implemented | Not implemented | Future exact human approval | None | None | issue #872 |

## 4. Self-hosted Agency executor

Agency has a registered runner capability with labels including:

```text
self-hosted, linux, x64, agency, ddev, browser
```

Capability registration/provisioning is durable. **Availability is dynamic.** A temporarily unavailable physical runner does not erase the capability and does not authorize moving APPLY/raw PROD data onto GitHub-hosted infrastructure.

Current #914 runner split:

```text
validate-authority = GitHub-hosted ubuntu-24.04
PLAN               = GitHub-hosted ubuntu-24.04
APPLY              = [self-hosted, linux, x64, agency]
```

#927 is **CLOSED / COMPLETED**. JIT-before-secret remains before PLAN SSH identity materialization, and PROD/PREPROD host trust remains repository-pinned.

## 5. Browser, DDEV and developer execution

| Capability | Status | Primary use |
| --- | --- | --- |
| Playwright Test | AVAILABLE on registered executor | Deterministic browser validation/evidence |
| Chromium | AVAILABLE on registered executor | Real browser validation |
| Playwright MCP | AVAILABLE on registered executor | Interactive agent browser inspection where transport exists |
| Chrome DevTools MCP | AVAILABLE on registered executor | Interactive DevTools diagnosis |
| DDEV | AVAILABLE on registered executor/local hosts | Agency Drupal runtime and dependency execution |
| Docker | AVAILABLE on registered executor | DDEV/container execution |

A conversation without direct MCP transport must not conclude that the project executor capability is absent.

## 6. Governed PROD -> PREPROD refresh (#914 / #816)

This supersedes old registry wording that presented #874/#907 activation/fence provisioning or #915/#917 transaction recovery as the current route.

```text
DECISION = EXTEND_EXISTING
CURRENT_IMPLEMENTATION = #914
PARENT = #816
REAL_END_TO_END_REFRESH = EXECUTION_PENDING
```

### Current PLAN

Current `main` routes a valid refresh command through the single dispatcher to #914. Authority validation and PLAN both run on GitHub-hosted `ubuntu-24.04`; PLAN revalidates live main, exact authority and `runner.environment == github-hosted` before transient SSH identities are materialized. Pinned PROD/PREPROD host trust remains mandatory.

PLAN is metadata/readiness-only. It creates no PROD DB content read, PROD snapshot, raw transfer, PREPROD backup, maintenance state or PREPROD DB/runtime mutation.

#929 real evidence:

```text
AUTHORITY = SUCCESS
JIT_BEFORE_SECRET = SUCCESS
REAL_METADATA_ONLY_PLAN = EXECUTED
PLAN_RESULT = FAIL_CLOSED
FAILED_READINESS_PREDICATE = NOT_YET_PROVEN
SSH_IDENTITY_CLEANUP = SUCCESS
APPLY = SKIPPED
PROD_DB_CONTENT_READ = NONE
PROD_SNAPSHOT = NOT_PERFORMED
PROD_DATA_TRANSFER = NONE
PROD_WRITE = NONE
PREPROD_MUTATION = NONE
```

The #929 failure is recoverable technical failure, not `HUMAN_REQUIRED`. #930 is **CURRENT RECOVERY / IN PROGRESS** to make future failures diagnosable with bounded non-sensitive enums. #930 is not implemented merely because the issue exists.

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

Failure after destructive replacement restores and validates the exact PREPROD backup. Proven restore => `ROLLED_BACK`; unprovable restore => `HUMAN_RECOVERY_REQUIRED` with maintenance ON.

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
REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
SYNTHETIC_PROOF = COMPLETE
REAL_PREPROD_SEED_GENERATION = PENDING #816
REAL_STORAGE_PROVISIONING = PENDING
REAL_SEED_DISTRIBUTION = PENDING
DDEV_PUSH = NONE
```

The provider has no push stanza. Current state is source/synthetic, not a live seed service.

## 8. Reload rule

Before saying a route, workflow, runner or command exists today:

1. reload `main`;
2. reload `.github/workflows/agency-command-dispatch.yml`;
3. reload the selected reusable workflow and route-specific doc;
4. reload governing issue/authority and exact execution evidence;
5. only then classify status.

If this registry and current repository differ, current repository wins and this registry must be corrected. Do not resurrect deleted historical commands to satisfy obsolete tests or prose.
