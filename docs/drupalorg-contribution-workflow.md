# Drupal.org contribution workflow

## Purpose

Agency must be able to answer, reproducibly:

> A Drupal capability has been implemented or proposed. Should it remain
> Agency-specific, be contributed to an existing project/core, or become a new
> contrib candidate?

This workflow is a decision and preparation capability. It is not an autonomous
publishing system.

```text
NEED
-> DRUPAL ECOSYSTEM SEARCH
-> IMPLEMENTATION DECISION
-> GENERALIZATION ANALYSIS when relevant
-> CONTRIB CANDIDATE CLASSIFICATION
-> HUMAN DECISION
-> optional separately-authorized Drupal.org contribution work
```

## Search before build

Search is required when a Drupal capability is substantial and reasonably
likely to exist already.

Evaluate only the surfaces material to the decision:
- Drupal core;
- maintained contrib projects;
- relevant Drupal.org/GitLab issues and merge requests;
- official Drupal documentation;
- existing Agency primitives.

Do not impose a heavy search on trivial fixes.

Expected result:

```text
DRUPAL_DISCOVERY =
EXISTING_SOLUTIONS =
EXISTING_ISSUE_OR_MR =
RESULT = EXISTING_SOLUTION | GAP_PROVEN | UNCERTAIN
```

If an existing solution is adequate, follow `USE EXISTING FIRST`.
If the right solution is an existing core/contrib issue, prefer contributing
there to creating a parallel module.

## Custom -> contrib candidate assessment

A custom implementation is not automatically a contrib candidate.

When a custom capability appears reusable beyond Agency, assess:

```text
GENERALITY =
Does the problem exist beyond this project?

EXISTING_CONTRIB =
Does an existing Drupal project already solve the need?

EXISTING_ISSUE =
Should the change live in an existing project or Drupal core?

CLIENT_COUPLING =
Is the behavior tied to Agency/client data, workflow or configuration?

API_GENERALIZABLE =
Can it be expressed using generic Drupal APIs?

ENTITY_GENERALIZATION =
Is it unnecessarily limited to one entity type/bundle/context?

CONFIG_GENERALIZATION =
Can project-specific choices become configuration?

SECURITY =
Would public reuse create new risks?

PRIVACY =
Does the implementation expose client/project information?

IP =
Are there contractual, copyright or ownership constraints?

MAINTENANCE =
Can E-merging Digital realistically maintain it?

COMMUNITY_VALUE =
Is there credible value for other Drupal sites?

TESTABILITY =
Can it be covered with appropriate Drupal tests?

QUALITY =
Can it reach contribution-grade quality without disproportionate effort?
```

Output:

```text
CONTRIB_CANDIDATE = YES | NO | UNCERTAIN
TARGET = KEEP_CUSTOM | EXISTING_CONTRIB | CORE | NEW_PROJECT
RATIONALE =
CLIENT_SANITATION_REQUIRED = YES | NO
QUALITY_GAPS =
NEXT_ACTION =
```

Do not use a numerical score as a substitute for evidence.

## Human authority boundary

Default Agency authority:

```text
READ_DRUPALORG = ALLOWED
SEARCH_ISSUES = ALLOWED
ANALYZE_PROJECT_OR_ISSUE = ALLOWED
PREPARE_LOCAL_PATCH = ALLOWED
DRAFT_ISSUE_OR_MR_TEXT = ALLOWED

CREATE_ISSUE = EXPLICIT_AUTHORITY_REQUIRED
COMMENT_ISSUE = EXPLICIT_AUTHORITY_REQUIRED
CREATE_ISSUE_FORK = EXPLICIT_AUTHORITY_REQUIRED
PUSH_PUBLIC_BRANCH = EXPLICIT_AUTHORITY_REQUIRED
CREATE_MR = EXPLICIT_AUTHORITY_REQUIRED
UPDATE_RELEASE_OR_PROJECT = SEPARATE_EXPLICIT_AUTHORITY_REQUIRED
MAINTAINER_ACTION = HUMAN_OR_SEPARATELY_GOVERNED
```

A Project Lead classification of `CONTRIB_CANDIDATE=YES` is not publication
authority.

No agent may publish simply because it considers a contribution useful.

## Drupal.org AI accountability

Before any real contribution, re-read the current Drupal.org policy.

Agency's minimum invariant is stricter than tool convenience:
- understand the issue and recent discussion;
- understand every submitted change;
- review and test AI-assisted output;
- fix failures before posting;
- avoid unrequested rewrites and drive-by contributions;
- collaborate with maintainers and respond to feedback;
- verify dependencies, security and licensing;
- disclose significant AI-generated code/text when required by Drupal.org.

AI assistance never transfers accountability away from the human contributor.

## Contribution-grade quality

The exact gates depend on the target project and change.

Evaluate proportionally:
- Drupal coding standards / PHPCS;
- static analysis where used;
- Unit / Kernel / Functional / FunctionalJavascript tests as behavior requires;
- supported Drupal/PHP compatibility;
- deprecations;
- update/schema behavior when relevant;
- configuration schema;
- permissions/access control;
- cacheability;
- entity/service/DI correctness;
- security/privacy;
- documentation and project metadata;
- target Drupal.org/GitLab pipeline.

Do not add every possible test category mechanically. Prove the behavior and the
target project's requirements.

## drupalorg-cli

Use upstream before building an Agency-specific Drupal.org integration.

As of the initial 2026-10-01 evaluation, `drupalorg-cli` provides Drupal.org
and git.drupalcode.org issue/MR operations, MCP support and agent skills.

Do not treat this observed version as permanently authoritative. Before live use:
1. revalidate the latest stable release and current commands;
2. revalidate Drupal.org policy;
3. use read/search operations first;
4. bind any mutation to explicit authority.

When available, prefer:

```text
drupalorg skill:get
```

or the equivalent current mechanism so agent instructions match the installed
CLI version.

Do not vendor stale copies of upstream skills merely for convenience.

## Agency / Preflight boundary

Agency owns:
- architecture and implementation decisions;
- ecosystem discovery;
- generalization analysis;
- contrib-candidate classification;
- client/IP/privacy sanitation decisions;
- contribution preparation;
- human authority.

Preflight may provide independent evidence for standards, static analysis,
compatibility and test/readiness checks when an actual integration exists.

Agency must not depend on an imagined Preflight feature. Reuse only proven
interfaces. Do not duplicate an existing Preflight capability once one is
available.

## First representative slice

The first slice is assessment-only.

For one real or controlled Agency custom capability:

```text
DRUPAL_DISCOVERY = DONE
EXISTING_SOLUTIONS = [...]
GENERALIZATION_ANALYSIS = [...]
CONTRIB_CANDIDATE = YES | NO | UNCERTAIN
TARGET = KEEP_CUSTOM | EXISTING_CONTRIB | CORE | NEW_PROJECT
RATIONALE = [...]
CLIENT_SANITATION_REQUIRED = YES | NO
QUALITY_GAPS = [...]
NEXT_ACTION = [...]
```

No Drupal.org publication is required to prove the capability.

## Anti-overengineering

Do not create, without a demonstrated need:
- a contribution database;
- a generic forge abstraction;
- an Agency Drupal.org orchestrator;
- a publication bot;
- a dedicated UI;
- a new MCP layer when upstream tooling is sufficient;
- a scoring engine;
- a full contribution pipeline before a real candidate exists.

```text
SEARCH_BEFORE_BUILD
-> EVIDENCE_BEFORE_CONTRIB_CLASSIFICATION
-> HUMAN_BEFORE_PUBLIC_MUTATION
-> MINIMUM_NECESSARY
```
