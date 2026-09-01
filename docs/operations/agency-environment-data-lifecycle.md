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
- `EXECUTABLE`: a real governed route is available, subject to its own fresh authority and runtime checks.
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

`DATA_ACTIVATION_AUTHORITY = DISABLED` means source, credentials, provisioning or helper existence does not grant a persistent data-activation privilege. A real #914 run still requires a fresh valid one-shot authority.

An emitted one-shot request ID is consumed regardless of success, failure, cancellation, queueing or transport outcome. A new attempt requires a new valid request identity.

### Current command dispatch topology (#922 completed)

#922 is **COMPLETED**. Current owner-authored issue commands enter through exactly one top-level listener:

```text
user command
-> .github/workflows/agency-command-dispatch.yml
-> exact syntax classification
-> one current reusable capability
-> capability-owned authorization
```

The dispatcher is a router, **not execution authority**. Its classifier performs syntax selection only; actor, issue state, exact identity, replay/JIT and other authorization rules remain owned by the reusable capability.

Current reusable command workflows are exactly five:

1. `.github/workflows/promote-production.yml`
2. `.github/workflows/production-scheduler-change.yml`
3. `.github/workflows/trusted-editorial-publication.yml`
4. `.github/workflows/trusted-editorial-feature-image.yml`
5. `.github/workflows/preprod-914-governed-successor.yml`

Historical command listeners removed by #922 are not current capabilities merely because old issues, docs or runs still exist.

## 2. Environment map

| Surface | Role | Code source | Database ownership | Settings ownership | Secrets ownership | Allowed mutations | Forbidden mutations | Execution surface / side effects |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DDEV / local | Development, tests, reproduction | Developer/agent Git checkout | Local DDEV DB only | Local/DDEV-owned | Local only | Local code/data inside task scope | Any upstream DB push; PREPROD/PROD mutation by implication | Developer host or trusted Agency DDEV. Mailpit/fake/null; external egress off unless explicitly governed. |
| PREPROD | Production-like release gate and independent refresh target | Exact immutable candidate deployed through the PREPROD route | PREPROD-owned independent DB | PREPROD server-owned shared settings + repository config | PREPROD-only scopes | Exact code deployment; separately authorized PREPROD operations/refresh | PROD mutation; PROD runtime credentials; unsanitized PROD DB activation | PREPROD deployment route and trusted surfaces required by the selected operation. Side effects hardened; Basic Auth/noindex preserved. |
| PROD | Live service and live data authority | Exact validated artifact promoted through governed production route | PROD-owned live DB | PROD server-owned shared settings + repository config | PROD-only scopes | Explicit governed production operations only | PREPROD DB promotion; refresh writes from #816; arbitrary DDEV push | Production promotion/editorial/scheduler routes. Real side effects only where explicitly intended. |
| GitHub-hosted control / validation | CI, build, command dispatch, authority validation, metadata/static/synthetic evidence | Exact repository checkout/artifacts | **No raw PROD DB** | Workflow metadata only | Scoped secrets only in governed routes | Repository/build/control and metadata-only operations explicitly encoded by the route | Raw PROD data materialization/manipulation, SQL/PII evidence, widening trust because another runner is unavailable | GitHub-hosted runners. Metadata-only validation may be appropriate; raw PROD remains forbidden. |
| Trusted self-hosted Agency executor | Registered trusted execution surface for DDEV/private/raw-data work | Exact checkout required by the route | Only data classes authorized by that route | Executor/environment-owned | Governed route-specific secrets | Fixed-purpose route operations | Generic authority, arbitrary PROD/PREPROD mutation, credential reuse across environments | `agency-browser-runner-01` on `preflight-runner-01`, account `agency-runner`; availability is dynamic. |

The Agency self-hosted runner remains a **registered/provisioned capability** even when physically unavailable. Capability registration and current online/busy state are separate facts.

Runtime references:

- `docs/operations/preproduction.md`
- `docs/deployment.md`
- `docs/operations/environment-side-effects.md`
- `docs/operations/agency-self-hosted-browser-runner.md`
- `docs/operations/execution-capabilities.md`

## 3. Ownership matrix

| Class | Durable owner/source of truth | Promotion/copy rule |
| --- | --- | --- |
| **CODE** | Git + immutable release candidate identity | Build once; PREPROD validates exact bytes; functional PROD promotion consumes the same exact artifact. |
| **CONFIG** | `config/sync` + approved split directories in Git; active environment selection remains environment-specific | `cim`/Config Split converge the deployed release. PREPROD active config is not promoted to PROD. |
| **EDITORIAL CONTENT** | For ordinary published editor-owned content: Drupal PROD after successful publication | Never promoted as a database. #576 mutates bounded Article content through Drupal Entity API. Governed Content is a separate product-owned baseline class. |
| **DATABASE DATA** | Each runtime owns its own DB; PROD is live source for the governed one-way refresh | PROD -> sanitized PREPROD only through #816/#914; PREPROD -> PROD is forbidden; Development Seed -> DDEV only when the real seed source is provisioned. |
| **ENVIRONMENT SETTINGS** | Each environment's local/server-owned settings | Never copied as another environment's runtime settings. PREPROD hash salt remains PREPROD-owned. |
| **SECRETS** | Environment/route-specific secret store or local developer scope | Never embedded in Git, seed metadata or another environment's runtime credential set. |
| **FILES** | Environment-owned persistent files | Release artifacts exclude runtime files. #914 DB refresh does not copy public files; private files are excluded. Development Seed v1 is database-only. |

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
-> PREPROD runtime + browser validation
-> explicit human GO where required
-> agency-command-dispatch.yml
-> promote-production.yml reusable capability
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
-> preprod-914-governed-successor.yml reusable capability
-> authority/JIT checks
-> PROD read-only
-> transient raw dump on trusted surface only
-> DDEV raw staging isolated BEFORE import
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

**Current evidence boundary:** #914 is `SOURCE_IMPLEMENTED` + `SYNTHETICALLY_PROVEN`. #816 remains open and no complete real PROD -> PREPROD refresh has terminal end-to-end proof.

Current `main` still runs both #914 PLAN and APPLY on the trusted Agency self-hosted runner after GitHub-hosted authority validation. #927 is a **PENDING / PROPOSED ADAPTATION** to move only mutation-free metadata PLAN execution to GitHub-hosted; it is not current repository behavior until separately implemented and merged.

Never:

```text
PREPROD DB -> PROD
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
```

### C. EDITORIAL CONTENT

Current:

```text
#576 bounded Article request
-> agency-command-dispatch.yml
-> trusted-editorial-publication.yml reusable capability
-> inspect
-> canonical payload + SHA-256
-> dry-run
-> exact-hash apply authorization
-> PROD backup
-> Drupal Entity API mutation
-> reload/evidence
```

Ordinary Articles are editor-owned Drupal content, outside Governed Content. The current route is documented in `docs/operations/governed-editorial-publication.md`.

Future #872 Editorial Candidate is `DESIGN_ONLY`: PREPROD preview + exact hash-bound human approval + bounded PROD promotion is a future capability. There is no PREPROD DB -> PROD editorial route.

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
REPOSITORY_IMPLEMENTATION = SOURCE_IMPLEMENTED
SYNTHETIC_PROOF = SYNTHETICALLY_PROVEN
REAL_PREPROD_SEED_GENERATION = EXECUTION_PENDING
REAL_STORAGE_PROVISIONING = EXECUTION_PENDING
REAL_DISTRIBUTION = EXECUTION_PENDING
DDEV_PUSH = NONE
PUBLIC_FILES = NONE
PRIVATE_FILES = NEVER
```

The provider exists, but a live seed service is not claimed. Real generation depends on #816's safe source boundary; storage/distribution requires separate provisioning authority.

## 5. Current capability/status matrix

| Capability | Owner | Current status | Real mutation/data status |
| --- | --- | --- | --- |
| Local DDEV Drupal runtime | repository / developer | `SOURCE_IMPLEMENTED` / `EXECUTABLE` | Local only. |
| PREPROD release environment | release/deployment program | `PROVISIONED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Code/config deployment route proven; independently owned DB/settings/secrets. |
| Single Agency command dispatcher | #922 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Syntax routing only; grants no downstream authority. |
| Same-artifact functional PROD promotion | production promotion route | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Routed through dispatcher; requires exact human GO. |
| Governed Article publication #576 | #576 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Routed through dispatcher; bounded PROD Article mutation only. |
| PROD -> PREPROD refresh implementation | #914 under #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTION_PENDING` | Current PLAN/APPLY reusable route exists; no terminal full real #816 refresh proof. |
| PLAN GitHub-hosted adaptation | #927 | `DESIGN_ONLY` / `EXECUTION_PENDING` | **PENDING / PROPOSED ADAPTATION**; current PLAN remains self-hosted. |
| Editorial Candidate | #872 | `DESIGN_ONLY` | No current implementation/execution authority. |
| Development Seed repository/DDEV tranche | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Real PREPROD generation/storage/distribution pending. |

The previous `#873 = DESIGN_ONLY` classification is obsolete. Older #907/#874 activation-capability status and #915/#917 transaction/recovery designs are historical implementation lineage, not the current #914 operational route.

The still-existing #908 validation workflow checks the following historical literal. It is retained **only as labelled validator compatibility**, not as current #907 state:

```text
HISTORICAL_ONLY / NOT CURRENT:
REAL PROVISIONING APPLY = EXECUTION_PENDING
```

Current operation has no pending #907 provisioning dependency.

## 6. Code/configuration recipe

### Build and validate a release in PREPROD

1. Work through the normal issue/branch/PR path and merge only reviewed work.
2. Create/update the coherent `release/*` candidate according to the release process.
3. `.github/workflows/build-release-candidate.yml` checks out the exact SHA, installs production Composer dependencies once and creates one immutable archive.
4. Candidate identity combines candidate Git SHA, artifact SHA-256, composer.lock SHA-256 and builder provenance.
5. The artifact excludes environment-owned `settings.php`, persistent public files, `.ddev` state and secrets.
6. `.github/workflows/deploy-preproduction.yml` consumes the existing artifact; PREPROD does not rebuild it.
7. PREPROD attaches its own shared settings/files, creates its own DB backup when applicable, runs `updb`, `cim`, PREPROD Config Split/convergence and cache rebuild, then validates runtime, side effects, health and browser behavior.

### Promote the same artifact to PROD

1. Reload current candidate/build/PREPROD evidence and live dispatcher/reusable promotion workflow.
2. Use only the exact current production promotion command accepted by `.github/workflows/agency-command-dispatch.yml`.
3. Dispatcher classification must select only `promote-production.yml`; downstream promotion authorization remains authoritative.
4. The production capability must consume the same candidate bytes that PREPROD validated.
5. PROD attaches PROD-owned settings/files, creates the pre-promotion DB backup, runs release convergence and post-deploy validation.
6. Ordinary merge/push to `main` is not by itself functional PROD deployment authority.

Code/config rollback is distinct from data refresh rollback. `docs/operations/preproduction.md` and `docs/deployment.md` own deployment-specific recovery details.

## 7. PROD -> PREPROD refresh recipe (#816 / current #914)

### Authority boundary

The merged #914 implementation is not execution authority. A real run requires a fresh owner-created active authority issue under #816 whose canonical marker matches exact live main and exactly one requested mode.

The current validator accepts `PLAN` or `APPLY`, attempt 1 only. It rejects replay/rerun, stale main, duplicate request identity and trigger/marker mismatch.

The owner command is first classified by `agency-command-dispatch.yml`; the reusable #914 workflow then performs its own authorization. Dispatcher routing never substitutes for the #914 authority contract.

### Current PLAN

Current `main` executes PLAN on `[self-hosted, linux, x64, agency]` after the GitHub-hosted authority job. PLAN is metadata/readiness-only and creates no PROD snapshot, raw staging import, PREPROD backup, maintenance state or PREPROD DB mutation.

#926 is closed `NOT_PLANNED`; its request IDs are consumed and it is not an operational route. #927 is the pending implementation issue for a possible GitHub-hosted metadata-only PLAN. Until #927 is implemented and merged, **do not document or operate PLAN as GitHub-hosted**.

The durable boundary is independent of #927:

```text
metadata-only validation may execute on GitHub-hosted when a current route explicitly implements it
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
```

### APPLY — current source implementation

Only when separately authorized:

1. Revalidate live `main` and exact one-shot authority before SSH secrets are materialized.
2. Execute raw-data work only on the trusted self-hosted Agency surface.
3. Prepare exact source release in DDEV; isolate web/db onto an internal-only Docker network **before raw import**.
4. Obtain PROD via reviewed read-only Drush `sql:dump`; PROD is never mutated.
5. Keep raw SQL only in protected transient trusted storage / isolated DDEV; never GitHub-hosted, logged or uploaded.
6. Run Drush `sql:sanitize`, then thin Agency sanitizer/assertions.
7. Export sanitized SQL, delete raw transient material and transfer sanitized SQL only to PREPROD.
8. Launch detached non-root PREPROD worker; it owns terminal transaction independently of runner/SSH session.
9. Acquire deploy lock and create non-empty SHA-256 verified PREPROD Drush backup before destructive replacement.
10. Enable bounded maintenance; standard `sql:drop` + `sql:cli`; `updb`, `cim`, PREPROD split/admin convergence, cache/runtime validation; disable maintenance and report `COMMITTED`.
11. Failure after destructive replacement restores exact backup and validates it before maintenance is disabled: `ROLLED_BACK`.
12. If restore/validation cannot be proven, maintenance stays ON and terminal result is `HUMAN_RECOVERY_REQUIRED`.

No GitHub transaction reconstruction, `RECOVER_CURRENT`, `RECOVER_ABORT`, historical target reconstruction or near-zero-downtime swap belongs to the current route. #915/#917 are not operational dependencies.

## 8. Privacy and data classification

### Preserve for fidelity when present and required

- public/editorial nodes and revisions;
- translations;
- taxonomy and references;
- Paragraphs/entity-reference revisions;
- menu/link content where needed;
- aliases/redirects;
- public media metadata required for editorial fidelity.

### Remove, clear or sanitize before PREPROD eligibility

- Webform submissions/data;
- active sessions;
- flood/rate-limit state;
- watchdog/dblog request/user/IP state;
- externally acting queues;
- cache/render/discovery state;
- batch/temp/runtime state;
- sensitive user names/emails and authentication/reset/session material;
- persisted provider/API credentials and production-only state.

Sanitization stack:

```text
Drush sql:sanitize
-> thin Agency project-specific sanitizer
-> fail-closed assertions
```

### Raw PROD boundary

```text
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW SQL AS GITHUB ARTIFACT = FORBIDDEN
PII IN EVIDENCE/LOGS = FORBIDDEN
PRIVATE FILES = EXCLUDED
```

Raw PROD is trusted-only and transient. Evidence may contain request/run identity, hashes, sizes/counts and PASS/FAIL statuses, never values from sensitive records.

## 9. Backup, rollback and recovery

### PREPROD data refresh

- exact PREPROD DB backup is created and verified before destructive DB replacement;
- PROD is read-only and never part of rollback;
- failure before destructive replacement means no PREPROD runtime DB mutation;
- failure after replacement restores exact PREPROD backup and validates it;
- maintenance reopens only after rollback/runtime state is proven;
- unprovable rollback = `HUMAN_RECOVERY_REQUIRED`, maintenance stays ON.

This is standard DB backup/restore, not GitHub transaction reconstruction.

### Code/config promotion

Use previous-release and DB backup evidence described by `docs/operations/preproduction.md` / `docs/deployment.md`. Code rollback alone is not a universal DB rollback after schema/config/content mutation.

### Editorial #576

Bounded apply verifies a production SQL backup before first Article mutation. Recovery remains owned by that route and never becomes whole-DB promotion.

### DDEV

Development Seed consumption uses DDEV's native snapshot before pull and native snapshot restore for local recovery. No custom upstream rollback/push framework exists.

## 10. Development Seed / DDEV

Current local baseline in `.ddev/config.yaml`:

```text
Drupal 11
PHP 8.4
MariaDB 11.8
Composer 2
```

Normal local startup:

```text
ddev start
```

#873 merged the repository source contract:

- `.ddev/providers/agency.yaml`;
- `.ddev/config.development-seed.yaml`;
- `scripts/development-seed/`;
- `docs/operations/development-seed.md`.

Consumer command:

```text
ddev pull agency
```

Real remote consumption is **not** currently claimed available. Future local configuration needs separately provisioned read-only seed-distribution storage/identity; it must not be a PROD credential or PREPROD runtime credential.

The v1 contract is DB-only, public files are not bundled, private files are never distributed, and the provider has no push stanza:

```text
Development Seed -> DDEV = allowed once real distribution is provisioned
DDEV -> seed store       = forbidden
DDEV -> PREPROD          = forbidden
DDEV -> PROD             = forbidden
DDEV_PUSH                = NONE
```

Compatibility is same release or seed-release ancestor; unsupported downgrade/divergence fails closed. SHA-256 is checked before import. Local convergence uses normal `updb`/`cim`/`cr`, local-only admin creation and side-effect assertions.

## 11. Editorial publication boundary

The current #576 Article route is a specialized production content mutation surface, not deployment, data refresh or Governed Content.

Current commands are the three `/agency-editorial ...` commands defined by the **current dispatcher** and documented in `docs/operations/governed-editorial-publication.md`. The dispatcher routes the command to `.github/workflows/trusted-editorial-publication.yml`; authorization remains inside that capability.

`dry-run` is read-only and binds canonical payload SHA-256. `apply` requires exact prior dry-run/live-main identity, runs a fresh preflight, verifies a backup before mutation and writes only the bounded Article profile through Drupal Entity API.

Published ordinary editorial content remains editor-owned in PROD Drupal. GitHub is request/audit control, not editorial source of truth.

#872 Editorial Candidate is future/not implemented by #871. Its intended PREPROD preview and exact approved-hash promotion must extend this bounded philosophy without creating a PREPROD DB -> PROD path.

## 12. Settings, Config Split and secrets ownership

- `config/sync/config_split.config_split.production.yml` and `config/sync/config_split.config_split.preproduction.yml` are stored OFF by default.
- PREPROD server-owned settings force production split OFF and preproduction split ON, automated cron OFF, production analytics OFF, null/native mail without production credentials and AI egress OFF.
- PROD shared settings activate PROD-specific runtime/config boundary.
- PREPROD hash salt and DB credentials remain PREPROD-owned.
- PROD settings/secrets remain PROD-owned.
- DDEV/local settings/secrets remain local-owned.

No environment receives another environment's runtime credential. Development Seed contains no PREPROD runtime credential.

## 13. Failure classification

Use `HUMAN_RECOVERY_REQUIRED` only when automatic safe recovery cannot be proven.

Normally recoverable technical conditions include CI/test failure, stale branch/main mismatch, runner temporarily offline/busy, transport failure before destructive work, consumed/invalid one-shot authority requiring a fresh identity, and deterministic script defects inside Delivery authority.

`recoverable technical failure != HUMAN_REQUIRED`.

Do not widen credentials, move raw PROD data onto GitHub-hosted infrastructure or resurrect historical executors/commands to work around an ordinary recoverable failure.

## 14. Evidence, request IDs and replay

For every governed operation:

1. reload live `main`, issue/PR and current route;
2. bind exact repository/release/request identities required by the route;
3. keep evidence metadata-only;
4. distinguish queued/in-progress from terminal outcomes;
5. never treat PLAN as APPLY;
6. never reuse an emitted request ID.

Safe evidence may include Git/release SHA, request/run identity, hashes/sizes, aggregate counts without values, bounded backup identity and validation statuses. Raw SQL, PII, passwords, sessions/reset data, private files and secret values are forbidden.

## 15. Onboarding, handoff and rebaseline

- **Source of truth?** GitHub + repository + execution evidence; never a handoff alone.
- **How do I start locally?** Read `AGENTS.md`, ADR-003, this page and `docs/operations/execution-capabilities.md`, then use DDEV.
- **How do I obtain safe realistic data?** #873 source/synthetic pull contract exists, but real seed generation/storage/distribution remains pending. Do not seek PROD credentials as a workaround.
- **Do I need PROD credentials for DDEV?** No.
- **Can DDEV push to PREPROD/PROD?** No.
- **Who owns settings/secrets?** Each environment owns its own.
- **Where do owner commands enter?** The single current `.github/workflows/agency-command-dispatch.yml` listener.
- **Does dispatch grant authority?** No; the selected reusable capability revalidates its own authority.
- **What is real vs synthetic?** Use the capability matrix and fresh live evidence.
- **How do I avoid replay?** Emitted one-shot IDs are `CONSUMED / NEVER REUSE`.
- **What happened to historical configuration-language commands?** They are not part of the current five-command dispatcher and must not be reintroduced as current capability just to satisfy old tests/docs.

A safe handoff includes last reloaded main SHA/tree, exact issue/PR/run/head identities, terminal/non-terminal evidence, consumed IDs, current allowed/forbidden operations and STOP boundary; it is explicitly non-authoritative.

After another PR merges:

```text
reload main
-> integrate current main into the same issue branch if it moved
-> re-audit current dispatcher/workflow/script paths
-> rerun exact-head validation
```

## 16. Authoritative links

Cross-environment:

- `AGENTS.md`
- `docs/decisions/ADR-003-use-existing-first.md`
- `docs/operations/execution-capabilities.md`
- `docs/operations/preproduction.md`
- `docs/deployment.md`
- `docs/operations/environment-side-effects.md`

Command control:

- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/promote-production.yml`
- `.github/workflows/production-scheduler-change.yml`
- `.github/workflows/trusted-editorial-publication.yml`
- `.github/workflows/trusted-editorial-feature-image.yml`
- `.github/workflows/preprod-914-governed-successor.yml`

Code/config:

- `.github/workflows/build-release-candidate.yml`
- `.github/workflows/deploy-preproduction.yml`

Data refresh:

- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`
- `scripts/preproduction-refresh/sanitization-policy.json`

Editorial:

- `docs/operations/governed-editorial-publication.md`

Development data:

- `docs/operations/development-seed.md`
- `.ddev/providers/agency.yaml`
- `.ddev/config.development-seed.yaml`
- `scripts/development-seed/sanitization-policy.json`

This page contains no secret values, raw data, hard-coded historical run ID or execution authority. Dynamic state must always be reloaded live.