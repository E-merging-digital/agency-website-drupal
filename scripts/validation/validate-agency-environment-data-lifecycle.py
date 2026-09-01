#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOC = ROOT / "docs/operations/agency-environment-data-lifecycle.md"
REGISTRY = ROOT / "docs/operations/execution-capabilities.md"
REFRESH = ROOT / "docs/operations/preproduction-data-refresh.md"
PROVIDER = ROOT / ".ddev/providers/agency.yaml"
DISPATCHER = ROOT / ".github/workflows/agency-command-dispatch.yml"
PREPROD_WORKFLOW = ROOT / ".github/workflows/preprod-914-governed-successor.yml"

ACTIVE_REUSABLE_WORKFLOWS = (
    ".github/workflows/promote-production.yml",
    ".github/workflows/production-scheduler-change.yml",
    ".github/workflows/trusted-editorial-publication.yml",
    ".github/workflows/trusted-editorial-feature-image.yml",
    ".github/workflows/preprod-914-governed-successor.yml",
)

REQUIRED_PATHS = (
    "docs/operations/agency-environment-data-lifecycle.md",
    "docs/operations/execution-capabilities.md",
    "docs/operations/agency-self-hosted-browser-runner.md",
    "docs/operations/preproduction.md",
    "docs/deployment.md",
    "docs/operations/preproduction-data-refresh.md",
    "docs/operations/preproduction-refresh-governed-successor.md",
    "docs/operations/environment-side-effects.md",
    "docs/operations/governed-editorial-publication.md",
    "docs/operations/development-seed.md",
    "scripts/preproduction-refresh/sanitization-policy.json",
    "scripts/preproduction-refresh/governed-successor/profile.json",
    "scripts/preproduction-refresh/governed-successor/validate-execution-authority.py",
    "scripts/preproduction-refresh/governed-successor/run-plan.sh",
    "scripts/preproduction-refresh/governed-successor/run-apply.sh",
    "scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh",
    "scripts/development-seed/sanitization-policy.json",
    ".ddev/config.yaml",
    ".ddev/config.development-seed.yaml",
    ".ddev/providers/agency.yaml",
    ".github/workflows/ci.yml",
    ".github/workflows/build-release-candidate.yml",
    ".github/workflows/deploy-preproduction.yml",
    ".github/workflows/agency-command-dispatch.yml",
    *ACTIVE_REUSABLE_WORKFLOWS,
)

REQUIRED_HEADINGS = (
    "## 1. Purpose, authority and status vocabulary",
    "## 2. Environment map",
    "## 3. Ownership matrix",
    "## 4. Four distinct operational flows",
    "## 5. Current capability/status matrix",
    "## 6. Code/configuration recipe",
    "## 7. PROD -> PREPROD refresh recipe (#816 / current #914)",
    "## 8. Privacy and data classification",
    "## 9. Backup, rollback and recovery",
    "## 10. Development Seed / DDEV",
    "## 11. Editorial publication boundary",
    "## 12. Settings, Config Split and secrets ownership",
    "## 13. Failure classification",
    "## 14. Evidence, request IDs and replay",
    "## 15. Onboarding, handoff and rebaseline",
    "## 16. Authoritative links",
)

STATUS_VOCABULARY = (
    "DESIGN_ONLY",
    "SOURCE_IMPLEMENTED",
    "SYNTHETICALLY_PROVEN",
    "PROVISIONED",
    "EXECUTABLE",
    "REAL_EXECUTION_PROVEN",
    "EXECUTION_PENDING",
)

AUTHORITY_INVARIANTS = (
    "GitHub + repository + execution evidence = source of truth",
    "handoff != authority",
    "implementation authority != execution authority",
    "PLAN != APPLY",
    "CONSUMED / NEVER REUSE",
    "recoverable technical failure != HUMAN_REQUIRED",
    "operator-surface capability != project-executor capability",
    "CAPABILITY EXISTS != EXECUTOR CURRENTLY ONLINE",
)

STALE_CURRENT_WORKFLOW_REFERENCES = (
    ".github/workflows/preprod-874-capability-provisioning.yml",
    ".github/workflows/preprod-891-runtime-db-identity-validation.yml",
    ".github/workflows/preprod-902-runtime-db-probe-validation.yml",
    ".github/workflows/preprod-905-runtime-db-home-validation.yml",
    ".github/workflows/preprod-915-fixed-refresh-capability-validation.yml",
    ".github/workflows/preprod-917-pre-ingress-authority-abort-validation.yml",
)

HISTORICAL_COMMANDS_NOT_CURRENT = (
    "/agency-config-language inspect",
    "/agency-config-language-lock evaluate",
)


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> int:
    for path in REQUIRED_PATHS:
        require((ROOT / path).is_file(), f"required current path does not exist: {path}")

    doc = DOC.read_text(encoding="utf-8")
    registry = REGISTRY.read_text(encoding="utf-8")
    refresh = REFRESH.read_text(encoding="utf-8")
    provider = PROVIDER.read_text(encoding="utf-8")
    dispatcher = DISPATCHER.read_text(encoding="utf-8")
    preprod_workflow = PREPROD_WORKFLOW.read_text(encoding="utf-8")

    for heading in REQUIRED_HEADINGS:
        require(heading in doc, f"missing required canonical heading: {heading}")

    for state in STATUS_VOCABULARY:
        require(f"`{state}`" in doc or state in doc, f"missing status vocabulary: {state}")

    for invariant in AUTHORITY_INVARIANTS:
        require(invariant in doc, f"missing authority invariant: {invariant}")

    for flow in (
        "### A. CODE / CONFIG",
        "### B. DATA REFRESH",
        "### C. EDITORIAL CONTENT",
        "### D. DEVELOPMENT DATA",
    ):
        require(flow in doc, f"missing operational flow: {flow}")

    # #922 current topology: exactly one top-level issue_comment listener and
    # exactly five reusable command workflows. Dispatch is syntax, not authority.
    listener_files = []
    for workflow_path in sorted((ROOT / ".github/workflows").glob("*.yml")):
        if "issue_comment:" in workflow_path.read_text(encoding="utf-8"):
            listener_files.append(workflow_path.relative_to(ROOT).as_posix())
    require(listener_files == [".github/workflows/agency-command-dispatch.yml"],
            f"unexpected issue_comment listeners: {listener_files}")
    require("Route syntax only; authorization remains downstream" in dispatcher,
            "dispatcher incorrectly owns execution authorization")
    for path in ACTIVE_REUSABLE_WORKFLOWS:
        text = (ROOT / path).read_text(encoding="utf-8")
        require("workflow_call:" in text, f"active command workflow is not reusable: {path}")
        require("issue_comment:" not in text, f"active reusable still owns top-level issue_comment: {path}")
        require(path in dispatcher, f"dispatcher does not route current reusable workflow: {path}")
        require(path in doc, f"canonical doc missing current reusable workflow: {path}")
        require(path in registry, f"registry missing current reusable workflow: {path}")
    require("#922 is **COMPLETED**" in doc, "#922 is not documented as completed current state")
    require("#922 is **COMPLETED**" in registry, "registry does not document completed dispatcher consolidation")

    # Current #914/#816 truth.
    require("#914 is `SOURCE_IMPLEMENTED` + `SYNTHETICALLY_PROVEN`" in doc,
            "current #914 source/synthetic status missing")
    require("#816 remains open" in doc, "#816 pending real state missing")
    require("REAL_END_TO_END_REFRESH = NOT_YET_PROVEN" in refresh,
            "refresh doc incorrectly implies terminal real execution")
    require("REAL_APPLY = PENDING" in refresh, "real APPLY pending state missing")
    require("runs-on: [self-hosted, linux, x64, agency]" in preprod_workflow,
            "current #914 PLAN/APPLY self-hosted execution boundary missing")
    require("#927" in doc and "PENDING / PROPOSED ADAPTATION" in doc,
            "#927 pending adaptation status missing")
    require("#927" in registry and "PENDING / PROPOSED ADAPTATION" in registry,
            "#927 pending adaptation missing from registry")
    require("PLAN_RUNNER = GitHub-hosted" not in doc + registry,
            "#927 proposed PLAN runner is incorrectly presented as current")

    for stale in STALE_CURRENT_WORKFLOW_REFERENCES:
        require(stale not in doc, f"stale current workflow reference in canonical doc: {stale}")
        require(stale not in registry, f"stale current workflow reference in registry: {stale}")

    require("No GitHub transaction reconstruction" in refresh,
            "obsolete recovery model is not explicitly rejected")
    require("not operational dependencies" in doc,
            "#915/#917 historical lineage is not clearly non-operational")

    # Historical command surfaces removed by #922 must not be resurrected as
    # current lifecycle/registry commands solely for old tests or documentation.
    for command in HISTORICAL_COMMANDS_NOT_CURRENT:
        require(command not in doc, f"historical command reintroduced in canonical doc: {command}")
        require(command not in registry, f"historical command reintroduced in registry: {command}")

    # Editorial current/future boundary.
    require("#576 bounded Article" in doc, "current #576 editorial route missing")
    require("#872 Editorial Candidate" in doc and "`DESIGN_ONLY`" in doc,
            "#872 future/not-implemented status missing")
    require("PREPROD DB -> PROD" in doc, "editorial/data no-DB-promotion boundary missing")

    # #873 source/synthetic current state, real service still pending.
    require("#873" in doc and "SOURCE_IMPLEMENTED" in doc and "SYNTHETICALLY_PROVEN" in doc,
            "current #873 source/synthetic status missing")
    require("REAL_PREPROD_SEED_GENERATION = EXECUTION_PENDING" in doc,
            "real Development Seed generation pending state missing")
    require("DDEV_PUSH = NONE" in doc, "DDEV push prohibition missing")
    require("ddev pull agency" in doc, "current DDEV pull command missing")
    require("db_push_command" not in provider, "Development Seed provider exposes DB push")
    require("files_push_command" not in provider, "Development Seed provider exposes files push")

    for ownership in (
        "**CODE**",
        "**CONFIG**",
        "**EDITORIAL CONTENT**",
        "**DATABASE DATA**",
        "**ENVIRONMENT SETTINGS**",
        "**SECRETS**",
        "**FILES**",
    ):
        require(ownership in doc, f"ownership class missing: {ownership}")

    for privacy in (
        "RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN",
        "RAW SQL AS GITHUB ARTIFACT = FORBIDDEN",
        "PII IN EVIDENCE/LOGS = FORBIDDEN",
        "PRIVATE FILES = EXCLUDED",
    ):
        require(privacy in doc, f"privacy invariant missing: {privacy}")

    require("HUMAN_RECOVERY_REQUIRED" in doc and "maintenance stays ON" in doc,
            "current fail-closed rollback boundary missing")
    require("DDEV's native snapshot" in doc, "DDEV native rollback model missing")

    for registry_contract in (
        "## 2. Current command dispatcher (#922 completed)",
        "## 3. Current operational capability index",
        "Same-artifact functional PROD promotion",
        "Governed Article publication",
        "PROD -> PREPROD sanitized DB refresh",
        "Development Seed -> DDEV pull-only",
        "CURRENT_IMPLEMENTATION = #914",
        "REAL_END_TO_END_REFRESH = EXECUTION_PENDING",
    ):
        require(registry_contract in registry, f"registry not synchronized: {registry_contract}")

    # Dynamic evidence and secret-shaped assignments do not belong in current docs.
    for name, text in (("canonical", doc), ("refresh", refresh), ("registry", registry)):
        require(not re.search(r"\b3\d{10}\b", text),
                f"ephemeral workflow run number embedded in {name} documentation")
        for forbidden in (
            "PREPROD_PROVISIONING_SSH_PRIVATE_KEY=",
            "DB_PASSWORD=",
            "SSH_PRIVATE_KEY=",
        ):
            require(forbidden not in text,
                    f"secret-shaped assignment forbidden in {name} documentation: {forbidden}")

    print("CANONICAL_LIFECYCLE_DOC=PRESENT")
    print("CURRENT_OPERATIONAL_FLOWS=4")
    print("ENVIRONMENT_OWNERSHIP_MATRIX=COMPLETE")
    print("SINGLE_COMMAND_DISPATCHER=DOCUMENTED_CURRENT")
    print("ACTIVE_REUSABLE_COMMANDS=5")
    print("DISPATCHER_AUTHORITY=NONE")
    print("CURRENT_914_MODEL=DOCUMENTED")
    print("CURRENT_914_PLAN_RUNNER=SELF_HOSTED")
    print("ISSUE_927=PENDING_NOT_CURRENT")
    print("REAL_816_EXECUTION=NOT_YET_PROVEN")
    print("EDITORIAL_576=CURRENT")
    print("EDITORIAL_872=DESIGN_ONLY")
    print("DEVELOPMENT_SEED_SOURCE=SYNTHETICALLY_PROVEN")
    print("DEVELOPMENT_SEED_REAL_DISTRIBUTION=PENDING")
    print("DDEV_PUSH=NONE")
    print("STALE_CURRENT_WORKFLOW_REFERENCES=0")
    print("HISTORICAL_CONFIG_LANGUAGE_COMMAND_REINTRODUCED=NO")
    print("PRIVACY_CLASSIFICATION=COMPLETE")
    print("BACKUP_ROLLBACK_MODEL=CURRENT")
    print("EXECUTION_CAPABILITY_REGISTRY=SYNCHRONIZED")
    print("EPHEMERAL_RUN_NUMBERS=ABSENT")
    print("DOC_CONTRACT=SUCCESS")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except AssertionError as exc:
        print(f"DOC_CONTRACT=FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1)
