# Agency Environment & Data Lifecycle

Status: **CANONICAL CURRENT-STATE OPERATIONAL ENTRYPOINT**
Owner: #871 while #816 real end-to-end execution remains pending.
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
| GitHub-hosted | CI/dispatcher/authority/PLAN/APPLY control plane | **No raw PROD DB** | `ubuntu-24.04`; metadata/scripts/identities only for #938 APPLY. |
| Trusted self-hosted Agency executor | Registered trusted DDEV/raw-data surface | Route-authorized only | Authorized alternative; currently unavailable for #938. |

The self-hosted Agency runner remains registered with `self-hosted`, `linux`, `x64`, `agency`, `ddev`, `browser`. `CAPABILITY EXISTS != EXECUTOR CURRENTLY ONLINE`.

## 3. Ownership matrix

| Class | Durable owner/source | Rule |
| --- | --- | --- |
| **CODE** | Git + immutable release identity | Build once, validate PREPROD, same artifact to PROD. |
| **CONFIG** | `config/sync` + approved splits | Converge with Drupal config APIs/Drush; PREPROD DB is never promoted. |
| **EDITORIAL CONTENT** | Drupal PROD/editor workflow | Never promoted as a database. |
| **DATABASE DATA** | Each runtime owns its DB | PROD -> sanitized PREPROD only; PREPROD -> PROD forbidden. |
| **ENVIRONMENT SETTINGS** | Environment-owned | Never copied as another environment's runtime settings. |
| **SECRETS** | Environment/route-specific store | Never versioned or copied into another runtime credential set. |
| **FILES** | Environment-owned persistent files | #914/#938 DB-only; private files excluded. |

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
PLAN = GitHub-hosted ubuntu-24.04 / CURRENT
APPLY = CONTROLLED_SERVER_TO_SERVER / TEMPORARY CURRENT
TRUSTED_SELF_HOSTED_RUNNER = AUTHORIZED ALTERNATIVE / CURRENTLY UNAVAILABLE
```

#937 is CLOSED / COMPLETED and provides `REAL_EXECUTION_PROVEN` for PLAN with `PLAN_RESULT=PASS`. It performed no PROD DB content read, snapshot, transfer, write or PREPROD mutation.

Temporary #938 APPLY is:

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
```

Raw PROD never transits or materializes on GitHub-hosted infrastructure. #938 is temporary and retireable; it is not a permanent multi-provider framework.

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

Current #576 bounded Article publication remains separate from DB refresh and code promotion. #872 Editorial Candidate remains `DESIGN_ONLY`.

### D. DEVELOPMENT DATA

#873 provides the repository/DDEV pull-only Development Seed consumer contract. Real seed generation/storage/distribution remains pending #816/separate provisioning.

```text
DDEV_PUSH = NONE
REAL_PREPROD_SEED_GENERATION = PENDING #816
```

## 5. Current capability/status matrix

| Capability | Status | Real mutation/data status |
| --- | --- | --- |
| PREPROD release environment | `PROVISIONED` / `REAL_EXECUTION_PROVEN` | Code/config deployment proven. |
| Single command dispatcher | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | Syntax routing only. |
| Same-artifact PROD promotion | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | Explicit governed PROD mutation. |
| Governed Article publication | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | Bounded Article mutation. |
| PROD -> PREPROD refresh | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTABLE` | Real PLAN proven PASS; real APPLY pending. |
| GitHub-hosted PLAN | `REAL_EXECUTION_PROVEN` | #937 PASS; metadata/readiness only. |
| Temporary controlled server-to-server APPLY | #938 `SOURCE_IMPLEMENTED` / static-synthetic proof in progress until PR green | No real APPLY yet. |
| Trusted self-hosted executor | `PROVISIONED` / currently unavailable | Authorized alternative, not current #938 executor. |
| Development Seed | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Real distribution pending. |
| Editorial Candidate | `DESIGN_ONLY` | Not implemented. |

#915/#917 are historical lineage and not operational dependencies.

## 6. Code/configuration recipe

Normal issue/branch/PR -> coherent release candidate -> exact PREPROD deployment -> validation -> explicit same-artifact PROD promotion. Code/config rollback is separate from data-refresh rollback.

## 7. PROD -> PREPROD refresh recipe (#816 / current #914)

### Authority boundary

Every real run requires a fresh owner-created authority under #816, exact live `main`, exact mode/profile and fresh request ID. The dispatcher only routes syntax.

### Current PLAN

Current PLAN executes on GitHub-hosted `ubuntu-24.04`. JIT-before-secret revalidates live main, exact checkout, one-shot authority and hosted runner environment.

#937 terminal result:

```text
PLAN_RESULT = PASS
PROD_DB_CONTENT_READ = NONE
PROD_SNAPSHOT = NOT_PERFORMED
PROD_DATA_TRANSFER = NONE
PROD_WRITE = NONE
PREPROD_DB_MUTATION = NONE
APPLY = SKIPPED
```

### Current temporary APPLY

GitHub-hosted is control plane only. It does not open a PROD snapshot connection and never sees raw PROD bytes.

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

For DDEV, rollback remains DDEV's native snapshot mechanism.

## 10. Development Seed / DDEV

Developers do not need PROD credentials. `ddev pull agency` is pull-only when a real seed source is provisioned.

```text
DDEV_PUSH = NONE
DDEV -> PREPROD = FORBIDDEN
DDEV -> PROD = FORBIDDEN
```

## 11. Editorial publication boundary

#576 bounded Article publication is independent of DB refresh. #872 remains future / `DESIGN_ONLY`.

## 12. Settings, Config Split and secrets ownership

```text
PROD settings/secrets     = PROD-owned
PREPROD settings/secrets  = PREPROD-owned
DDEV settings/secrets     = local-owned
```

The transient #938 PROD SSH identity is request-scoped execution material, not a PREPROD runtime credential, and must be absent before activation.

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

#937 PLAN identity is consumed. Future PLAN/APPLY requires fresh authority and fresh identity.

## 15. Onboarding, handoff and rebaseline

Current concise handoff facts:

- GitHub/repository/execution evidence are authoritative;
- single dispatcher + five reusable workflows;
- PLAN runner = GitHub-hosted `ubuntu-24.04`;
- PLAN real result = PASS (#937);
- APPLY = temporary `CONTROLLED_SERVER_TO_SERVER` current route (#938);
- self-hosted Agency runner = authorized alternative / currently unavailable;
- raw PROD on GitHub-hosted = none;
- DDEV push = none;
- #816 real end-to-end APPLY = pending;
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
- `docs/operations/development-seed.md`
- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/preprod-914-governed-successor.yml`
- `.ddev/providers/agency.yaml`

Primary current-state rule: live implementation/execution evidence wins over historical prose.
