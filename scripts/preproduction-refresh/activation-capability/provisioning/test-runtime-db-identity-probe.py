#!/usr/bin/env python3
from __future__ import annotations

import contextlib
import importlib.util
import io
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "runtime_db_identity_probe", HERE / "runtime-db-identity-probe.py"
)
assert SPEC and SPEC.loader
probe = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = probe
SPEC.loader.exec_module(probe)


def test_preconditions() -> None:
    assert probe.precondition_state(
        current_is_symlink=False, drush_exists=False, drush_executable=False
    ) == (probe.RELEASE_UNAVAILABLE, probe.NOT_RUN, "NONE")
    assert probe.precondition_state(
        current_is_symlink=True, drush_exists=False, drush_executable=False
    ) == (probe.DRUSH_MISSING, probe.NOT_RUN, "NONE")
    assert probe.precondition_state(
        current_is_symlink=True, drush_exists=True, drush_executable=False
    ) == (probe.DRUSH_NOT_EXECUTABLE, probe.NOT_RUN, "NONE")
    assert probe.precondition_state(
        current_is_symlink=True, drush_exists=True, drush_executable=True
    ) is None
    print("MISSING_CURRENT_SYMLINK=FAIL_CLOSED")
    print("MISSING_DRUSH=FAIL_CLOSED")
    print("UNEXECUTABLE_DRUSH=FAIL_CLOSED")


def test_fixed_active_connection_command() -> None:
    command = probe.runtime_command()
    assert command == [str(probe.DRUSH), "--quiet", "php:eval", probe.PHP_EVAL]
    assert probe.PHP_EVAL.count("SELECT DATABASE()") == 1
    assert "\\Drupal::database()->query" in probe.PHP_EVAL
    assert "getConnectionInfo" not in probe.PHP_EVAL
    print("ACTIVE_CONNECTION_QUERY=FIXED_SELECT_DATABASE")
    print("CALLER_SQL=IMPOSSIBLE")
    print("CALLER_PHP=IMPOSSIBLE")


def test_successful_active_db_connection() -> None:
    assert probe.classify_command_result(0, b"agency_preprod\n") == (
        probe.OBSERVED,
        probe.ZERO,
        "agency_preprod",
    )
    assert probe.classify_command_result(0, b"agency_preprod") == (
        probe.OBSERVED,
        probe.ZERO,
        "agency_preprod",
    )
    assert probe.classify_command_result(0, b"other_preprod\n") == (
        probe.OBSERVED,
        probe.ZERO,
        "other_preprod",
    )
    print("SUCCESSFUL_ACTIVE_DB_CONNECTION=OBSERVED/ZERO/agency_preprod")
    print("SAFE_MISMATCH_PROBE=OBSERVED_FOR_EVALUATOR")


def test_nonzero_and_empty() -> None:
    assert probe.classify_command_result(1, b"") == (
        probe.DRUSH_FAILED,
        probe.NONZERO,
        "NONE",
    )
    assert probe.classify_command_result(1, b"secret-looking-output\n") == (
        probe.DRUSH_FAILED,
        probe.NONZERO,
        "NONE",
    )
    assert probe.classify_command_result(0, b"") == (
        probe.OUTPUT_EMPTY,
        probe.ZERO,
        "NONE",
    )
    assert probe.classify_command_result(0, b"\n") == (
        probe.OUTPUT_EMPTY,
        probe.ZERO,
        "NONE",
    )
    print("COMMAND_FAILURE=FAIL_CLOSED")
    print("EMPTY_OUTPUT=FAIL_CLOSED")


def test_unsafe_output_never_flattens() -> None:
    cases = {
        "MULTILINE_OUTPUT": b"agency_\npreprod\n",
        "REPEATED_NEWLINE": b"agency_preprod\n\n",
        "CR_INJECTION": b"agency_preprod\r\n",
        "NUL": b"agency_preprod\x00suffix\n",
        "NON_ASCII": b"caf\xc3\xa9\n",
        "UNSAFE_IDENTIFIER_DASH": b"agency-preprod\n",
        "UNSAFE_IDENTIFIER_SPACE": b"agency preprod\n",
        "OVERLONG_IDENTIFIER": (b"a" * 65) + b"\n",
    }
    for label, stdout in cases.items():
        state, exit_class, db_name = probe.classify_command_result(0, stdout)
        assert state == probe.OUTPUT_INVALID, label
        assert exit_class == probe.ZERO, label
        assert db_name == "NONE", label
        print(f"{label}=FAIL_CLOSED")


def test_root_and_caller_arguments_forbidden() -> None:
    stderr = io.StringIO()
    with contextlib.redirect_stderr(stderr):
        assert probe.main(["runtime-db-identity-probe.py", "unexpected"]) == 64

    original_geteuid = probe.os.geteuid
    probe.os.geteuid = lambda: 0
    try:
        stderr = io.StringIO()
        with contextlib.redirect_stderr(stderr):
            assert probe.main(["runtime-db-identity-probe.py"]) == 65
    finally:
        probe.os.geteuid = original_geteuid

    print("CALLER_ARGUMENTS=FORBIDDEN")
    print("ROOT_EXECUTION=FORBIDDEN")


def test_privacy_contract() -> None:
    source = (HERE / "runtime-db-identity-probe.py").read_text(encoding="utf-8")
    assert "stderr=subprocess.DEVNULL" in source
    assert "stdout=subprocess.PIPE" in source
    assert "settings.php" not in source
    assert "runtime.env" not in source
    assert "printenv" not in source
    assert "os.environ" not in source
    assert "shell=True" not in source
    assert "getConnectionInfo" not in source
    assert "status\", \"--field=db-name" not in source
    assert "php:eval" in source
    assert "SELECT DATABASE()" in source
    assert "DRUSH = CURRENT / \"vendor/bin/drush\"" in source
    assert "CURRENT = Path(\"/var/www/agency-preprod/current\")" in source
    assert "SAFE_DB_NAME = re.compile(r\"^[A-Za-z0-9_]{1,64}$\")" in source
    print("OLD_AUTHORITATIVE_DEPENDENCY=REMOVED")
    print("SECRET_LEAKAGE=NONE")
    print("DRUSH_RAW_STDERR=ABSENT")
    print("FIXED_RUNTIME_PATHS=PASS")


def main() -> None:
    test_preconditions()
    test_fixed_active_connection_command()
    test_successful_active_db_connection()
    test_nonzero_and_empty()
    test_unsafe_output_never_flattens()
    test_root_and_caller_arguments_forbidden()
    test_privacy_contract()
    print("#891_RUNTIME_DB_PROBE_MATRIX=PASS")
    print("#902_RUNTIME_DB_ACTIVE_CONNECTION_MATRIX=PASS")


if __name__ == "__main__":
    main()
