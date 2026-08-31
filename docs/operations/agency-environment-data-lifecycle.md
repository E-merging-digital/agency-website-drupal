# Agency environment and data lifecycle

This document is the canonical operational entrypoint for Agency environment ownership, execution authority, code/configuration promotion, the PROD-to-PREPROD data lifecycle, privacy boundaries, execution capabilities, recovery, onboarding, and handoff.

It is an index and status map, not a replacement for the specialized contracts linked below. When this document and a live execution surface disagree, reload GitHub and the exact repository state: GitHub, versioned repository state, and execution evidence are authoritative.

## 1. Status model

Use these terms precisely:

- `PROVEN`: implementation or behavior is supported by repository contract and durable evidence at the stated boundary.
- `EXECUTABLE`: a proven capability has a currently defined execution route, subject to its own fresh authority checks and executor availability.
- `PROVISIONING_PENDING`: source capability exists but a required provisioning step has not reached terminal proof.
- `EXECUTION_PENDING`: implementation/authority exists, but the relevant real execution has not reached terminal proof.
- `DESIGN_ONLY`: design contract exists without implementation/execution authority.
- `DEFERRED`: intentionally postponed until named prerequisites or the current stabilization program complete.

A green implementation test is not real runtime execution. A queued job is not success or failure. `CAPABILITY EXISTS != EXECUTOR CURRENTLY ONLINE`.

### Current major capability classification

| Capability | Current classification | Durable interpretation |
| --- | --- | --- |
| DDEV/local development runtime | `PROVEN` / `EXECUTABLE` | Local development/test surface; not authority for PREPROD or PROD. |
| PREPROD environment | `PROVEN` / `EXECUTABLE` | Real environment with separately governed deployment and operational mutation routes. |
| GitHub-hosted control, build and validation | `PROVEN` / `EXECUTABLE` | Suitable for code, metadata, policy and synthetic validation; raw PROD data is forbidden. |
| Trusted self-hosted Agency executor capability | `PROVEN` | Persistent capability registered separately from its dynamic online/busy state. |
| PREPROD activation/fence repository implementation | `PROVEN` | Fixed-purpose source and validations exist. |
| PREPROD activation/fence mutation-free PLAN | `PROVEN` | Real PLAN lineage has reached proof; `PLAN != APPLY`. |
| Real activation/fence capability provisioning APPLY (#907) | `EXECUTION_PENDING` | At #908 materialization, successor authority validated and the APPLY job is queued. This is dynamic: reload live before any runtime conclusion. |
| Data activation authority | `DISABLED` | Provisioning does not grant data activation. |
| Full real PROD -> PREPROD refresh (#816) | `EXECUTION_PENDING` | Architecture and component capabilities exist, but the complete refresh has not reached terminal end-to-end real proof. |
| Governed Article editorial publication (#576) | `PROVEN` / `EXECUTABLE` | Separate editorial mutation route with its own authority; not code/config promotion and not data refresh. |
| Editorial Candidate (#872) | `DESIGN_ONLY` | Dependency-gated; no implementation authority from this runbook. |
| Development Seed (#873) | `DESIGN_ONLY` | Dependency-gated; no implementation authority from this runbook. |
| AI / Agent Readiness (#863) | `DEFERRED` | Resume only after the current stability/PREPROD program permits it. |

Issue numbers describe lineage; they do not replace live reload. Do not copy a queued/completed observation from this document into an execution decision.

## 2. Authority model

The authority hierarchy is mandatory:

- `GitHub + repository + execution evidence = source of truth`.
- `handoff != authority`.
- `implementation authority != execution authority`.
- `PLAN != APPLY`.
- an emitted one-shot request ID is `CONSUMED / NEVER REUSE`, regardless of success, failure, cancellation, queueing, or transport outcome.
- `recoverable technical failure != HUMAN_REQUIRED`.
- `operator-surface capability != project-executor capability`.
- `CAPABILITY EXISTS != EXECUTOR CURRENTLY ONLINE`.
- absence of a tool such as MCP in the current ChatGPT cockpit does not prove that the project executor lacks that capability.

Always reload live state before:

- merge;
- execution;
- approval;
- runtime conclusion;
- mutation.

A workflow or issue may define implementation and validation without authorizing real execution. A real executor may exist while a particular runner is offline or busy. Conversely, an online runner does not create authority by itself.

## 3. Environment map

| Surface | Role | Code ownership | DB/data ownership | Settings ownership | Secret ownership | Allowed mutations | Side-effect policy |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DDEV / local | Development, tests, local reproduction | Git working tree / developer checkout | Local developer/DDEV state | Local/DDEV configuration | Local developer/DDEV only | Local changes within task scope | External effects should remain fake/null/disabled unless explicitly testing a governed integration. |
| PREPROD | Production-like validation and separately governed data-refresh target | Exact deployed release/artifact plus server runtime layout | PREPROD server/runtime; refreshed data only through governed lifecycle | PREPROD server-owned settings and environment configuration | PREPROD/GitHub environment scopes; never documented as values | Deployment, separately authorized operational changes, and later separately authorized refresh actions | Non-production side effects must remain hardened/disabled; Basic Auth/noindex and health/readiness contracts apply. |
| PROD | Live service and source of truth for live data | Exact validated production artifact through governed promotion | PROD runtime/data | PROD server/environment-owned settings | PROD environment/server only | Only specialized, explicitly authorized production routes | Real side effects only where intended; no PREPROD refresh authority implies PROD write. |
| GitHub-hosted control/validation | CI, immutable build, policy/static/synthetic checks, metadata-only governance | Repository checkout and generated non-sensitive artifacts | No raw PROD dataset | Workflow-defined metadata/config only | GitHub secrets may be consumed only by already-governed scoped routes | Repository/CI/build/deployment control as explicitly encoded | `RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN`; secrets, PII and raw SQL must not become evidence/artifacts. |
| Trusted self-hosted Agency executor | Trusted project execution for capabilities requiring local/private/privileged surface | Exact checked-out repository identity required by each route | Only data/classes authorized by that route | Executor and environment-owned | Governed self-hosted secret scopes | Only fixed-purpose workflow actions with fresh authority | Capability is persistent; online/busy status is dynamic and must be reloaded. |

Specialized references:

- `docs/operations/execution-capabilities.md`
- `docs/operations/agency-self-hosted-browser-runner.md`
- `docs/operations/environment-side-effects.md`
- `docs/operations/agency-health-endpoints.md`

## 4. Code and configuration flow

Code/configuration promotion is separate from data refresh and editorial mutation.

Canonical normal flow:

```text
Git / PR
  -> CI
  -> exact immutable release candidate
  -> PREPROD deployment
  -> PREPROD runtime + health + browser validation
  -> human GO where required
  -> same exact immutable artifact promoted to PROD
  -> post-deploy proof
```

Relevant durable routes:

- `.github/workflows/build-release-candidate.yml` builds the immutable candidate from a release branch, pins source identity and excludes environment-owned runtime material.
- `.github/workflows/deploy-preproduction.yml` consumes that exact candidate and produces PREPROD deployment/validation evidence.
- `.github/workflows/promote-production.yml` requires an owner GO bound to candidate/artifact/composer identities and a successful exact PREPROD run, then promotes that same candidate.
- `.github/workflows/production-promotion-validation.yml` validates the promotion contract.
- `.github/workflows/deploy-production.yml` is the separately constrained emergency hotfix/security path; it is not the normal promotion model above.

Do not confuse this flow with:

- PROD -> PREPROD data refresh;
- governed editorial Article publication;
- Editorial Candidate;
- Development Seed.

Editorial publication authority is documented in `docs/operations/governed-editorial-publication.md`.

## 5. PROD -> PREPROD data refresh (#816)

The target architecture is:

```text
PROD READ-ONLY
  -> transient snapshot
  -> encrypted transfer
  -> isolated PREPROD staging DB
  -> deterministic sanitization
  -> side-effect hardening
  -> candidate assertions
  -> runtime fence
  -> PREPROD backup
  -> controlled activation
  -> Drupal convergence
  -> validation
  -> commit OR rollback
```

The specialized canonical refresh contract remains `docs/operations/preproduction-data-refresh.md`. This document supplies the cross-environment status map and must not be used to invent a full-refresh command.

| Stage | Target state | Current implementation state | Current real execution state |
| --- | --- | --- | --- |
| PROD read-only source | No PROD write; only bounded snapshot source access | `PROVEN` repository routes/policies | Full refresh remains `EXECUTION_PENDING`; isolated predecessor proof does not equal terminal end-to-end refresh. |
| Transient snapshot | Request-owned, bounded, securely cleaned | `PROVEN` contracts exist | End-to-end refresh snapshot use remains `EXECUTION_PENDING`. |
| Encrypted/controlled transfer | Raw material stays on a trusted data path, never GitHub-hosted | `PROVEN` policy boundary | Full-chain transfer remains `EXECUTION_PENDING`. |
| Isolated PREPROD staging DB | Raw import cannot become runtime DB | `PROVEN` implementation/validation surfaces | Full-chain real execution remains `EXECUTION_PENDING`. |
| Deterministic sanitization | Canonical versioned sanitizer/policy, fail closed | `PROVEN` | Component proofs do not by themselves make #816 terminal; full-chain state `EXECUTION_PENDING`. |
| Side-effect hardening | PREPROD egress/state disabled before runtime eligibility | `PROVEN` | Full-chain state `EXECUTION_PENDING`. |
| Candidate assertions/seal | Sanitized candidate asserted, staging/raw transient material removed | `PROVEN` repository architecture | Full-chain state `EXECUTION_PENDING`. |
| Runtime fence | Root-owned maintenance marker enforced by pinned Nginx config | `PROVEN` source capability | Capability provisioning APPLY currently `EXECUTION_PENDING`; data activation remains disabled. |
| PREPROD backup | Server-local protected backup before activation | `PROVEN` architecture | Activation transaction use remains `EXECUTION_PENDING`. |
| Controlled activation | Atomic fixed-purpose DB transition only after separate authority | `PROVEN` implementation design/source | `EXECUTION_PENDING`; `DATA_ACTIVATION_AUTHORITY = DISABLED`. |
| Drupal convergence | UPDB/config import/PREPROD split/admin restore/cache rebuild and validations | `PROVEN` fixed sequence | Real refresh convergence remains `EXECUTION_PENDING`. |
| Validation | Runtime DB, side effects, readiness and health must pass | `PROVEN` contracts | Terminal full-refresh proof remains `EXECUTION_PENDING`. |
| Commit or rollback | Commit only after all gates; otherwise restore pre-state/fail closed | `PROVEN` rollback architecture | Terminal full-refresh commit/rollback proof remains `EXECUTION_PENDING`. |

Never upgrade a component's synthetic or isolated success into a statement that the complete #816 lifecycle was executed.

## 6. Activation and runtime fence

Current repository architecture is the converged result of the #874/#876/#902/#904/#905 lineage and the pending #907 provisioning execution.

### Runtime DB identity proof

The PREPROD PLAN uses the fixed repository-owned runtime DB identity probe under the non-root deploy identity. It proves an active Drupal DB connection with a fixed `SELECT DATABASE()` semantic, emits only a bounded DB identifier, suppresses raw stderr, and fails closed on unavailable/malformed results. Its stripped remote environment carries the fixed deploy-user `HOME=/home/agency-preprod` required by the proven PREPROD runtime.

Relevant sources:

- `scripts/preproduction-refresh/activation-capability/provisioning/runtime-db-identity-probe.py`
- `scripts/preproduction-refresh/activation-capability/provisioning/run-plan.sh`
- `.github/workflows/preprod-891-runtime-db-identity-validation.yml`
- `.github/workflows/preprod-902-runtime-db-probe-validation.yml`
- `.github/workflows/preprod-905-runtime-db-home-validation.yml`

### Fixed-purpose capability

`docs/operations/preproduction-refresh-activation-capability.md` and `scripts/preproduction-refresh/activation-capability/profile.json` define:

- root-owned fixed-purpose helper `/usr/local/sbin/agency-preprod-refresh-control`;
- bounded deploy identity `agency-preprod`;
- no generic shell, Python, MariaDB, path, table or caller-supplied SQL authority;
- outer deploy lock `/var/www/agency-preprod/shared/deploy.lock`;
- privileged refresh lock `/run/lock/agency-preprod-refresh.lock`;
- root-owned state directory and maintenance marker;
- pinned Nginx public fence returning 503 while active;
- loopback-only internal readiness at `127.0.0.1:18087/health/ready` against the same PREPROD runtime;
- server-local pre-activation backup and deterministic state identity;
- atomic base-table activation architecture;
- fixed Drupal convergence sequence;
- rollback to exact pre-state on provisioning/transaction failure where the contract requires it.

### PLAN versus APPLY

`.github/workflows/preprod-874-capability-provisioning.yml` keeps these authorities separate:

- PLAN: GitHub-hosted `ubuntu-24.04`, non-root `agency-preprod`, metadata-only, no helper execution, no sudo, no host mutation.
- provisioning APPLY: trusted `[self-hosted, linux, x64, agency]` executor, fixed runner guard, provisioning root credential, fixed `run-apply.sh`, capability/fence installation only.

Provisioning APPLY does **not** authorize a data refresh or data activation. `DATA_ACTIVATION_AUTHORITY = DISABLED` before and after capability provisioning.

At #908 materialization, #907's authority job has succeeded, its PLAN is skipped by mode, and the capability-only APPLY job remains queued. Therefore the durable classification is:

```text
IMPLEMENTATION = PROVEN
PLAN = PROVEN
REAL PROVISIONING APPLY = EXECUTION_PENDING
DATA_ACTIVATION_AUTHORITY = DISABLED
```

The queue state is observational, not permanent documentation of executor health. Reload #907 and its workflow jobs before any runtime conclusion.

## 7. Privacy and data boundary

The authority is `scripts/preproduction-refresh/sanitization-policy.json` plus `docs/operations/preproduction-data-refresh.md`. Do not invent or relax policy in this runbook.

Hard boundaries:

```text
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW SQL AS GITHUB ARTIFACT = FORBIDDEN
PII IN EVIDENCE/LOGS = FORBIDDEN
PRIVATE FILES = EXCLUDED BY DEFAULT
ENVIRONMENT CREDENTIALS/SETTINGS = ENVIRONMENT-OWNED / NON-TRANSFERRED
```

The current canonical sanitization policy requires, among other things:

- Drupal users anonymized and authentication material invalidated; PREPROD administrative access is restored from PREPROD server-owned state rather than copied from PROD;
- webform submissions deleted;
- sessions and flood state deleted;
- Drupal database logs/watchdog state deleted;
- queues removed by default, including mail/link-checker/AI-provider work; unknown externally capable queues fail closed;
- cache, batch and temporary state cleared;
- cron/update/announcement/link-checker runtime state reset;
- one-time authentication/reset material invalidated or removed;
- persisted provider credentials removed/replaced only from PREPROD-owned state;
- production environment state removed;
- production mail, provider egress, analytics/tagging and equivalent side effects disabled/asserted for PREPROD;
- private files never transferred by this refresh policy; public files are outside the current Phase-1 data scope unless separately governed.

Evidence may contain bounded metadata/digests, never raw SQL, PII, secrets, private files, passwords, session/reset material, DB credentials, or provider tokens.

## 8. Execution capability registry

The canonical execution registry is `docs/operations/execution-capabilities.md`. The dedicated executor runbook is `docs/operations/agency-self-hosted-browser-runner.md`.

Durable known Agency executor architecture:

```text
host = preflight-runner-01
runner = agency-browser-runner-01
account = agency-runner
```

The architecture is a `PROVEN` capability. Current registration/online/busy state is dynamic and must be queried live using the registry/runbook procedure.

Do not infer:

```text
queued job => capability absent
runner offline => capability absent
MCP absent from current ChatGPT cockpit => MCP absent from project executor
```

Instead classify separately:

1. Does the repository define and register the capability?
2. Does the intended workflow have authority for this operation?
3. Is the required executor currently online and not busy?
4. Is the specific job queued, in progress, or completed?

A runner outage is an execution-surface condition. It does not create permission to move a privileged/root credential onto GitHub-hosted infrastructure or to create a parallel workaround.

## 9. Blocked and future work

These items are documented but not enabled by this runbook:

- #907: activation/fence capability-only provisioning APPLY is `EXECUTION_PENDING` while its real job has not reached terminal execution. Do not cancel, rerun or replace it from documentation work.
- #816: complete real PROD -> PREPROD refresh is `EXECUTION_PENDING` and separately gated. No full-refresh command is declared here.
- #872 Editorial Candidate: `DESIGN_ONLY`, dependency-gated.
- #873 Development Seed: `DESIGN_ONLY`, dependency-gated.
- #863 AI / Agent Readiness: `DEFERRED` until the current stability/PREPROD program permits resumption.

The parent documentation program #871 remains open until the environment/data documentation is reconverged after terminal #816 execution.

## 10. Safe operational recipes

These recipes discover authority/evidence; they do not grant mutation authority.

### Reload current main

Query the live `main` branch and record the exact commit SHA before a conclusion or mutation. The GitHub REST resource is:

```text
GET /repos/E-merging-digital/agency-website-drupal/branches/main
```

### Find issue authority

Read the live issue body, state, labels and comments. Confirm that the issue is not a PR and identify the exact allowed mode/action and lineage. Never infer authority from a handoff alone.

### Find workflow, run and job evidence

Locate the repository workflow named by the authority, then inspect the exact run and its jobs. Treat states literally:

- `queued`: not executed;
- `in_progress`: not terminal;
- `completed/success`: terminal success for that job only;
- `completed/failure`: terminal failure;
- `skipped`: not executed.

Do not collapse a queued or skipped APPLY into success.

### Distinguish PLAN and APPLY

Read the workflow and authority validator. Verify the requested mode exactly. PLAN evidence never authorizes APPLY. Capability provisioning APPLY never implies data activation authority.

### Handle one-shot request IDs

Before execution, derive/validate the fresh request ID exactly as the governing route requires. As soon as a one-shot request is emitted for execution, classify it:

```text
CONSUMED / NEVER REUSE
```

Never rerun a consumed request ID. A retry requires a fresh separately valid authority/request identity.

### Locate authoritative implementation

Start from this runbook, then follow the specialized references to the exact workflow, script, policy/profile and validation file. Prefer the existing registered capability; do not invent a parallel executor or workflow merely because an operator-facing surface lacks a tool.

### Classify an executor outage

Keep capability and runtime availability separate. Query live runner/job state. If a job is queued because the required executor is offline/busy, report `EXECUTION_PENDING` plus the observed executor condition. Do not widen credentials or move privileged execution to GitHub-hosted infrastructure as a workaround.

### Classify a technical failure

A repository/runtime/transport/runner failure that can be diagnosed or corrected within project authority is a recoverable technical failure. `recoverable technical failure != HUMAN_REQUIRED`. Escalate to human authority only when the required decision/credential/provider action is genuinely human-only.

### Produce a safe handoff

A handoff should include:

- last reloaded main SHA;
- live issue/PR/run identities;
- exact terminal/non-terminal states;
- consumed request IDs;
- proven cause or bounded uncertainty;
- explicit allowed/forbidden operations;
- data activation state;
- STOP boundary.

Mark it non-authoritative and require a fresh live reload in the receiving conversation.

## 11. Onboarding checklist

A new developer/agent must be able to answer these questions before operating Agency:

1. **What is authoritative?** GitHub, versioned repository state, and execution evidence; not a handoff.
2. **What environment may I mutate?** Only the environment and mutation class explicitly authorized by the current live issue/workflow; local DDEV authority never implies PREPROD/PROD authority.
3. **What is code/config versus data versus editorial content?** Code/config follows immutable artifact promotion; data refresh follows #816; editorial Article publication is separately governed.
4. **What is merely designed?** Check the status model; #872/#873 are `DESIGN_ONLY`, #863 is `DEFERRED`.
5. **What is proven?** Use the capability table and specialized repository validation/evidence, not issue prose alone.
6. **Where do executor capabilities live?** `docs/operations/execution-capabilities.md` and the specialized executor runbook/workflows.
7. **How do I know whether a runner is merely offline?** Reload runner/job state independently of capability registration.
8. **Why can I not move a root secret to GitHub-hosted?** Executor trust/credential boundaries are part of the security contract; an offline self-hosted runner does not widen authority.
9. **Why can I not reuse request IDs?** One-shot identity provides replay protection and exact authority/main binding; emitted IDs are consumed forever.
10. **How do I reload current main?** Read the live branch resource and bind decisions to the exact SHA.
11. **How do I find current workflow authority?** Read the live issue plus validator/workflow from exact live main and inspect the exact run/jobs.
12. **How do I hand off safely?** Transfer observations and boundaries, explicitly state that the handoff is not authority, and require live reload.

## 12. Specialized authoritative references

Use these instead of duplicating their internals:

- `docs/operations/preproduction-data-refresh.md`
- `docs/operations/preproduction-refresh-activation-boundary.md`
- `docs/operations/preproduction-refresh-activation-capability.md`
- `docs/operations/execution-capabilities.md`
- `docs/operations/agency-self-hosted-browser-runner.md`
- `docs/operations/environment-side-effects.md`
- `docs/operations/agency-health-endpoints.md`
- `docs/operations/governed-editorial-publication.md`
- `scripts/preproduction-refresh/sanitization-policy.json`
- `scripts/preproduction-refresh/activation-capability/profile.json`
- `scripts/preproduction-refresh/activation-capability/provisioning/profile.json`
- `.github/workflows/build-release-candidate.yml`
- `.github/workflows/deploy-preproduction.yml`
- `.github/workflows/promote-production.yml`
- `.github/workflows/production-promotion-validation.yml`
- `.github/workflows/preprod-874-capability-provisioning.yml`
- `.github/workflows/preprod-891-runtime-db-identity-validation.yml`
- `.github/workflows/preprod-902-runtime-db-probe-validation.yml`
- `.github/workflows/preprod-905-runtime-db-home-validation.yml`

This document intentionally contains no secret values, no raw data, no full-refresh execution command, and no permanent workflow run number. Dynamic execution status must always be reloaded live.