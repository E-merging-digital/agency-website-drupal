# Agency Environment & Data Lifecycle

Status: **CANONICAL CURRENT-STATE OPERATIONAL ENTRYPOINT**
Owner: #871 — tranche 1 while #816 real end-to-end execution remains pending.
Architecture rule: `docs/decisions/ADR-003-use-existing-first.md`.

This is the first operational page to read for Agency environments, code/configuration promotion, PROD-to-PREPROD data refresh, editor-owned publication and safe development data. It describes the **current** system and links to specialized contracts rather than reconstructing the history that produced it.

GitHub, the versioned repository and execution evidence are authoritative. A handoff, an old issue description or this document never replaces a fresh live reload before approval, execution, mutation or merge.

## 1. Purpose, authority and status vocabulary

Agency uses existing Drupal, Drush, DDEV and system primitives first. Future changes follow ADR-003:

```text
Drupal Core
-> Drush / Drupal APIs
-> DDEV
-> stable supported contrib when useful
-> standard system tools
-> minimal Agency extension only for a demonstrated gap
```

Use status terms narrowly:

- `DESIGN_ONLY`: intent exists; no repository implementation is claimed.
- `SOURCE_IMPLEMENTED`: versioned implementation exists.
- `SYNTHETICALLY_PROVEN`: source/static/synthetic tests prove the stated contract without claiming a real environment execution.
- `PROVISIONED`: required runtime infrastructure/credential surface has been installed and proven at the stated boundary.
- `EXECUTABLE`: a real governed route is available, subject to fresh authority/runtime checks.
- `REAL_EXECUTION_PROVEN`: a real execution reached terminal evidence at the stated boundary.
- `EXECUTION_PENDING`: implementation exists but the relevant real execution has not reached terminal proof.
- `DEFERRED`: intentionally postponed.

Authority invariants:

```text
GitHub + repository + execution evidence = source of truth
handoff != authority
implementation authority != execution authority
PLAN != APPLY
CONSUMED / NEVER REUSE
recoverable technical failure != HUMAN_REQUIRED
operator-surface capability != project-executor capability
CAPABILITY EXISTS != EXECUTOR CURRENTLY ONLINE
DATA_ACTIVATION_AUTHORITY = DISABLED
```

`DATA_ACTIVATION_AUTHORITY = DISABLED` means source, credentials, provisioning or helper existence does not grant persistent activation authority. Every real #914 execution still requires a fresh one-shot authority.

An emitted request ID is consumed regardless of success, failure, cancellation, queueing or transport outcome. A new attempt requires a new request ID.

### Current command dispatch topology (#922 completed)

#922 is **COMPLETED**. Current owner-authored issue commands enter through exactly one top-level listener:

```text
user command
-> .github/workflows/agency-command-dispatch.yml
-> exact syntax classification
-> one current reusable capability
-> capability-owned authorization
```

The dispatcher is a router, **not execution authority**. Actor, issue state, exact identity, replay/JIT and operation-specific authorization remain owned by the selected reusable capability.

Current reusable command workflows are exactly five:

1. `.github/workflows/promote-production.yml`
2. `.github/workflows/production-scheduler-change.yml`
3. `.github/workflows/trusted-editorial-publication.yml`
4. `.github/workflows/trusted-editorial-feature-image.yml`
5. `.github/workflows/preprod-914-governed-successor.yml`

Historical issue-comment listeners removed by #922 are not current capabilities.

## 2. Environment map

| Surface | Role | Code source | Database ownership | Settings ownership | Secrets ownership | Allowed mutations | Forbidden mutations | Execution surface / side effects |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DDEV / local | Development, tests, reproduction | Developer/agent Git checkout | Local DDEV DB only | Local/DDEV-owned | Local only | Local code/data inside task scope | Any upstream DB push; PREPROD/PROD mutation by implication | Developer host or trusted Agency DDEV. External side effects remain fake/null/disabled unless explicitly governed. |
| PREPROD | Production-like release gate and independent refresh target | Exact immutable candidate deployed through PREPROD route | PREPROD-owned independent DB | PREPROD server-owned settings + repository config | PREPROD-only scopes | Exact code deploy; separately authorized PREPROD operations/refresh | PROD mutation; PROD runtime credentials; unsanitized PROD activation | PREPROD deployment route and trusted execution surfaces required by the operation. |
| PROD | Live service and live data authority | Exact validated artifact through governed promotion | PROD-owned live DB | PROD server-owned settings + repository config | PROD-only scopes | Explicit governed production operations only | PREPROD DB promotion; arbitrary DDEV push | Production promotion/editorial/scheduler routes only. |
| GitHub-hosted control / validation | CI, build, dispatcher, authority, metadata-only PLAN, static/synthetic evidence | Exact repository checkout/artifacts | **No raw PROD DB** | Workflow metadata only | Scoped route secrets only after route-specific gates | Repository/build/control plus #914 metadata-only PLAN | Raw PROD materialization/manipulation, SQL/PII evidence | GitHub-hosted `ubuntu-24.04`; raw PROD remains forbidden. |
| Trusted self-hosted Agency executor | Registered trusted surface for DDEV/private/raw-data APPLY | Exact checkout required by route | Only route-authorized data classes | Executor/environment-owned | Governed route-specific secrets | Fixed-purpose route operations | Generic authority, arbitrary PROD/PREPROD mutation | `self-hosted`, `linux`, `x64`, `agency`; current availability is dynamic. |

The self-hosted Agency runner remains a registered/provisioned capability even when physically unavailable. `CAPABILITY REGISTERED != CURRENTLY ONLINE`; availability must be reloaded live. APPLY/raw-data requirements do not move because the runner is temporarily offline.

Runtime references:

- `docs/operations/preproduction.md`
- `docs/deployment.md`
- `docs/operations/environment-side-effects.md`
- `docs/operations/agency-self-hosted-browser-runner.md`
- `docs/operations/execution-capabilities.md`

## 3. Ownership matrix

| Class | Durable owner/source of truth | Promotion/copy rule |
| --- | --- | --- |
| **CODE** | Git + immutable release candidate identity | Build once; PREPROD validates exact bytes; functional PROD promotion consumes the same artifact. |
| **CONFIG** | `config/sync` + approved split directories in Git | `cim`/Config Split converge the deployed release; PREPROD active config is not promoted as a database. |
| **EDITORIAL CONTENT** | Ordinary published editor-owned content lives in Drupal PROD after bounded publication | Never promoted as a database. #576 mutates bounded Article content through Drupal Entity API. |
| **DATABASE DATA** | Each runtime owns its DB; PROD is live source for one-way governed refresh | PROD -> sanitized PREPROD only through #816/#914; PREPROD -> PROD forbidden; Development Seed -> DDEV only when real seed source exists. |
| **ENVIRONMENT SETTINGS** | Each environment's local/server-owned settings | Never copied as another environment's runtime settings. PREPROD hash salt remains PREPROD-owned. |
| **SECRETS** | Environment/route-specific secret store or local developer scope | Never embedded in Git, seed metadata or another environment's runtime credential set. |
| **FILES** | Environment-owned persistent files | Release artifacts exclude runtime files. #914 is DB-only; private files excluded; Development Seed v1 is DB-only. |

```text
Git is not the source of truth for already-published ordinary editorial content.
PREPROD database state is never a promotable PROD artifact.
```

## 4. Four distinct operational flows

### A. CODE / CONFIG

```text
Git / PR
-> CI
-> release/*
-> immutable candidate identity
-> PREPROD exact-artifact deployment
-> PREPROD validation
-> explicit human GO where required
-> agency-command-dispatch.yml
-> promote-production.yml
-> same exact artifact to PROD
-> post-deploy validation
```

Primary sources:

- `.github/workflows/build-release-candidate.yml`
- `.github/workflows/deploy-preproduction.yml`
- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/promote-production.yml`
- `docs/operations/preproduction.md`
- `docs/deployment.md`

### B. DATA REFRESH

```text
fresh one-shot command
-> agency-command-dispatch.yml
-> preprod-914-governed-successor.yml
-> authority validation on GitHub-hosted
-> PLAN on GitHub-hosted ubuntu-24.04 OR separately authorized APPLY on self-hosted
```

PLAN is metadata/readiness-only. APPLY is the only #914 mode that may enter the raw-data path:

```text
PROD read-only dump
-> trusted raw staging isolated BEFORE import
-> Drush sql:sanitize
-> thin Agency sanitization/assertions
-> sanitized SQL only to PREPROD
-> exact PREPROD DB backup
-> bounded maintenance
-> standard DB replacement + Drupal convergence
-> validate
-> COMMITTED or exact backup restore
```

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

Current source state: #914 is `SOURCE_IMPLEMENTED` + `SYNTHETICALLY_PROVEN`. #927 is **CLOSED / COMPLETED** and moved only PLAN execution to GitHub-hosted `ubuntu-24.04`; APPLY remains `[self-hosted, linux, x64, agency]`. JIT-before-secret and pinned PROD/PREPROD SSH trust remain mandatory.

#929 provides `REAL_EXECUTION_PROVEN` evidence for the mutation-free PLAN execution surface: authority and JIT passed, transient SSH identities were materialized only after JIT, the metadata/readiness PLAN executed and returned `FAIL_CLOSED`, SSH identity cleanup succeeded, and APPLY was skipped. No PROD DB content read, PROD snapshot, PROD data transfer, PROD write or PREPROD mutation occurred. The exact failed readiness predicate is **NOT YET PROVEN** and must not be guessed.

#930 is the **CURRENT RECOVERY / IN PROGRESS** repository-only diagnostic tranche. It may make future PLAN failures return bounded metadata-only reason enums, but no #930 behavior is current until separately implemented and merged.

#816 remains OPEN/in-progress. A real full PROD -> PREPROD refresh is **NOT YET PROVEN**.

Never:

```text
PREPROD DB -> PROD
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW PROD DATA ON GITHUB-HOSTED RUNNER = FORBIDDEN
```

### C. EDITORIAL CONTENT

Current:

```text
#576 bounded Article request
-> agency-command-dispatch.yml
-> trusted-editorial-publication.yml
-> inspect
-> canonical payload + SHA-256
-> dry-run
-> exact-hash apply authorization
-> PROD backup
-> Drupal Entity API mutation
-> reload/evidence
```

Ordinary Articles are editor-owned Drupal content, outside Governed Content. Current route: `docs/operations/governed-editorial-publication.md`.

Future #872 Editorial Candidate is `DESIGN_ONLY`: PREPROD preview + exact hash-bound human approval + bounded PROD promotion is future work. There is no PREPROD DB -> PROD editorial route.

### D. DEVELOPMENT DATA

Merged #873 repository contract:

```text
immutable sanitized Development Seed
-> authenticated read-only distribution (future real source)
-> .ddev/providers/agency.yaml
-> ddev pull agency
-> SHA-256 + code compatibility guard
-> DDEV native snapshot/import
-> updb / cim / cr
-> local-only administrator
-> side-effect assertions
```

Current classification:

```text
REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
REPOSITORY_IMPLEMENTATION = SOURCE_IMPLEMENTED
SYNTHETIC_PROOF = COMPLETE
REAL_PREPROD_SEED_GENERATION = PENDING #816
REAL_STORAGE_PROVISIONING = PENDING
REAL_DISTRIBUTION = PENDING
DDEV_PULL = ddev pull agency
DDEV_PUSH = NONE
PUBLIC_FILES = NONE
PRIVATE_FILES = NEVER
```

The provider exists, but a live seed service is not claimed.

## 5. Current capability/status matrix

| Capability | Owner | Current status | Real mutation/data status |
| --- | --- | --- | --- |
| Local DDEV runtime | repository / developer | `SOURCE_IMPLEMENTED` / `EXECUTABLE` | Local only. |
| PREPROD release environment | deployment program | `PROVISIONED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Code/config deployment route proven. |
| Single Agency command dispatcher | #922 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Syntax routing only. |
| Same-artifact PROD promotion | production promotion route | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Exact human GO required. |
| Governed Article publication | #576 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Bounded PROD Article mutation. |
| PROD -> PREPROD refresh source | #914 under #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTABLE` | PLAN executable; full APPLY/end-to-end remains unproven. |
| GitHub-hosted metadata-only PLAN | #927 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | #929 reached the real PLAN and failed closed on readiness; no runtime mutation. |
| PLAN diagnostics recovery | #930 | `DESIGN_ONLY` / `EXECUTION_PENDING` | OPEN/in-progress; no implementation claimed by #871. |
| Editorial Candidate | #872 | `DESIGN_ONLY` | Not implemented. |
| Development Seed repository/DDEV tranche | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Real generation/storage/distribution pending #816. |

Older #907/#874 activation-capability status and #915/#917 transaction/recovery designs are historical implementation lineage, not operational dependencies of current #914.

The #908 validation workflow still protects this historical literal for validator compatibility only; it is **not current #907 architecture**:

```text
HISTORICAL_ONLY / NOT CURRENT:
REAL PROVISIONING APPLY = EXECUTION_PENDING
```

## 6. Code/configuration recipe

### Deploy/validate a release in PREPROD

1. Work through normal issue/branch/PR review.
2. Build the coherent `release/*` candidate with `.github/workflows/build-release-candidate.yml`.
3. Preserve exact candidate Git SHA, artifact SHA-256 and Composer lock identity.
4. `.github/workflows/deploy-preproduction.yml` consumes the existing artifact; PREPROD does not rebuild it.
5. PREPROD attaches its own settings/files, then runs required `updb`, `cim`, PREPROD Config Split/convergence, cache rebuild and runtime/browser validation.

### Promote the same artifact to PROD

1. Reload current candidate/build/PREPROD evidence and live dispatcher route.
2. Use only the current promotion command accepted by `.github/workflows/agency-command-dispatch.yml`.
3. Dispatcher classification must select `promote-production.yml`; downstream authorization remains authoritative.
4. Production consumes the same candidate bytes validated in PREPROD.
5. PROD attaches PROD-owned settings/files and performs governed post-deploy validation.

Code/config rollback is separate from data-refresh rollback.

## 7. PROD -> PREPROD refresh recipe (#816 / current #914)

### Authority boundary

#914 source implementation is not persistent execution authority. A real run requires a fresh owner-created active authority issue under #816 whose marker matches exact live `main`, mode/profile and one fresh request identity. Attempt/replay/stale-main/duplicate mismatches fail closed.

The dispatcher only classifies the command; #914 owns execution authorization.

### Current PLAN

Current `main` executes PLAN on `ubuntu-24.04` / GitHub-hosted after authority validation. Immediately before SSH secret materialization it revalidates live main, checked-out HEAD, exact request/authority and `runner.environment == github-hosted`.

After JIT only, transient PROD/PREPROD SSH identities are materialized under `RUNNER_TEMP`; repository-pinned ED25519 trust is provisioned/verified; PLAN observes bounded metadata/readiness only; identities are removed in cleanup.

PLAN performs no PROD DB content read, PROD snapshot, PROD data transfer, PROD write, PREPROD backup, maintenance, DB/runtime mutation or activation.

#927 is CLOSED / COMPLETED. #929 proves the real hosted PLAN execution surface is reachable, but its readiness result was `FAIL_CLOSED`; the failed predicate is not yet known. #930 is OPEN / IN_PROGRESS to make that failure diagnosable without leaking sensitive output.

### Current APPLY

APPLY remains `[self-hosted, linux, x64, agency]` and requires separate fresh authority. Its current source sequence is:

```text
JIT exact main/authority before secrets
-> trusted Agency runner
-> exact source code in DDEV
-> isolate DDEV web/db on fresh internal network BEFORE raw import
-> PROD read-only Drush sql:dump
-> Drush sql:sanitize
-> Agency sanitizer/assertions
-> sanitized SQL only to PREPROD
-> exact PREPROD Drush backup
-> maint:set 1
-> sql:drop + sql:cli
-> updb + cim + PREPROD split/admin convergence
-> validate-runtime + cr
-> maint:set 0
-> COMMITTED
```

Failure after destructive replacement restores the exact backup and validates it. Proven restore => `ROLLED_BACK`. Unprovable restore => `HUMAN_RECOVERY_REQUIRED` and maintenance stays ON.

No `RECOVER_CURRENT`, `RECOVER_ABORT`, GitHub transaction reconstruction, historical target lookup or near-zero-downtime #915 swap belongs to the current route.

## 8. Privacy and data classification

Preserve for fidelity where present/relevant:

- public/editorial nodes and revisions;
- taxonomy, translations and Paragraphs/entity-reference revisions;
- menu/link content, aliases and redirects;
- public media metadata required by editorial behavior.

Remove/sanitize before PREPROD activation:

- user identity/auth/reset/session material as required;
- Webform submissions/data;
- sessions;
- flood/rate-limit state;
- watchdog/dblog request/user/IP state;
- queues, caches, batch/temp/runtime state;
- persisted provider/API credentials and production-only state.

Hard evidence/privacy rules:

```text
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW SQL AS GITHUB ARTIFACT = FORBIDDEN
PII IN EVIDENCE/LOGS = FORBIDDEN
PRIVATE FILES = EXCLUDED
```

Metadata-only evidence may include request/issue identity, hashes, sizes, aggregate counts and bounded PASS/FAIL state. It must not include raw SQL, copied values, email/IP/PII, credentials, tokens or private files.

## 9. Backup, rollback and recovery

Data refresh does not deploy code and PROD is never part of rollback.

Before destructive PREPROD replacement, #914 APPLY must create and verify an exact protected PREPROD Drush backup. If failure occurs after replacement, restore the exact backup, rebuild/validate runtime, and reopen maintenance only after proof.

```text
restore proven      -> ROLLED_BACK
restore unprovable  -> HUMAN_RECOVERY_REQUIRED
                       maintenance stays ON
```

`recoverable technical failure != HUMAN_REQUIRED`. The #929 PLAN failure is a recoverable technical failure. #930 is current diagnostic recovery work, not a human-recovery boundary.

For DDEV, rollback is DDEV's native snapshot / snapshot restore mechanism.

## 10. Development Seed / DDEV

Start local development from repository state:

```text
git clone <repository>
cd agency-website-drupal
ddev start
```

Developers/agents do **not** need PROD credentials.

The merged #873 consumer contract supports:

```text
ddev pull agency
```

only when the real read-only seed source is later provisioned. Current repository implementation already includes SHA-256 verification, same-or-seed-ancestor compatibility, local snapshot/restore, local convergence/admin creation and side-effect assertions.

No provider push stanza exists:

```text
DDEV_PUSH = NONE
DDEV -> PREPROD = FORBIDDEN
DDEV -> PROD = FORBIDDEN
```

Real PREPROD seed generation remains pending #816. Real storage/distribution remains pending separate provisioning. Do not present onboarding as though a live seed service already exists.

## 11. Editorial publication boundary

Current #576 bounded Article publication is separate from code/config promotion, PREPROD DB refresh and Governed Content. It uses exact payload/hash/idempotence/dry-run/apply evidence and bounded PROD Drupal Entity API mutation.

#872 Editorial Candidate remains future / `DESIGN_ONLY`. #871 does not design or implement it.

## 12. Settings, Config Split and secrets ownership

```text
PROD settings/secrets     = PROD-owned
PREPROD settings/secrets  = PREPROD-owned
DDEV settings/secrets     = local-owned
```

No environment receives another environment's runtime credential. PREPROD hash salt remains PREPROD-owned. Development Seed contains no PREPROD runtime credential.

Config Split remains environment-specific. Data refresh must converge the code already deployed in PREPROD; it does not promote PREPROD active config or server settings into PROD.

## 13. Failure classification

Use three distinct outcomes:

1. **recoverable technical failure** — CI/tool/runner/transport/readiness defect still diagnosable within project authority;
2. **runtime operation terminal outcome** — e.g. `COMMITTED` or proven `ROLLED_BACK`;
3. **`HUMAN_RECOVERY_REQUIRED`** — automatic safe rollback cannot be proven after destructive PREPROD replacement.

A temporarily offline registered runner is an availability fact, not architectural removal and not `HUMAN_REQUIRED` by itself.

## 14. Evidence, request IDs and replay

Evidence must be durable enough to identify exact code/authority/run while remaining non-sensitive. Do not hardcode transient run IDs as operational instructions; use the governing issue/PR and GitHub run evidence.

Every emitted one-shot request ID becomes:

```text
CONSUMED / NEVER REUSE
```

#929's emitted PLAN identities are consumed. Future PLAN/APPLY requires fresh authority and fresh identity.

## 15. Onboarding, handoff and rebaseline

A safe onboarding/handoff should answer:

- source of truth: live GitHub + repository + execution evidence;
- local start: repository + DDEV;
- safe realistic data: #873 contract exists, real seed source still pending #816/provisioning;
- PROD credentials for developers: **NO**;
- DDEV upstream push: **NO**;
- current command topology: single dispatcher + five reusable workflows;
- PLAN runner: GitHub-hosted `ubuntu-24.04`;
- APPLY/raw data: self-hosted Agency runner only under current #914;
- environment settings/secrets: environment-owned;
- #816 full refresh: not yet proven;
- request IDs: consumed/never reuse;
- handoff state: always reload before repeating it.

After a merge, rebaseline by reloading `main`, merge commit/tree, governing issues/PRs and exact post-merge validations. Never infer current state from a prior handoff alone.

## 16. Authoritative links

- `AGENTS.md`
- `docs/decisions/ADR-003-use-existing-first.md`
- `docs/operations/execution-capabilities.md`
- `docs/operations/preproduction.md`
- `docs/deployment.md`
- `docs/operations/preproduction-data-refresh.md`
- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/environment-side-effects.md`
- `docs/operations/governed-editorial-publication.md`
- `docs/operations/development-seed.md`
- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/preprod-914-governed-successor.yml`
- `.ddev/providers/agency.yaml`

Primary current-state rule: implementation and live execution evidence win over historical prose. #915/#917 and other predecessor designs are not operational dependencies of #914.
