#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOC = ROOT / "docs/operations/agency-environment-data-lifecycle.md"
REGISTRY = ROOT / "docs/operations/execution-capabilities.md"

REQUIRED_PATHS = (
    "docs/operations/agency-environment-data-lifecycle.md",
    "docs/operations/execution-capabilities.md",
    "docs/operations/agency-self-hosted-browser-runner.md",
    "docs/operations/preproduction-data-refresh.md",
    "docs/operations/preproduction-refresh-activation-boundary.md",
    "docs/operations/preproduction-refresh-activation-capability.md",
    "docs/operations/environment-side-effects.md",
    "docs/operations/agency-health-endpoints.md",
    "docs/operations/governed-editorial-publication.md",
    "scripts/preproduction-refresh/sanitization-policy.json",
    "scripts/preproduction-refresh/activation-capability/profile.json",
    "scripts/preproduction-refresh/activation-capability/provisioning/profile.json",
    "scripts/preproduction-refresh/activation-capability/provisioning/run-plan.sh",
    "scripts/preproduction-refresh/activation-capability/provisioning/runtime-db-identity-probe.py",
    ".github/workflows/build-release-candidate.yml",
    ".github/workflows/deploy-preproduction.yml",
    ".github/workflows/promote-production.yml",
    ".github/workflows/production-promotion-validation.yml",
    ".github/workflows/preprod-891-runtime-db-identity-validation.yml",
)

REQUIRED_HEADINGS = (
    "## 1. Status model",
    "## 2. Authority model",
    "## 3. Environment map",
    "## 4. Code and configuration flow",
    "## 5. PROD -> PREPROD data refresh (#816)",
    "## 6. Activation and runtime fence",
    "## 7. Privacy and data boundary",
    "## 8. Execution capability registry",
    "## 9. Blocked and future work",
    "## 10. Safe operational recipes",
    "## 11. Onboarding checklist",
)

STATUS_VOCABULARY = (
    "PROVEN",
    "EXECUTABLE",
    "PROVISIONING_PENDING",
    "EXECUTION_PENDING",
    "DESIGN_ONLY",
    "DEFERRED",
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

PRIVACY_INVARIANTS = (
    "RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN",
    "RAW SQL AS GITHUB ARTIFACT = FORBIDDEN",
    "PII IN EVIDENCE/LOGS = FORBIDDEN",
    "PRIVATE FILES = EXCLUDED BY DEFAULT",
    "DATA_ACTIVATION_AUTHORITY = DISABLED",
)


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> int:
    require(DOC.is_file(), "canonical lifecycle document is missing")
    require(REGISTRY.is_file(), "execution capability registry is missing")

    text = DOC.read_text(encoding="utf-8")

    for path in REQUIRED_PATHS:
        require((ROOT / path).is_file(), f"referenced path does not exist: {path}")
        require(f"`{path}`" in text or path == str(DOC.relative_to(ROOT)), f"canonical document does not reference: {path}")

    for heading in REQUIRED_HEADINGS:
        require(heading in text, f"missing required heading: {heading}")

    for state in STATUS_VOCABULARY:
        require(f"`{state}`" in text, f"missing status vocabulary: {state}")

    for invariant in AUTHORITY_INVARIANTS:
        require(invariant in text, f"missing authority invariant: {invariant}")

    for invariant in PRIVACY_INVARIANTS:
        require(invariant in text, f"missing privacy invariant: {invariant}")

    require("host = preflight-runner-01" in text, "missing known executor host")
    require("runner = agency-browser-runner-01" in text, "missing known executor runner")
    require("account = agency-runner" in text, "missing known executor account")
    require("MCP absent from current ChatGPT cockpit => MCP absent from project executor" in text, "missing MCP/executor distinction")

    require("#907" in text and "EXECUTION_PENDING" in text, "#907 pending capability is not documented")
    require("#872" in text and "DESIGN_ONLY" in text, "#872 design-only state is not documented")
    require("#873" in text and "DESIGN_ONLY" in text, "#873 design-only state is not documented")
    require("#863" in text and "DEFERRED" in text, "#863 deferred state is not documented")

    require("one-shot request ID" in text, "one-shot request identity contract is missing")
    require("Never rerun a consumed request ID" in text, "request ID reuse prohibition is missing")
    require("no full-refresh execution command" in text, "fake full-refresh command prohibition is missing")

    require(not re.search(r"\b3\d{10}\b", text), "ephemeral workflow run number embedded in canonical document")

    for forbidden in ("PREPROD_PROVISIONING_SSH_PRIVATE_KEY=", "DB_PASSWORD=", "SSH_PRIVATE_KEY="):
        require(forbidden not in text, f"secret-shaped assignment is forbidden in canonical document: {forbidden}")

    print("CANONICAL_LIFECYCLE_DOC=PRESENT")
    print("CRITICAL_REFERENCED_PATHS=EXIST")
    print("STATUS_VOCABULARY=PRESENT")
    print("AUTHORITY_INVARIANTS=PRESENT")
    print("DATA_ACTIVATION_AUTHORITY=DOCUMENTED_AS_DISABLED")
    print("RAW_PROD_DATA_GITHUB_HOSTED=FORBIDDEN")
    print("REQUEST_ID_REUSE=FORBIDDEN")
    print("CAPABILITY_EXISTS_VS_EXECUTOR_ONLINE=DISTINGUISHED")
    print("EPHEMERAL_RUN_NUMBERS=ABSENT")
    print("DOC_CONTRACT=SUCCESS")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except AssertionError as exc:
        print(f"DOC_CONTRACT=FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1)
