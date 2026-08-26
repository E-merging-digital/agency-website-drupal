# Agent-Ready trajectory — post-PROD only

Status: **durable backlog / not authorized for runtime implementation yet**.

Authority: `docs/decisions/ADR-003-agent-ready-drupal-capabilities.md`.

## Current gate

Agency's operational priority remains the PREPROD -> PROD stabilization program.

```text
CURRENT_PRIORITY = PREPROD_TO_PROD_STABILIZATION
AGENT_READY_RUNTIME_IMPLEMENTATION = BLOCKED
UNBLOCK_CONDITION = PREPROD_TO_PROD_HEALTHY_WITH_EVIDENCE
```

This document preserves the future trajectory so it cannot be lost while the Ops
program is completed. It does **not** create READY implementation work and does
not authorize changes to #816, #834, #842/#843 or related production paths.

## Ordered trajectory after the gate

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

First real Agent-Ready capability candidate:

```text
list_components()
get_component_schema(component)
```

Names are illustrative, not a frozen API.

Required properties: read-only, permission-aware, structured output, no secrets,
no mutation, deterministic enough for tests.

### D. Evaluate WebMCP as a read-only adapter

Evaluate current WebMCP/Site Tools standards only after the catalogue contract is
proven. Candidate use cases include component discovery, design rules, page
structure, content requirements, validation, draft status and publication
readiness.

Do not make browser registration the security authority.

### E. Evaluate API/MCP convergence

Determine how Codex, ForgePilot and non-browser agents can consume the same
structured domain capabilities without scraping the back-office DOM.

MCP/API and WebMCP should converge on the same Drupal services and access rules
when possible.

### F. Governed draft composition

Only after read-only capabilities are proven, evaluate controlled draft
operations such as composing approved SDC components or updating draft content.

`draft != publish` remains absolute.

### G. Governed publication workflows

Publication/external effects are a later and separately governed capability.
They require explicit permissions, moderation/workflow proof, auditability,
rollback/recovery and separate Project Lead authorization.

## Preserved ideas — not issues yet

The following ideas are intentionally retained here without turning each one into
an active GitHub issue:

### Agent-readable SDC catalog

Expose the governed SDC component catalogue to AI agents using native metadata as
far as possible.

### WebMCP Site Tools

Expose selected authenticated Agency back-office capabilities through WebMCP,
starting read-only.

### MCP capability adapter

Allow non-browser agents such as Codex/ForgePilot to consume the same structured
capabilities.

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

Agent-Ready architecture is an enabling layer, not the commercial positioning of
the public site.

After PREPROD -> PROD stabilization and the bounded architecture work above,
Agency can move into the dedicated content/acquisition phase: improve public
content, landing pages, SEO and technical proof, then execute commercial
campaigns. Drupal migration/EOL expertise can be a strong targeted acquisition
channel without making E-merging Digital appear Drupal-only.

## Re-entry checklist after PROD is healthy

Before creating the first implementation issue from this trajectory:

- reload live repository and current upstream Drupal/SDC/Drupal AI state;
- revalidate ADR-001 and ADR-003;
- inspect the live SDC/component catalogue;
- verify whether Context Control Center or successor primitives remain relevant;
- re-evaluate MCP/WebMCP standards rather than relying on this document's 2026
  assumptions;
- choose exactly one first vertical slice;
- preserve one issue = one branch = one PR;
- start read-only unless a stronger need is explicitly demonstrated.
