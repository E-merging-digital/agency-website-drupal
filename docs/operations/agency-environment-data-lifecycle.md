# Agency Environment & Data Lifecycle

Status: **CANONICAL CURRENT-STATE OPERATIONAL ENTRYPOINT**
Owner: #871 — tranche 1 while #816 real end-to-end execution remains pending.
Architecture rule: `docs/decisions/ADR-003-use-existing-first.md`.

This document is the first operational page to read for Agency environments, code/configuration promotion, PROD-to-PREPROD data refresh, editor-owned publication and safe development data. It links to specialized contracts instead of duplicating their internals.

GitHub, the versioned repository and execution evidence are authoritative. A handoff, an old issue description or this document's status wording never replaces a fresh live reload before approval, execution, mutation or merge.

## 1. Purpose, authority and status vocabulary

Agency uses existing Drupal, Drush, DDEV and system primitives first. Future changes must apply the ADR-003 order:

```text
Drupal Core
-> Drush / Drupal APIs
-> DDEV
-> stable supported contrib when useful
-> standard system tools
-> minimal Agency extension only for a demonstrated gap
```

Use these status terms narrowly:

- `DESIGN_ONLY`: intent exists; no repository implementation is claimed.
- `SOURCE_IMPLEMENTED`: versioned implementation exists.
- `SYNTHETICALLY_PROVEN`: source/static/synthetic tests prove the stated contract without claiming a real environment execution.
- `PROVISIONED`: required runtime infrastructure/credential surface has been installed and proven at the stated boundary.
- `EXECUTABLE`: a real governed route is available, subject to its own fresh authority and runtime checks.
- `REAL_EXECUTION_PROVEN`: a real execution reached terminal evidence at the stated boundary.
- `EXECUTION_PENDING`: implementation exists but the relevant real execution has not reached terminal proof.
- `DEFERRED`: intentionally postponed.

Do not collapse these states into an ambiguous `ready` or generic `proven` claim.

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

`DATA_ACTIVATION_AUTHORITY = DISABLED` means that no durable/persistent data-activation privilege exists merely because source, secrets, provisioning or a helper exists. A fresh separately valid one-shot APPLY authority is still required by the current #914 route.

An emitted one-shot request ID is consumed regardless of success, failure, cancellation or transport outcome. A fresh attempt needs a fresh separately valid request identity.

## 2. Environment map

| Surface | Role | Code source | Database ownership | Settings ownership | Secrets ownership | Allowed mutations | Forbidden mutations | Execution surface / side effects |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DDEV / local | Development, tests, reproduction | Developer/agent Git checkout | Local DDEV DB only | Local/DDEV-owned | Local only | Local code/data inside task scope | Any upstream DB push; PREPROD/PROD mutation by implication | Developer host or trusted Agency DDEV. Mailpit/fake/null; external egress off unless explicitly governed. |
| PREPROD | Production-like release gate and independent refresh target | Exact immutable candidate deployed through the PREPROD route | PREPROD-owned independent DB | PREPROD server-owned shared settings + repository config | PREPROD-only scopes | Exact code deployment; separately authorized PREPROD operations/refresh | PROD mutation; use of PROD runtime credentials; unsanitized PROD DB activation | PREPROD deployment route and, for raw refresh work, trusted Agency executor. Side effects hardened; Basic Auth/noindex preserved. |
| PROD | Live service and live data authority | Exact validated artifact promoted through governed production route | PROD-owned live DB | PROD server-owned shared settings + repository config | PROD-only scopes | Explicit governed production operations only | PREPROD DB promotion; refresh writes from #816; arbitrary DDEV push | Production promotion/editorial/scheduler routes. Real side effects only where explicitly intended. |
| GitHub-hosted control / validation | CI, build, authority validation, metadata/static/synthetic evidence | Exact repository checkout/artifacts | **No raw PROD DB** | Workflow metadata only | Scoped GitHub secrets only in governed routes | Repository/build/control operations encoded by the route | Raw PROD data materialization, SQL/PII evidence, widening trust because a runner is unavailable | GitHub-hosted runners. Raw PROD data is forbidden. |
| Trusted self-hosted Agency executor | Trusted execution requiring DDEV/private/raw-data surface | Exact checkout required by the route | Only data classes authorized by that route | Executor/environment-owned | Governed route-specific secrets | Fixed-purpose route operations | Generic authority, arbitrary PROD/PREPROD mutation, credential reuse across environments | `agency-browser-runner-01` on `preflight-runner-01`, account `agency-runner`; online/busy state is dynamic. |

Runtime references:

- `docs/operations/preproduction.md`
- `docs/deployment.md`
- `docs/operations/environment-side-effects.md`
- `docs/operations/agency-self-hosted-browser-runner.md`
- `docs/operations/execution-capabilities.md`

## 3. Ownership matrix

The following classes are deliberately separate.

| Class | Durable owner/source of truth | Promotion/copy rule |
| --- | --- | --- |
| **CODE** | Git + immutable release candidate identity | Build once; PREPROD validates exact bytes; functional PROD promotion consumes the same exact artifact. |
| **CONFIG** | `config/sync` + approved split directories in Git; active environment selection remains environment-specific | `cim`/Config Split converge the deployed release. PREPROD active config is not promoted to PROD. |
| **EDITORIAL CONTENT** | For ordinary published editor-owned content: Drupal PROD after successful publication | Never promoted as a database. #576 mutates bounded Article content through Drupal Entity API. Governed Content is a separate product-owned baseline class. |
| **DATABASE DATA** | Each runtime owns its own DB; PROD is live source for the governed one-way refresh | PROD -> sanitized PREPROD only through #816/#914; PREPROD -> PROD is forbidden; Development Seed -> DDEV only when the real seed source is provisioned. |
| **ENVIRONMENT SETTINGS** | Each environment's local/server-owned settings | Never copied as another environment's runtime settings. PREPROD hash salt remains PREPROD-owned. |
| **SECRETS** | Environment/route-specific secret store or local developer scope | Never embedded in Git, DB seed metadata or another environment's runtime credential set. |
| **FILES** | Environment-owned persistent files | Release artifacts exclude runtime files. #914 DB refresh does not copy public files; private files are excluded. Development Seed v1 is database-only. |

Two important consequences:

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
-> same exact artifact to PROD
-> post-deploy validation
```

Primary sources:

- `.github/workflows/build-release-candidate.yml`
- `.github/workflows/deploy-preproduction.yml`
- `.github/workflows/promote-production.yml`
- `docs/operations/preproduction.md`
- `docs/deployment.md`

### B. DATA REFRESH

```text
PROD read-only
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

- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `.github/workflows/preprod-914-governed-successor.yml`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`

**Current evidence boundary:** #914 is `SOURCE_IMPLEMENTED` + `SYNTHETICALLY_PROVEN`. #816 remains open and the complete real PROD -> PREPROD PLAN/APPLY lifecycle has not reached terminal end-to-end real proof. Do not state otherwise without fresh evidence.

Never:

```text
PREPROD DB -> PROD
raw PROD -> GitHub-hosted runner/artifact/log
```

### C. EDITORIAL CONTENT

Current:

```text
#576 bounded Article request
-> inspect
-> canonical payload + SHA-256
-> dry-run
-> exact-hash apply authorization
-> PROD backup
-> Drupal Entity API mutation
-> reload/evidence
```

Ordinary Articles are editor-owned Drupal content, outside Governed Content. The current route is documented in `docs/operations/governed-editorial-publication.md`.

Future #872 Editorial Candidate is `DESIGN_ONLY`: PREPROD preview + exact hash-bound human approval + bounded PROD promotion is a future capability. #871 does not implement or authorize it. There is no PREPROD DB -> PROD editorial route.

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

The provider exists, but a real seed service must **not** be presented as available until #816 establishes the source boundary and a separate authority provisions read-only storage/distribution.

## 5. Current capability/status matrix

| Capability | Owner | Current status | Real mutation/data status |
| --- | --- | --- | --- |
| Local DDEV Drupal runtime | repository / developer | `SOURCE_IMPLEMENTED` / `EXECUTABLE` | Local only. |
| PREPROD release environment | release/deployment program | `PROVISIONED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Code/config deployment route proven; independently owned DB/settings/secrets. |
| Same-artifact functional PROD promotion | production promotion route | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Requires its own exact human GO. |
| Governed Article publication #576 | #576 | `SOURCE_IMPLEMENTED` / `EXECUTABLE` / `REAL_EXECUTION_PROVEN` | Bounded PROD Article mutation only. |
| PROD -> PREPROD refresh implementation | #914 under #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTION_PENDING` | No terminal full real #816 refresh proof yet. |
| Editorial Candidate | #872 | `DESIGN_ONLY` | No current implementation/execution authority. |
| Development Seed repository/DDEV tranche | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Real PREPROD generation/storage/distribution pending. |

The previous `#873 = DESIGN_ONLY` classification is obsolete and must not be reused. Likewise, older #907/#874 activation-capability status is historical implementation lineage, not the current #914 operational route.

A pre-#914 validation workflow still checks the literal below. It is retained **only as explicitly labelled historical context** and must not be interpreted as a current status or instruction:

```text
HISTORICAL_ONLY / NOT CURRENT:
REAL PROVISIONING APPLY = EXECUTION_PENDING
```

Current operation is #914 source/synthetic with fresh one-shot #816 authority; there is no pending #907 provisioning step in the current route.

## 6. Code/configuration recipe

### Build and validate a release in PREPROD

1. Work through the normal issue/branch/PR path and merge only reviewed work.
2. Create/update the coherent `release/*` candidate according to the release process.
3. `.github/workflows/build-release-candidate.yml` checks out the exact SHA, installs production Composer dependencies once and creates one immutable archive.
4. Candidate identity is the combination of candidate Git SHA, artifact SHA-256, composer.lock SHA-256 and successful builder provenance.
5. The artifact excludes environment-owned `settings.php`, persistent public files, `.ddev` state and secrets.
6. `.github/workflows/deploy-preproduction.yml` consumes the existing artifact; PREPROD does not rebuild it.
7. PREPROD attaches its own shared settings/files, creates its own DB backup when applicable, runs the release's `updb`, `cim`, PREPROD Config Split/convergence and cache rebuild, then validates runtime, side effects, health and browser behavior.

### Promote the same artifact to PROD

1. Reload the current candidate/build/PREPROD evidence and production promotion route.
2. Use only the exact owner GO syntax currently accepted by the production promotion authority; bind the GO to the exact candidate/artifact/composer/PREPROD evidence.
3. The production route must consume the same candidate bytes that PREPROD validated.
4. PROD attaches **PROD-owned** settings/files, creates the pre-promotion DB backup, runs the release convergence (`updb`, `cim`, production Config Split and other release-owned steps), then post-deploy validation.
5. Ordinary merge/push to `main` is not by itself a functional PROD deployment authority.

Code/config rollback is distinct from data refresh rollback. `docs/operations/preproduction.md` and `docs/deployment.md` own the deployment-specific recovery details.

## 7. PROD -> PREPROD refresh recipe (#816 / current #914)

### Authority boundary

The merged #914 implementation is not execution authority. A real run requires a **fresh**, owner-created, active authority issue under #816 whose canonical marker matches the exact live main and one requested mode.

The current validator accepts exactly `PLAN` or `APPLY`, attempt 1 only. It rejects replay/rerun, stale main, duplicated request identity and a trigger not matching the issue marker.

Conceptual request shape only — **do not copy it as authority**:

```text
/agency-preprod-refresh-successor PLAN  plan-<authority-issue>-<fresh-id>-r1 <live-main-sha> AUTO <profile>
/agency-preprod-refresh-successor APPLY apply-<authority-issue>-<fresh-id>-r1 <live-main-sha> <exact-prod-release-sha> <profile>
```

The issue marker and current `validate-execution-authority.py` are authoritative for the exact syntax/profile. Never rerun a consumed request ID.

### PLAN

PLAN is mutation-free metadata/readiness observation. It may verify current main, trusted runner, pinned SSH trust, current release identities, PREPROD Drush/runtime readiness, backup path, Config Split/admin reconciliation inputs and lock presence.

PLAN must create **no**:

- PROD snapshot/data transfer;
- raw staging import;
- PREPROD backup;
- maintenance state;
- PREPROD DB mutation.

### APPLY — current source implementation

Only when separately authorized:

1. Revalidate live `main` and the exact one-shot authority **before** SSH secrets are materialized.
2. Execute raw-data work only on the trusted self-hosted Agency surface.
3. Prepare the exact source release in DDEV; disconnect web/db from normal networks and attach only a fresh internal Docker network **before raw import**.
4. Obtain PROD through the reviewed read-only Drush `sql:dump` route. PROD is never mutated.
5. Keep raw SQL only in protected transient trusted storage / isolated DDEV; never GitHub-hosted, logged or uploaded.
6. Import raw only after isolation, run Drush `sql:sanitize`, then the thin Agency sanitizer/assertions.
7. Export sanitized SQL, delete raw transient material and transfer **sanitized SQL only** to PREPROD over the pinned encrypted channel.
8. Launch the detached non-root PREPROD worker. After launch, it owns the terminal transaction independently of the runner/SSH session.
9. Acquire the existing deploy lock and create a non-empty SHA-256 verified PREPROD Drush backup **before** destructive replacement.
10. Enable bounded maintenance; use standard `sql:drop` + `sql:cli`; run `updb`, `cim`, PREPROD split import, PREPROD server-owned admin reconciliation, cache rebuild/runtime validation; then disable maintenance and report `COMMITTED`.
11. If failure occurs after destructive replacement, restore the exact backup, rebuild/validate and only then disable maintenance: `ROLLED_BACK`.
12. If exact restore/validation cannot be proven, maintenance remains ON and the terminal boundary is `HUMAN_RECOVERY_REQUIRED`.

No GitHub transaction reconstruction, historical target recovery or near-zero-downtime swap belongs to the current route. #915/#917 may exist as repository history but are not operational dependencies.

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
- queues, especially externally acting mail/link-checker/AI/provider work;
- cache/render/discovery state;
- batch/temp/runtime state;
- sensitive user names/emails and authentication/reset/session material;
- persisted provider/API credentials and production-only state;
- externally acting runtime state not explicitly safe for PREPROD.

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

Different flows have different recovery boundaries.

### PREPROD data refresh

- exact PREPROD DB backup is created and verified before destructive DB replacement;
- PROD is read-only and is never part of rollback;
- failure before destructive replacement means no PREPROD runtime DB mutation;
- failure after replacement restores the exact PREPROD backup and validates it;
- maintenance reopens only after the rollback/runtime state is proven;
- unprovable rollback = `HUMAN_RECOVERY_REQUIRED`, maintenance stays ON.

This is standard DB backup/restore, not GitHub transaction reconstruction.

### Code/config promotion

Use the deployment-specific previous-release and DB backup evidence described by `docs/operations/preproduction.md` / `docs/deployment.md`. Code rollback alone is not a universal database rollback after schema/config/content mutation.

### Editorial #576

The bounded apply verifies a production SQL backup before first Article mutation. Recovery is owned by the editorial route and must never become a whole PREPROD/PROD DB promotion.

### DDEV

Development Seed consumption uses DDEV's native snapshot before pull and native snapshot restore for local recovery. No custom upstream rollback/push framework exists.

## 10. Development Seed / DDEV

### Start Agency locally

Current local runtime baseline is defined in `.ddev/config.yaml`:

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

Local DDEV does **not** need PROD credentials and does not gain PREPROD/PROD authority.

### Development Seed current state

#873 merged the source contract:

- `.ddev/providers/agency.yaml`;
- `.ddev/config.development-seed.yaml`;
- `scripts/development-seed/` policies, hash/compatibility verification and local convergence;
- `docs/operations/development-seed.md`.

The intended consumer command is:

```text
ddev pull agency
```

but **real remote consumption is not currently claimed available**. The future local configuration requires a separately provisioned read-only seed-distribution SSH identity/storage; it must not be a PROD credential or a PREPROD runtime credential.

The v1 contract is database-only. Public files are not bundled. Private files are never distributed. The provider has no push stanza:

```text
Development Seed -> DDEV = allowed once real distribution is provisioned
DDEV -> seed store       = forbidden
DDEV -> PREPROD          = forbidden
DDEV -> PROD             = forbidden
```

Compatibility is same release or seed-release ancestor; unsupported downgrade/divergence fails closed. SHA-256 is checked before import. After import, normal `updb`/`cim`/`cr`, local-only administrator creation and side-effect assertions converge the checkout.

See `docs/operations/development-seed.md` for the exact source contract and the clearly labelled future provisioning example.

## 11. Editorial publication boundary

The current #576 Article route is a specialized production content mutation surface, not deployment, data refresh or Governed Content.

Current owner commands are documented in `docs/operations/governed-editorial-publication.md`:

```text
/agency-editorial inspect
/agency-editorial dry-run
/agency-editorial apply
```

`dry-run` is read-only and binds a canonical payload SHA-256. `apply` requires the exact prior dry-run/main identity, runs a fresh preflight, verifies a backup before mutation and writes only the bounded Article profile through Drupal Entity API.

Published ordinary editorial content remains editor-owned in PROD Drupal. GitHub is request/audit control, not editorial source of truth.

#872 Editorial Candidate is future/not implemented by #871. Its intended PREPROD preview and exact approved-hash promotion must extend this bounded philosophy without creating a PREPROD DB -> PROD path.

## 12. Settings, Config Split and secrets ownership

Repository configuration is common by default. Environment activation remains environment-owned.

- `config/sync/config_split.config_split.production.yml` and `config/sync/config_split.config_split.preproduction.yml` are stored OFF by default.
- PREPROD server-owned settings force production split OFF and preproduction split ON, automated cron OFF, production analytics OFF, null/native mail without production credentials and AI egress OFF.
- PROD shared settings activate the production-specific runtime/config boundary.
- PREPROD hash salt and DB credentials remain PREPROD-owned.
- PROD settings/secrets remain PROD-owned.
- DDEV/local settings/secrets remain local-owned.

No environment receives another environment's runtime credential. Development Seed metadata/DB must contain no PREPROD runtime credential.

## 13. Failure classification

Use `HUMAN_RECOVERY_REQUIRED` only for a real safety boundary where automatic safe recovery cannot be proven.

Examples that are normally technical/recoverable rather than human boundaries:

- CI lint/test failure;
- stale branch/main mismatch that can be rebased/integrated;
- runner temporarily offline/busy;
- SSH transport failure before a destructive operation;
- invalid one-shot request requiring a fresh authorized identity;
- deterministic script defect inside Delivery authority.

Do not widen credentials, move raw data to GitHub-hosted infrastructure or create a parallel executor to work around a temporary executor problem.

## 14. Evidence, request IDs and replay

For every governed operation:

1. reload live `main`, issue/PR and current route;
2. bind the operation to exact repository/release/request identities required by that route;
3. keep evidence metadata-only;
4. treat statuses literally (`queued`, `in_progress`, terminal success/failure/skipped);
5. never treat PLAN as APPLY;
6. never reuse an emitted one-shot request ID.

Safe evidence may include:

- Git/release SHA;
- request/run identity;
- artifact/database hashes and byte sizes;
- aggregate sanitization counts without values;
- backup identity/path when non-sensitive;
- side-effect/health/browser PASS/FAIL.

No raw SQL, PII, passwords, session/reset data, private files or secret values belong in GitHub evidence.

## 15. Onboarding, handoff and rebaseline

A new developer/agent should be able to answer:

- **Source of truth?** GitHub + repository + execution evidence; never a handoff alone.
- **How do I start locally?** Read `AGENTS.md`, ADR-003, this page and `docs/operations/execution-capabilities.md`, then use DDEV.
- **How do I obtain safe realistic data?** The #873 pull contract is implemented/synthetically proven, but the real seed source/distribution is still pending #816 + separate provisioning. Do not seek PROD credentials as a workaround.
- **Do I need PROD credentials for DDEV?** No.
- **Can DDEV push to PREPROD/PROD?** No.
- **Who owns settings/secrets?** Each environment owns its own; they are not release/data artifacts.
- **How are code/config/content/data different?** See the ownership matrix and four flows above.
- **What is real vs synthetic?** Use the current capability matrix and reload live evidence.
- **Where are current routes?** Follow the authoritative links below, then reload the referenced workflow/script from live `main`.
- **How do I avoid replay?** Attempt 1 only where specified; emitted request IDs are `CONSUMED / NEVER REUSE`.

A safe handoff includes the last reloaded main SHA/tree, exact issue/PR/run/head identities, terminal vs non-terminal evidence, consumed IDs, current allowed/forbidden operations and the STOP boundary. It must explicitly say it is non-authoritative and require a fresh reload.

After another PR merges, rebaseline before terminal review:

```text
reload main
-> integrate current main into the same issue branch if it moved
-> re-audit referenced current workflow/script paths
-> rerun exact-head validation
```

## 16. Authoritative links

Current cross-environment sources:

- `AGENTS.md`
- `docs/decisions/ADR-003-use-existing-first.md`
- `docs/operations/execution-capabilities.md`
- `docs/operations/preproduction.md`
- `docs/deployment.md`
- `docs/operations/environment-side-effects.md`

Code/config:

- `.github/workflows/build-release-candidate.yml`
- `.github/workflows/deploy-preproduction.yml`
- `.github/workflows/promote-production.yml`

Data refresh:

- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `.github/workflows/preprod-914-governed-successor.yml`
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

This canonical page intentionally contains no secret values, no raw data, no hard-coded historical workflow run ID and no execution authority. Dynamic state must always be reloaded live.