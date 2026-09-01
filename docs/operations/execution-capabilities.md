# Agency execution capabilities

Status: **AUTHORITATIVE CURRENT CAPABILITY REGISTRY**
Repository: `E-merging-digital/agency-website-drupal`
Registry owner: #421
Current lifecycle index: `docs/operations/agency-environment-data-lifecycle.md`
Last materialized: 2026-09-01

## 1. Purpose and interpretation

This registry answers which useful capabilities currently exist, where they execute and which route owns authority.

```text
operator-surface capability != project-executor capability
registered capability != currently online executor
command dispatch != execution authority
implementation authority != execution authority
```

Status vocabulary includes `DESIGN_ONLY`, `SOURCE_IMPLEMENTED`, `SYNTHETICALLY_PROVEN`, `PROVISIONED`, `EXECUTABLE`, `REAL_EXECUTION_PROVEN` and `EXECUTION_PENDING`.

## 2. Current command dispatcher (#922 completed)

#922 is **COMPLETED**. `.github/workflows/agency-command-dispatch.yml` is the single top-level `issue_comment` listener. It classifies exact syntax only; the selected reusable workflow owns authorization.

Current reusable capabilities are exactly five:

1. `.github/workflows/promote-production.yml`
2. `.github/workflows/production-scheduler-change.yml`
3. `.github/workflows/trusted-editorial-publication.yml`
4. `.github/workflows/trusted-editorial-feature-image.yml`
5. `.github/workflows/preprod-914-governed-successor.yml`

## 3. Current operational capability index

| Capability | Owner | Status | Current execution surface | Mutation/data boundary |
| --- | --- | --- | --- | --- |
| Immutable code/config build | release system | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Artifact creation only. |
| Exact PREPROD deploy | release system | `PROVISIONED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PREPROD | PREPROD code/config only. |
| Single command dispatcher | #922 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Syntax routing only. |
| Same-artifact PROD promotion | promotion route | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD | Exact approved artifact only. |
| Production scheduler change | scheduler governance | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD | Bounded scheduler transition. |
| Governed Article publication | #576 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD Drupal | Bounded Article Entity API mutation. |
| Governed Article feature image | #584 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD Drupal | Bounded existing Article image mutation. |
| PROD -> PREPROD sanitized DB refresh | #914 / #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTABLE` | PLAN hosted; temporary #938 APPLY hosted control -> direct PROD/PREPROD server route | Raw never on GitHub-hosted; PREPROD receives only sanitized SQL for activation. |
| GitHub-hosted metadata-only PLAN | #927 / real proof #937 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted `ubuntu-24.04` | `PLAN_RESULT=PASS`; no DB content/snapshot/transfer/mutation. |
| Trusted self-hosted Agency executor | runner capability | `PROVISIONED` / currently unavailable | `self-hosted`, `linux`, `x64`, `agency` | Authorized raw-data alternative under policy; not current #938 APPLY executor. |
| Development Seed -> DDEV pull-only | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Local/trusted DDEV consumer | Pull-only; real source pending #816. |
| Editorial Candidate | #872 | `DESIGN_ONLY` | None | Not implemented. |

## 4. Self-hosted Agency executor

Agency has a registered runner capability with labels:

```text
self-hosted, linux, x64, agency, ddev, browser
```

Registration/provisioning is durable; availability is dynamic. The executor is currently unavailable for #938. That does not authorize raw PROD data on GitHub-hosted.

Current #914/#938 split:

```text
validate-authority = GitHub-hosted ubuntu-24.04
PLAN               = GitHub-hosted ubuntu-24.04 / CURRENT
APPLY control plane= GitHub-hosted ubuntu-24.04 / TEMPORARY CURRENT
APPLY raw route    = CONTROLLED_SERVER_TO_SERVER / PROD -> PREPROD DIRECT
self-hosted runner = AUTHORIZED ALTERNATIVE / CURRENTLY UNAVAILABLE
```

The temporary #938 path is intentionally retireable when the trusted runner is available again. It is not a permanent multi-provider architecture.

## 5. Browser, DDEV and developer execution

| Capability | Status | Primary use |
| --- | --- | --- |
| Playwright Test | AVAILABLE on registered executor | Deterministic browser validation/evidence |
| Chromium | AVAILABLE on registered executor | Browser validation |
| Playwright MCP | AVAILABLE on registered executor | Interactive inspection when transport exists |
| Chrome DevTools MCP | AVAILABLE on registered executor | DevTools diagnosis |
| DDEV | AVAILABLE on registered executor/local hosts | Drupal runtime/dependency execution |
| Docker | AVAILABLE on registered executor | DDEV/container execution |

A conversation without direct MCP transport must not conclude that the registered project capability does not exist.

## 6. Governed PROD -> PREPROD refresh (#914 / #816 / temporary #938)

```text
DECISION = EXTEND_EXISTING
CURRENT_IMPLEMENTATION = #914 + temporary #938 execution-path adaptation
PARENT = #816
REAL_PLAN = PASS / #937
REAL_END_TO_END_REFRESH = EXECUTION_PENDING
REAL_APPLY = PENDING
```

### Current PLAN

Authority validation and PLAN run on GitHub-hosted `ubuntu-24.04`. JIT revalidates live main, exact authority and hosted runner environment before transient SSH identities are materialized.

#937 terminal real PLAN evidence is:

```text
PLAN_RESULT = PASS
PROD_DB_CONTENT_READ = NONE
PROD_SNAPSHOT = NOT_PERFORMED
PROD_DATA_TRANSFER = NONE
PROD_WRITE = NONE
PREPROD_DB_MUTATION = NONE
DATA_ACTIVATION_AUTHORITY = DISABLED
APPLY = SKIPPED
```

### Temporary current APPLY

The GitHub-hosted APPLY job is only a control plane. It stages fixed scripts/identities to PREPROD after JIT and launches a detached request-scoped PREPROD worker. Raw PROD bytes never transit or materialize on GitHub-hosted infrastructure.

```text
GitHub-hosted control plane
-> PREPROD detached root preparation worker
-> pinned read-only PROD snapshot route
-> PROD -> PREPROD direct raw stream
-> request-derived PREPROD staging DB
-> existing root-owned sanitizer + single policy
-> sanitization assertions
-> sanitized SQL
-> raw staging cleanup + absence proof
-> PROD identity/root-stage cleanup + absence proof
-> existing #914 remote-apply-worker.sh
-> backup / maintenance / activation / rollback
```

Pre-activation cleanup is fail-closed:

```text
RAW staging absence unproven
-> HUMAN_RECOVERY_REQUIRED / RAW_STAGING_CLEANUP_UNPROVEN
-> ACTIVATION NOT STARTED

PROD identity/root stage absence unproven
-> HUMAN_RECOVERY_REQUIRED / PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN
-> ACTIVATION NOT STARTED
```

After activation begins, the unchanged #914 worker owns backup and rollback. Proven rollback => `ROLLED_BACK`; unproven rollback => `HUMAN_RECOVERY_REQUIRED` with maintenance ON.

Hard boundaries:

```text
PROD_WRITE = NONE
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW_PROD_IN_GITHUB_ARTIFACT/LOG/TEMP = NONE
PREPROD_LIVE_RUNTIME_RAW_ACCESS = FORBIDDEN
SANITIZATION_POLICY = EXISTING SINGLE POLICY
PREPROD_RECEIVES_FOR_ACTIVATION = SANITIZED_SQL_ONLY
PERSISTENT_DATA_ACTIVATION_AUTHORITY = DISABLED
```

No `RECOVER_ABORT`, `RECOVER_CURRENT`, transaction registry, historical target reconstruction or #915 state machine is current.

Primary sources:

- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/preprod-914-governed-successor.yml`
- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-server-to-server-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-server-to-server-worker.py`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`

## 7. Development Seed -> DDEV pull-only (#873)

```text
REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
SYNTHETIC_PROOF = COMPLETE
REAL_PREPROD_SEED_GENERATION = PENDING #816
REAL_STORAGE_PROVISIONING = PENDING
REAL_SEED_DISTRIBUTION = PENDING
DDEV_PUSH = NONE
```

The DDEV provider is pull-only and does not imply a live seed service.

## 8. Reload rule

Before saying a route, workflow, runner or command exists today:

1. reload `main`;
2. reload the dispatcher;
3. reload the selected reusable workflow and route-specific docs;
4. reload governing issue/authority and exact execution evidence;
5. then classify status.

If this registry and current repository differ, current repository wins and this registry must be corrected.
