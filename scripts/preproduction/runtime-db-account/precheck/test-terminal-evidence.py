#!/usr/bin/env python3
from __future__ import annotations

import os
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
FINALIZER = HERE / "finalize-precheck-evidence.py"
RUNNER = HERE / "run-precheck.sh"
REPO = Path(__file__).resolve().parents[4]
WORKFLOW = REPO / ".github" / "workflows" / "preprod-895-runtime-db-precheck.yml"

RC0 = "\n".join(
    (
        "TARGET_DATABASE_PRESENT=YES",
        "ACCOUNT_127_PRESENT=YES",
        "ACCOUNT_LOCALHOST_PRESENT=NO",
        "EXPECTED_DB_GRANT=EXACT",
        "RUNTIME_ACCOUNT_STATE=EXACT",
    )
) + "\n"
RC1 = "\n".join(
    (
        "TARGET_DATABASE_PRESENT=UNKNOWN_FAIL_CLOSED",
        "ACCOUNT_127_PRESENT=UNKNOWN_FAIL_CLOSED",
        "ACCOUNT_LOCALHOST_PRESENT=UNKNOWN_FAIL_CLOSED",
        "EXPECTED_DB_GRANT=UNKNOWN_FAIL_CLOSED",
        "RUNTIME_ACCOUNT_STATE=UNSAFE",
    )
) + "\n"


def materialize(path: Path, content: str) -> None:
    path.write_text(content, encoding="utf-8")
    os.chmod(path, 0o600)


def invoke(
    evidence: Path,
    status: Path,
    *,
    setup: str = "success",
    execute: str,
    cleanup: str = "success",
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            sys.executable,
            str(FINALIZER),
            "--evidence",
            str(evidence),
            "--status",
            str(status),
            "--setup-outcome",
            setup,
            "--execute-outcome",
            execute,
            "--cleanup-outcome",
            cleanup,
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
    )


def assert_no_residue(evidence: Path, status: Path) -> None:
    assert not os.path.lexists(evidence)
    assert not os.path.lexists(status)


with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    evidence = root / "result.evidence"
    status = root / "result.status"
    materialize(evidence, RC0)
    materialize(status, "0\n")
    result = invoke(evidence, status, execute="success")
    assert result.returncode == 0
    assert result.stdout == RC0
    assert "PRECHECK_TERMINAL_EVIDENCE=REJECTED" not in result.stderr
    assert_no_residue(evidence, status)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    evidence = root / "result.evidence"
    status = root / "result.status"
    materialize(evidence, RC1)
    materialize(status, "1\n")
    result = invoke(evidence, status, execute="failure")
    assert result.returncode == 1
    assert result.stdout == RC1
    assert "PRECHECK_TERMINAL_EVIDENCE=REJECTED" not in result.stderr
    assert_no_residue(evidence, status)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    evidence = root / "result.evidence"
    status = root / "result.status"
    materialize(evidence, RC1)
    result = invoke(evidence, status, execute="failure")
    assert result.returncode == 1
    assert result.stdout == ""
    assert "PRECHECK_TERMINAL_EVIDENCE=REJECTED" in result.stderr
    assert_no_residue(evidence, status)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    evidence = root / "result.evidence"
    status = root / "result.status"
    materialize(evidence, "TARGET_DATABASE_PRESENT=UNKNOWN_FAIL_CLOSED\nBROKEN=1\n")
    materialize(status, "1\n")
    result = invoke(evidence, status, execute="failure")
    assert result.returncode == 1
    assert result.stdout == ""
    assert "PRECHECK_TERMINAL_EVIDENCE=REJECTED" in result.stderr
    assert_no_residue(evidence, status)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    evidence = root / "result.evidence"
    status = root / "result.status"
    materialize(evidence, RC1)
    materialize(status, "1\n")
    result = invoke(evidence, status, execute="failure", cleanup="failure")
    assert result.returncode == 1
    assert result.stdout == ""
    assert_no_residue(evidence, status)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    evidence = root / "result.evidence"
    status = root / "result.status"
    materialize(evidence, RC1)
    materialize(status, "1\n")
    result = invoke(evidence, status, execute="success")
    assert result.returncode == 1
    assert result.stdout == ""
    assert_no_residue(evidence, status)

runner = RUNNER.read_text(encoding="utf-8")
workflow = WORKFLOW.read_text(encoding="utf-8")

assert 'rm -f -- "$raw_output" "$raw_error"' in runner
assert "trap cleanup_best_effort EXIT HUP INT TERM" in runner
assert 'precheck_status="$RUNNER_TEMP/agency-895-precheck-${GITHUB_RUN_ID}.status"' in workflow
assert 'precheck_rc="$(tr -d \'\\r\\n\' < "$precheck_status")"' in workflow
assert 'exit "$precheck_rc"' in workflow
assert "finalize-precheck-evidence.py" in workflow
assert "EXECUTE_OUTCOME" in workflow
assert "CLEANUP_OUTCOME" in workflow
assert '[[ "$EXECUTE_OUTCOME" == \'success\' ]]' not in workflow

print("FAIL_CLOSED_METADATA_EMISSION=FIXED")
print("RC0_OUTCOME_CONSISTENCY=PROVEN")
print("RC1_OUTCOME_CONSISTENCY=PROVEN")
print("RC1_TERMINAL_RESULT=FAILURE_WITH_BOUNDED_EVIDENCE")
print("UNEXPECTED_FAILURE_METADATA=NONE_NOT_FABRICATED")
print("MALFORMED_EVIDENCE=REJECTED")
print("CLEANUP_FAILURE_METADATA=SUPPRESSED")
print("RUNNER_TEMP_RAW_STDERR_CLEANUP=PRESERVED")
print("RUNNER_TEMP_EVIDENCE_RESIDUE=NONE")
print("RUNNER_TEMP_STATUS_RESIDUE=NONE")
