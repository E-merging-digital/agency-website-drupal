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

STALE_CURRENT_STATUS_REFERENCES = (
    "#927 is a **PENDING / PROPOSED ADAPTATION**",
    "#927 is **PENDING / PROPOSED ADAPTATION**",
    "current PLAN remains self-hosted",
    "Current `main` still runs both #914 PLAN and APPLY on the trusted Agency self-hosted runner",
    "PLAN               = [self-hosted, linux, x64, agency]",
)


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def has_top_level_issue_comment_trigger(text: str) -> bool:
    """Detect issue_comment only inside the root-level YAML `on` mapping."""
    lines = text.splitlines()
    in_on = False
    for line in lines:
        if not in_on:
            if line == "on:":
                in_on = True
                continue
            if re.fullmatch(r"on:\s*issue_comment\s*", line):
                return True
            continue

        if line and not line.startswith((" ", "\t")):
            return False
        if re.fullmatch(r"  issue_comment:\s*", line):
            return True
    return False


def job_runs_on(workflow: str, job: str, expected: str) -> bool:
    pattern = rf"(?ms)^  {re.escape(job)}:\n.*?^    runs-on: {re.escape(expected)}\s*$"
    return re.search(pattern, workflow) is not None


def main() -> int:
    for path in REQUIRED_PATHS:
        require((ROOT / path).is_file(), f"required current path does not exist: {path}")

    doc = DOC.read_text(encoding="utf-8")
    registry = REGISTRY.read_text(encoding="utf-8")
    refresh = REFRESH.read_text(encoding="utf-8")
    provider = PROVIDER.read_text(encoding="utf-8")
    dispatcher = DISPATCHER.read_text(encoding="utf-8")
    preprod_workflow = PREPROD_WORKFLOW.read_text(encoding="utf-8")
    current_docs = doc + "\n" + registry + "\n" + refresh

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

    listener_files = []
    for workflow_path in sorted((ROOT / ".github/workflows").glob("*.yml")):
        if has_top_level_issue_comment_trigger(workflow_path.read_text(encoding="utf-8")):
            listener_files.append(workflow_path.relative_to(ROOT).as_posix())
    require(listener_files == [".github/workflows/agency-command-dispatch.yml"],
            f"unexpected issue_comment listeners: {listener_files}")
    require("Route syntax only; authorization remains downstream" in dispatcher,
            "dispatcher incorrectly owns execution authorization")

    for path in ACTIVE_REUSABLE_WORKFLOWS:
        text = (ROOT / path).read_text(encoding="utf-8")
        require("workflow_call:" in text, f"active command workflow is not reusable: {path}")
        require(not has_top_level_issue_comment_trigger(text),
                f"active reusable still owns top-level issue_comment: {path}")
        require(path in dispatcher, f"dispatcher does not route current reusable workflow: {path}")
        require(path in doc, f"canonical doc missing current reusable workflow: {path}")
        require(path in registry, f"registry missing current reusable workflow: {path}")

    require("#922 is **COMPLETED**" in doc, "#922 is not documented as completed")
    require("#922 is **COMPLETED**" in registry, "registry does not document #922 completed")

    # Current #914/#927 runner split and security boundary.
    require(job_runs_on(preprod_workflow, "validate-authority", "ubuntu-24.04"),
            "#914 authority job is not current GitHub-hosted")
    require(job_runs_on(preprod_workflow, "plan", "ubuntu-24.04"),
            "#914 PLAN is not current GitHub-hosted ubuntu-24.04")
    require(job_runs_on(preprod_workflow, "apply", "[self-hosted, linux, x64, agency]"),
            "#914 APPLY trusted runner boundary changed")
    require("runner.environment" in preprod_workflow and "github-hosted" in preprod_workflow,
            "PLAN hosted-runner JIT assertion missing")
    require("JIT revalidate before PLAN SSH secrets" in preprod_workflow,
            "PLAN JIT-before-secret boundary missing")

    require("#914 is `SOURCE_IMPLEMENTED` + `SYNTHETICALLY_PROVEN`" in doc,
            "current #914 source/synthetic status missing")
    require("#927 is **CLOSED / COMPLETED**" in doc,
            "#927 completed status missing from canonical doc")
    require("#927 is **CLOSED / COMPLETED**" in registry,
            "#927 completed status missing from registry")
    require("PLAN_RUNNER = ubuntu-24.04 / github-hosted" in refresh,
            "refresh contract missing current hosted PLAN runner")
    require("APPLY_RUNNER = self-hosted / linux / x64 / agency" in refresh,
            "refresh contract missing current APPLY runner")

    # Real #929 PLAN evidence is factual and deliberately bounded.
    for required in (
        "REAL_PLAN = EXECUTED / FAIL_CLOSED",
        "REAL_END_TO_END_REFRESH = NOT_YET_PROVEN",
        "PROD_WRITE = NONE",
    ):
        require(required in refresh, f"refresh evidence missing: {required}")
    require("#929" in doc and "returned `FAIL_CLOSED`" in doc,
            "canonical doc missing real #929 fail-closed evidence")
    require("exact failed readiness predicate is **NOT YET PROVEN**" in doc,
            "canonical doc does not preserve unknown #929 readiness cause")
    require("REAL_METADATA_ONLY_PLAN = EXECUTED" in registry,
            "registry missing #929 real PLAN execution")
    require("FAILED_READINESS_PREDICATE = NOT_YET_PROVEN" in registry,
            "registry incorrectly claims #929 readiness cause")

    # #930 is recovery work in progress, not current implementation.
    require("#930 is the **CURRENT RECOVERY / IN PROGRESS**" in doc,
            "canonical doc missing #930 in-progress recovery status")
    require("#930 is **CURRENT RECOVERY / IN PROGRESS**" in registry,
            "registry missing #930 in-progress recovery status")
    require("#930 is not yet implemented/current behavior" in refresh,
            "refresh doc incorrectly implies #930 is implemented")

    require("#816 remains OPEN/in-progress" in doc,
            "#816 pending real state missing")
    require("REAL_END_TO_END_REFRESH = NOT_YET_PROVEN" in refresh,
            "refresh doc incorrectly implies terminal real execution")
    require("REAL_APPLY = PENDING" in refresh,
            "real APPLY pending state missing")

    for stale in STALE_CURRENT_WORKFLOW_REFERENCES:
        require(stale not in doc, f"stale current workflow reference in canonical doc: {stale}")
        require(stale not in registry, f"stale current workflow reference in registry: {stale}")

    for stale in STALE_CURRENT_STATUS_REFERENCES:
        require(stale not in current_docs, f"stale current status reference remains: {stale}")

    require(all(term in refresh for term in (
        "RECOVER_CURRENT",
        "RECOVER_ABORT",
        "GitHub transaction reconstruction",
        "historical target lookup",
    )), "obsolete recovery model is not explicitly rejected")
    require("not operational dependencies" in doc,
            "#915/#917 historical lineage is not clearly non-operational")

    for command in HISTORICAL_COMMANDS_NOT_CURRENT:
        require(command not in doc, f"historical command reintroduced in canonical doc: {command}")
        require(command not in registry, f"historical command reintroduced in registry: {command}")

    # Editorial current/future boundary.
    require("#576 bounded Article" in doc, "current #576 editorial route missing")
    require("#872 Editorial Candidate" in doc and "`DESIGN_ONLY`" in doc,
            "#872 future/not-implemented status missing")
    require("PREPROD DB -> PROD" in doc, "editorial/data no-DB-promotion boundary missing")

    # #873 source/synthetic current state, real service pending #816.
    require("#873" in doc and "SOURCE_IMPLEMENTED" in doc and "SYNTHETIC_PROOF = COMPLETE" in doc,
            "current #873 source/synthetic status missing")
    require("REAL_PREPROD_SEED_GENERATION = PENDING #816" in doc,
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

    # Current docs contain durable classifications, not hardcoded transient run IDs or secrets.
    for name, text in (("canonical", doc), ("refresh", refresh), ("registry", registry)):
        require(not re.search(r"\b3\d{10}\b", text),
                f"ephemeral workflow run number embedded in {name} documentation")
        for forbidden in (
            "PREPROD_PROVISIONING_SSH_PRIVATE_KEY=",
            "DB_PASSWORD=",
            "SSH_PRIVATE_KEY=",
        ):
            require(forbidden not in text,
                    f"secret-shaped assignment forbidden in {name}: {forbidden}")

    print("CANONICAL_LIFECYCLE_DOC=PRESENT")
    print("CURRENT_OPERATIONAL_FLOWS=4")
    print("ENVIRONMENT_OWNERSHIP_MATRIX=COMPLETE")
    print("SINGLE_COMMAND_DISPATCHER=DOCUMENTED_CURRENT")
    print("ACTIVE_REUSABLE_COMMANDS=5")
    print("DISPATCHER_AUTHORITY=NONE")
    print("CURRENT_914_MODEL=DOCUMENTED")
    print("CURRENT_914_PLAN_RUNNER=GITHUB_HOSTED_UBUNTU_24_04")
    print("CURRENT_914_APPLY_RUNNER=SELF_HOSTED_LINUX_X64_AGENCY")
    print("ISSUE_927=CLOSED_COMPLETED")
    print("ISSUE_929_REAL_PLAN=FAIL_CLOSED_CAUSE_UNPROVEN")
    print("ISSUE_930=OPEN_IN_PROGRESS")
    print("REAL_816_EXECUTION=NOT_YET_PROVEN")
    print("EDITORIAL_576=CURRENT")
    print("EDITORIAL_872=DESIGN_ONLY")
    print("DEVELOPMENT_SEED_SOURCE=SYNTHETICALLY_PROVEN")
    print("DEVELOPMENT_SEED_REAL_DISTRIBUTION=PENDING")
    print("DDEV_PUSH=NONE")
    print("STALE_CURRENT_WORKFLOW_REFERENCES=0")
    print("STALE_CURRENT_STATUS_REFERENCES=0")
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
