# Agent-Ready trajectory — bounded post-PROD roadmap

Status: **durable roadmap / implementation only by explicit Project Lead authority**.

Authority: `docs/decisions/ADR-003-agent-ready-drupal-capabilities.md`.

External/adoption roadmap: `docs/research/external-capability-adoption-backlog.md`.

## Current gate

The historical PREPROD -> PROD stabilization gate has been satisfied with durable
runtime evidence. Agent-Ready work is therefore no longer globally blocked by
that gate, but **nothing in this document creates automatic runtime authority**.

```text
PREPROD_TO_PROD_GATE = SATISFIED
AGENT_READY_RUNTIME_IMPLEMENTATION = EXPLICIT_AUTHORITY_ONLY
CURRENT_TECHNICAL_PRIORITY = #962 WHEN REQUIRED RUNNER IS AVAILABLE
#962_CURRENT_STATE = HOLD_ON_SELF_HOSTED_RUNNER
ONE_ACTIVE_P2_PILOT = MAXIMUM
```

When #962 cannot progress because the required runner is unavailable, Project
Lead may select bounded hosted-only work from the roadmap. This must not create a
parallel infrastructure path merely to bypass the temporary runner outage.

## Architecture convergence

Agency should support two first-class consumers of the same authoritative Drupal
content and capabilities:

```text
HUMAN_EXPERIENCE
+
AGENT_EXPERIENCE
```

`Agent Experience` is not a second website or a permission shortcut. It combines
machine-readable semantics, discovery, bounded capabilities, explicit identity /
account / authority, deterministic rules where practical, validation and
receipts.

Durable principles:

```text
DISCOVER_BEFORE_MUTATE = REQUIRED
GENERIC_DRUPAL_KNOWLEDGE != PROJECT_COMPATIBILITY
MODEL_CONTEXT = CACHE_NOT_AUTHORITY
PROJECT_INDEX = CACHE_NOT_AUTHORITY
CAPABILITY != AUTHORITY
ACCOUNT_IS_PART_OF_AUTHORITY = YES
LLMS_TXT != AUTHORITY
MODEL != POLICY_ENGINE
UNKNOWN_EXECUTION_STATE -> RECONCILE_NOT_REPLAY
EXECUTION -> VALIDATION -> RECEIPT
```

## Ordered trajectory

### A. Audit SDC machine readability

Inventory the governed SDC/component catalogue and determine what Drupal/SDC
already exposes natively for identity, purpose, props, slots, required fields,
variants, constraints, accessibility and references.

Goal: identify real metadata gaps before inventing any project-specific format.

### B. Define the governed component catalogue contract

Define the smallest structured, provider-independent contract needed by humans,
Drupal AI, Codex and other agents.

Prefer derivation from Drupal/SDC metadata. Do not create a parallel source of
truth.

### C. Expose the component catalogue read-only

First concrete Agent-Ready capability family:

```text
list_components()
get_component_schema(component)
```

Names are illustrative, not a frozen API.

Required properties: read-only, permission-aware, structured output, no secrets,
no mutation, deterministic enough for tests.

### D. Evaluate WebMCP / Tool API as read-only adapters

Evaluate current WebMCP/Site Tools/MCP standards only after the domain capability
contract is proven. Candidate use cases include component discovery, design
rules, page structure, content requirements, validation, draft status and
publication readiness.

Do not make browser registration, MCP connection or model approval the security
authority.

### E. Converge browser and non-browser agent capabilities

Codex, ForgePilot and browser agents should consume the same bounded Drupal domain
services where practical instead of scraping the administrative DOM or exposing
arbitrary Drush/entity operations.

Identity, selected account and authority scope must remain explicit.

### F. Project discovery before mutation

Agents should discover the live project's installed modules, versions, content
model, SDC catalogue, workflows and relevant constraints before planning changes.

A generated project index or context summary is useful only as a cache:

```text
PROJECT_INDEX = CACHE_NOT_AUTHORITY
LIVE_REPOSITORY_AND_RUNTIME = AUTHORITATIVE
```

A bounded discovery/index pilot may compare scan cost and correctness before any
persistent indexing architecture is adopted.

### G. Skills instead of repeated giant prompts

Evaluate one low-risk, maintained Drupal skill/procedure for a read-only or
static-analysis task. Compare it to the equivalent repeated prompt/handoff.

A skill is an executable supply-chain input, not merely documentation. Require
provenance, reviewed revision, declared preconditions and least privilege.

Do not invent an Agency skill if a maintained Drupal/upstream skill already covers
the need.

### H. Governed draft composition

Only after read-only capabilities are proven, evaluate controlled draft
operations such as composing approved SDC components or updating draft content.

```text
draft != publish
```

remains absolute.

### I. Governed publication workflows

Publication and external effects are separately governed capabilities. They
require explicit permissions, moderation/workflow proof, auditability,
rollback/recovery and separate Project Lead authorization.

## P1 convergence work

The current strategic P1 items are intentionally few:

1. **GitHub Actions supply chain** — pin reviewed third-party actions to immutable
   full commit SHAs and audit `GITHUB_TOKEN` permissions without redesigning CI.
2. **Agent Experience principle** — fold semantic machine exposure and explicit
   identity/account/authority into #863/#390 rather than creating a new platform.

These items do not automatically preempt #962 when its runner becomes available.

## P2 pilots — one at a time

Current candidates are tracked in
`docs/research/external-capability-adoption-backlog.md`:

1. FlowDrop synthetic orchestration pilot;
2. project discovery/index comparison;
3. one reusable Drupal skill pilot;
4. Codex context-management long-task pilot;
5. hosted CI egress/Harden-Runner observation.

Only one P2 pilot should be active at a time. A pilot must answer a concrete gap
question and stop when that question is answered.

## Preserved ideas — not automatically READY

### Agent-readable SDC catalogue

Expose the governed SDC catalogue using native metadata as far as possible.

### WebMCP Site Tools

Expose selected authenticated Agency capabilities through a read-only-first
adapter.

### MCP capability adapter

Allow non-browser agents to consume the same structured domain capabilities.

### Governed page composition

Allow agents to compose drafts from approved SDC components without arbitrary
HTML/CSS generation.

### Design-system-aware agents

Expose relevant design rules and constraints in machine-readable form while
keeping `DESIGN.md`, tokens and governed components authoritative.

### AI-assisted content operations

Target flow:

```text
research
  -> content proposal
  -> structured draft
  -> governed SDC composition
  -> validation
  -> human/editor review
  -> publication
```

No massive autonomous publishing is implied or authorized.

## Commercial sequencing

Agent-Ready architecture is an enabling layer, not an acronym-led commercial
positioning.

A future offer may evolve from `AI / Agent Readiness Audit` toward
`Agent-Ready Drupal Architecture` only when the technical proof exists.

Sell outcomes:

- machine readability and semantic structure;
- bounded capabilities;
- explicit permissions and human approval;
- provenance and auditability;
- supply-chain posture;
- agent development readiness.

Do not sell “we install MCP / llms.txt / FlowDrop”.

Agency should continue improving public content, landing pages, SEO and technical
proof in parallel where those tasks do not depend on blocked infrastructure.
Drupal migration/EOL expertise remains a targeted acquisition channel without
making E-merging Digital appear Drupal-only.

## External capability discovery

The wider adoption program is preserved in:

`docs/research/external-capability-adoption-backlog.md`

It includes:

- FlowDrop;
- DrupalClaw patterns/skills/index ideas;
- Codex context management and account-scoped approvals;
- GPT-6 Astra readiness without model dependency;
- GitHub Actions supply-chain hardening;
- egress/Harden-Runner observation;
- Open Design;
- community/upstream skills;
- content naturalisation;
- ownership Agency / Preflight / ForgePilot;
- licence and supply-chain review;
- content/acquisition ideas.

Every named upstream is a dated snapshot. Reload its current state before making a
decision.

## Re-entry checklist

Before creating or resuming implementation work from this trajectory:

- reload live repository, issues, PRs and active priorities;
- revalidate ADR-001 and ADR-003;
- inspect the current Drupal/SDC/Canvas/Drupal AI primitives;
- consult the external capability roadmap if an upstream may reduce custom work;
- reload the upstream, licence, maintenance and security state;
- verify whether the capability already exists in Agency, Preflight or ForgePilot;
- perform a bounded gap analysis;
- choose exactly one vertical slice;
- preserve one issue = one branch = one PR;
- start read-only unless a stronger need is explicitly demonstrated;
- stop when the requested outcome is sufficiently proven.

## Execution boundary

```text
ROADMAP != IMPLEMENTATION_AUTHORITY
NEW_FRAMEWORK = FORBIDDEN_BY_DEFAULT
NEW_DEPENDENCY = EXPLICIT_ISSUE_ONLY
PREPROD_MUTATION = EXPLICIT_GATE_ONLY
PROD_MUTATION = EXPLICIT_GATE_ONLY
MINIMUM_NECESSARY = REQUIRED
WHEN_REQUESTED_OUTCOME_IS_PROVEN = STOP
```
