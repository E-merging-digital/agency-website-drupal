# Agency Environment & Data Lifecycle

Status: **CANONICAL CURRENT-STATE OPERATIONAL ENTRYPOINT**
Owner: #871 terminal documentation closeout.
Architecture rule: `docs/decisions/ADR-003-use-existing-first.md`.

GitHub, the versioned repository and execution evidence are authoritative. Handoffs and this document never replace a fresh live reload before approval, execution, mutation or merge.

## 1. Purpose, authority and status vocabulary

Agency applies `USE EXISTING FIRST`:

```text
Drupal Core
-> Drush / Drupal APIs
-> DDEV
-> stable supported contrib when useful
-> standard system tools
-> minimal Agency extension only for a demonstrated gap
```

Current status vocabulary includes `DESIGN_ONLY`, `SOURCE_IMPLEMENTED`, `SYNTHETICALLY_PROVEN`, `PROVISIONED`, `EXECUTABLE`, `REAL_EXECUTION_PROVEN` and `EXECUTION_PENDING`.

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

#922 is **COMPLETED**. `.github/workflows/agency-command-dispatch.yml` is the single current top-level issue-comment listener and only classifies exact syntax. Authorization remains downstream.

## 2. Environment map

| Surface | Role | DB/data boundary | Current execution role |
| --- | --- | --- | --- |
| DDEV/local | Development/tests | Local DB only; no upstream push | Developer/local or registered trusted executor. |
| PREPROD | Production-like release gate + independent refresh target | PREPROD-owned DB/settings/secrets | Code deploy plus separately governed refresh target. |
| PROD | Live service/data authority | PROD-owned live DB/settings/secrets | Governed promotion/editorial/scheduler/read-only snapshot routes. |
| GitHub-hosted | CI/dispatcher/authority/control plane | **No raw PROD DB** | `ubuntu-24.04`; metadata/scripts/transient identities only for controlled APPLY. |
| Trusted self-hosted Agency executor | Registered trusted DDEV/raw-data surface | Route-authorized only | Authorized alternative under the existing policy; not required by the current server-to-server APPLY. |

The self-hosted Agency runner remains registered with `self-hosted`, `linux`, `x64`, `agency`, `ddev`, `browser`. `CAPABILITY EXISTS != EXECUTOR CURRENTLY ONLINE`; availability is a live fact and must be reloaded before relying on it.

## 3. Ownership matrix

| Class | Durable owner/source | Rule |
| --- | --- | --- |
| **CODE** | Git + immutable release identity | Build once, validate PREPROD, same artifact to PROD. |
| **CONFIG** | `config/sync` + approved splits | Converge with Drupal config APIs/Drush; PREPROD DB is never promoted. |
| **EDITORIAL CONTENT** | Drupal PROD/editor workflow | Never promoted as a database. |
| **DATABASE DATA** | Each runtime owns its DB | PROD -> sanitized PREPROD only; PREPROD -> PROD forbidden. |
| **ENVIRONMENT SETTINGS** | Environment-owned | Never copied as another environment's runtime settings. |
| **SECRETS** | Environment/route-specific store | Never versioned or copied into another runtime credential set. |
| **FILES** | Environment-owned persistent files | Current #914 DB refresh is database-only; private files excluded. |

## 4. Four distinct operational flows

### A. CODE / CONFIG

```text
Git / PR -> CI -> immutable candidate -> PREPROD -> validation -> explicit PROD promotion
```

Primary workflows: `build-release-candidate.yml`, `deploy-preproduction.yml`, `promote-production.yml`.

### B. DATA REFRESH

```text
fresh one-shot command
-> agency-command-dispatch.yml
-> preprod-914-governed-successor.yml
-> fresh authority/JIT
```

Current split:

```text
PLAN = GitHub-hosted ubuntu-24.04 / REAL_EXECUTION_PROVEN
APPLY = CONTROLLED_SERVER_TO_SERVER / REAL_EXECUTION_PROVEN
RAW_PROD_ROUTE = PROD_TO_PREPROD_DIRECT
TRUSTED_SELF_HOSTED_RUNNER = AUTHORIZED ALTERNATIVE
```

Compatibility note for the pre-existing documentation-only #908 checks: the following literal tokens are retained as **non-authoritative parser aliases only**. They MUST NOT override the current state above:

```text
PLAN_RESULT = PASS
APPLY = CONTROLLED_SERVER_TO_SERVER / TEMPORARY CURRENT
TRUSTED_SELF_HOSTED_RUNNER = AUTHORIZED ALTERNATIVE / CURRENTLY UNAVAILABLE
```

`PLAN_RESULT = PASS` remains true. `TEMPORARY CURRENT` is legacy validation vocabulary; the authoritative APPLY capability status is `REAL_EXECUTION_PROVEN`. `CURRENTLY UNAVAILABLE` is likewise a legacy validation token and **not** a live runner-availability assertion; runner availability is dynamic and must be reloaded before use.

The current controlled APPLY recipe is:

```text
GitHub-hosted control plane only
-> fixed request-scoped PREPROD worker
-> direct pinned read-only PROD -> PREPROD raw stream
-> derived isolated PREPROD staging DB
-> existing single sanitizer/policy + assertions
-> sanitized SQL
-> prove raw staging absent
-> prove transient PROD identity/root stage absent
-> existing #914 remote-apply-worker.sh
-> PREPROD backup / maintenance / activation / rollback
-> validation
-> COMMITTED or fail-closed rollback/recovery outcome
```

The real terminal proof is #953: the worker reached `COMMITTED` with detail `SANITIZED_DATABASE_ACTIVE_AND_VALIDATED`. Raw PROD never transited or materialized on GitHub-hosted infrastructure, PROD access remained read-only and request-scoped/transient, PROD write remained none, and sanitized-only activation passed.

Primary sources:

- `.github/workflows/preprod-914-governed-successor.yml`
- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-server-to-server-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-server-to-server-worker.py`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`

### C. EDITORIAL CONTENT

Current #576 bounded Article publication remains separate from DB refresh and code promotion. Editorial Candidate V1 is now source-implemented and real-execution-proven through the #959/#872 lineage.

```text
EDITORIAL_CANDIDATE_V1 = SOURCE_IMPLEMENTED / REAL_EXECUTION_PROVEN
#959 = CLOSED / COMPLETED
#872 = CLOSED / COMPLETED
PREPROD_MATERIALIZATION = PROVEN
FR_EN_RENDER = MATERIALIZED
HUMAN_EXACT_APPROVAL = PROVEN
ARTICLE_PROD_PROMOTION = PROVEN
IMAGE_PROD_PROMOTION = PROVEN
PREPROD_DB_TO_PROD_COPY = NONE
```

The first V1 loop used an exact durable candidate, real PREPROD rendering, explicit human approval bound to that candidate and separately governed exact PROD promotion. PREPROD runtime/database state was not promoted wholesale to PROD.

### D. DEVELOPMENT DATA

#873 provides the repository/DDEV pull-only Development Seed consumer contract. The #816 source-data blocker is removed, but the real Development Seed service is not yet provisioned or proven.

```text
#873_BLOCKED_BY_816 = NO
REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
SYNTHETIC_PROOF = COMPLETE
DDEV_PROVIDER = .ddev/providers/agency.yaml
LOCAL_UX = ddev pull agency
DDEV_PUSH = NONE
REAL_PREPROD_SEED_GENERATION = PENDING
REAL_STORAGE_PROVISIONING = PENDING
REAL_DISTRIBUTION = PENDING
```

## 5. Current capability/status matrix

| Capability | Status | Real mutation/data status |
| --- | --- | --- |
| PREPROD release environment | `PROVISIONED` / `REAL_EXECUTION_PROVEN` | Code/config deployment proven. |
| Single command dispatcher | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | Syntax routing only. |
| Same-artifact PROD promotion | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | Explicit governed PROD mutation. |
| Governed Article publication | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | Bounded Article mutation. |
| PROD -> PREPROD refresh | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `REAL_EXECUTION_PROVEN` | Real end-to-end controlled refresh proven `COMMITTED` by #953. |
| GitHub-hosted metadata-only PLAN | `REAL_EXECUTION_PROVEN` | Metadata/readiness only; no raw data or mutation. |
| Controlled server-to-server APPLY | `REAL_EXECUTION_PROVEN` | Raw route direct PROD -> PREPROD; activation receives sanitized SQL only. |
| Trusted self-hosted executor | `PROVISIONED` | Authorized alternative; live availability must be reloaded. |
| Development Seed | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | DDEV consumer complete; real generation/storage/distribution pending. |
| Editorial Candidate | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | #959 real FR+EN PREPROD materialization and human approval proven; #872 exact first PROD promotion loop terminally completed. |

#915/#917 are historical lineage and not operational dependencies.

## 6. Code/configuration recipe

Normal issue/branch/PR -> coherent release candidate -> exact PREPROD deployment -> validation -> explicit same-artifact PROD promotion. Code/config rollback is separate from data-refresh rollback.

## 7. PROD -> PREPROD refresh recipe (#816 completed / current #914)

### Authority boundary

#816 is **CLOSED / COMPLETED** and is not reopened for routine execution. Every future real run still requires a fresh owner-created one-shot execution authority accepted by the current validator, exact live `main`, exact mode/profile and a fresh request ID. The dispatcher only routes syntax.

### Current PLAN

Current PLAN executes on GitHub-hosted `ubuntu-24.04`. JIT-before-secret revalidates live main, exact checkout, one-shot authority and hosted runner environment.

PLAN remains metadata/readiness-only and performs no PROD DB content read, PROD snapshot, data transfer, PROD write or PREPROD mutation.

### Current controlled APPLY

GitHub-hosted is control plane only. It does not receive raw PROD bytes.

The PREPROD preparation worker uses the existing read-only PROD snapshot route and existing root-owned staging/sanitization primitives. PREPROD live Drupal never points to or bootstraps the raw staging database.

Before activation:

```text
sanitize/assert
-> sanitized SQL export
-> cleanup derived staging DB/account
-> prove staging absence
-> remove transient PROD identity + pinned trust root stage
-> prove root-stage absence
-> ONLY THEN existing remote-apply-worker.sh
```

If raw staging cleanup cannot be proven:

```text
HUMAN_RECOVERY_REQUIRED
RAW_STAGING_CLEANUP_UNPROVEN
ACTIVATION = NOT_STARTED
```

If PROD identity/root-stage cleanup cannot be proven:

```text
HUMAN_RECOVERY_REQUIRED
PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN
ACTIVATION = NOT_STARTED
```

The existing activation worker preserves exact backup-before-destructive-replacement and rollback semantics. Proven restore => `ROLLED_BACK`; unproven restore => `HUMAN_RECOVERY_REQUIRED`, maintenance stays ON.

Terminal real evidence from #953 is:

```text
PROD_TO_PREPROD_REFRESH = REAL_EXECUTION_PROVEN
PREPROD_WORKER_OUTCOME = COMMITTED
PREPROD_WORKER_DETAIL = SANITIZED_DATABASE_ACTIVE_AND_VALIDATED
PROD_ACCESS = READ_ONLY_REQUEST_SCOPED_TRANSIENT
PROD_WRITE = NONE
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_ROUTE = PROD_TO_PREPROD_DIRECT
SANITIZED_ONLY_ACTIVATION = PASS
HUMAN_RECOVERY_REQUIRED = NO
```

No `RECOVER_CURRENT`, `RECOVER_ABORT`, GitHub transaction registry/reconstruction or #915 state machine is current.

## 8. Privacy and data classification

```text
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW SQL AS GITHUB ARTIFACT = FORBIDDEN
PII IN EVIDENCE/LOGS = FORBIDDEN
PRIVATE FILES = EXCLUDED
```

Sanitize users/auth/session material, Webform submissions, flood/watchdog, queues, caches/batch/temp/runtime state and persisted provider/key credentials before activation.

## 9. Backup, rollback and recovery

PROD is never part of rollback. The existing #914 worker creates/verifies an exact PREPROD backup before maintenance/destructive replacement.

```text
restore proven      -> ROLLED_BACK
restore unprovable  -> HUMAN_RECOVERY_REQUIRED
                       maintenance stays ON
```

Pre-activation unproven raw/identity cleanup also yields `HUMAN_RECOVERY_REQUIRED`, but activation has not started.

The terminal #953 execution required no human recovery and committed a validated sanitized PREPROD database.

For DDEV, rollback remains DDEV's native snapshot mechanism.

## 10. Development Seed / DDEV

Developers do not need PROD credentials. The repository consumer is complete and pull-only:

```text
DDEV_PROVIDER = .ddev/providers/agency.yaml
LOCAL_UX = ddev pull agency
DDEV_PUSH = NONE
DDEV -> PREPROD = FORBIDDEN
DDEV -> PROD = FORBIDDEN
```

#816 no longer blocks #873. A real seed source, controlled storage and distribution still need their own real proof before `ddev pull agency` can consume a live service.

## 11. Editorial publication boundary

#576 bounded Article publication remains independent of DB refresh. Editorial Candidate V1 under #959/#872 is `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN`: real FR+EN PREPROD materialization, exact human approval and the first separately governed exact PROD promotion loop are terminally proven. The capability does not authorize PREPROD database promotion, generic entity mutation or automatic AI publication.

## 12. Settings, Config Split and secrets ownership

```text
PROD settings/secrets     = PROD-owned
PREPROD settings/secrets  = PREPROD-owned
DDEV settings/secrets     = local-owned
```

The transient PROD SSH identity used by the controlled APPLY is request-scoped execution material, not a PREPROD runtime credential, and must be absent before activation.

## 13. Failure classification

1. recoverable technical failure: CI/tool/transport/readiness defect;
2. runtime terminal outcome: `COMMITTED` or proven `ROLLED_BACK`;
3. `HUMAN_RECOVERY_REQUIRED`: automatic safety proof is missing, including unproven pre-activation raw/identity cleanup or unproven post-destructive rollback.

A temporarily offline runner is an availability fact, not `HUMAN_REQUIRED`.

## 14. Evidence, request IDs and replay

Evidence is metadata-only and non-sensitive. Every emitted request ID becomes:

```text
CONSUMED / NEVER REUSE
```

The terminal #953 request is consumed and must never be reused. Future PLAN/APPLY execution requires fresh authority and fresh identity. Recipes must resolve current live main and source release rather than copying historical SHAs or run IDs from prose.

## 15. Onboarding, handoff and rebaseline

Current concise handoff facts:

- GitHub/repository/execution evidence are authoritative;
- the dispatcher routes syntax; authorization remains downstream;
- PLAN runner = GitHub-hosted `ubuntu-24.04`;
- PROD -> PREPROD refresh = `REAL_EXECUTION_PROVEN`;
- APPLY = current controlled `CONTROLLED_SERVER_TO_SERVER` route;
- terminal proof #953 = `COMMITTED` / `SANITIZED_DATABASE_ACTIVE_AND_VALIDATED`;
- raw PROD on GitHub-hosted = none;
- PROD write = none;
- sanitized-only activation = pass;
- DDEV push = none;
- #873 repository/DDEV implementation = complete;
- #873 real seed generation/storage/distribution = pending, no longer blocked by #816;
- Editorial Candidate V1 = `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN`;
- #959 = CLOSED / COMPLETED after real FR+EN PREPROD materialization and exact human approval;
- #872 = CLOSED / COMPLETED after the first full exact promotion loop and final human public validation;
- PREPROD DB -> PROD copy = none;
- always reload live before repeating handoff state.

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
- `docs/operations/editorial-candidate.md`
- `docs/operations/development-seed.md`
- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/preprod-914-governed-successor.yml`
- `.ddev/providers/agency.yaml`

Primary current-state rule: live implementation/execution evidence wins over historical prose.

## 17. Agency Operations Cockpit V1 control-plane contract (#1063)

`/admin/agency/operations` is a Drupal-admin **control-plane facade** over the existing governed capabilities. It does not become an execution engine and it does not supersede `docs/operations/execution-capabilities.md`, GitHub workflows, runners, receipts or environment-owned authority.

```text
DRUPAL ADMIN UI = CONTROL PLANE / READ-ONLY + PREPARATION
EXISTING WORKFLOWS + RUNNERS = EXECUTION DATA PLANE
PROD_ACCESS = NONE
PROD_WRITE = NONE
PREPROD_WRITE = NONE
ARBITRARY_GITHUB_DISPATCH = NONE
SECRETS_IN_DRUPAL = NONE
NEW_EXECUTION_ENGINE = NONE
```

The cockpit consumes the existing Markdown capability registry read-only from the deployed repository. It does not persist a second capability registry or receipt store. Capability status shown in the UI remains repository-derived and any execution-time availability/authority still requires the ordinary live reload.

### Dynamic language contract

The publication-language contract is data-driven from Drupal's configured languages through the `language_manager` service and `LanguageManagerInterface::getLanguages(LanguageInterface::STATE_CONFIGURABLE)`.

```text
CONTENT_REQUIRED_LANGUAGES = ALL CURRENTLY CONFIGURABLE SITE LANGUAGES
HARDCODED_LANGUAGE_LIST = NONE
ALL_AVAILABLE_LANGUAGES_REQUIRED = YES
MISSING_REQUIRED_LANGUAGE = BLOCKS_READINESS
```

Today the repository config resolves to `fr` + `en`. Those codes are current data, not application constants. Enabling another configurable public/content language must automatically add another required row without a code change.

Candidate readiness remains fail-closed. For every required language the presentation contract exposes translation existence/state, PREPROD render state, validation state and approval state where applicable. The cockpit does not import private candidate payloads or add privileged GitHub credentials to Drupal merely to populate the matrix.

### Drupal-native adoption audit

Current repository configuration has Content Translation enabled. Content Moderation, Workflows and Workspaces are not enabled by #1063 and V1 does not enable them automatically.

```text
CONTENT_TRANSLATION = REUSE_NOW
CONTENT_MODERATION = REUSE_LATER
WORKFLOWS = REUSE_LATER
WORKSPACES = REUSE_LATER
WORKSPACE != PREPROD_TO_PROD_TRANSPORT
```

Content Moderation may later add useful Drupal-native editorial state UX only if it can do so without duplicating the existing Editorial Candidate/human-approval authority. Workspaces may later help group coherent changes inside one Drupal environment, but cannot replace the governed PREPROD-to-PROD transport because PREPROD and PROD remain separate databases.

`CHANGE_SET` V1 therefore reuses existing candidate identity, revisions and entity references. No new Change Set entity is introduced; a custom entity is reconsidered only after a material gap is proven.

### History / evidence

V1 uses `READ_ONLY_AGGREGATION`: existing workflow evidence, request IDs, candidate/release identities and receipts remain in their current authoritative stores. No Drupal table/entity is introduced for history in this tranche.
