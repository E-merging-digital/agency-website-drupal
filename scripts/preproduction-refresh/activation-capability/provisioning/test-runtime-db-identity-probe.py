#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
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
    print("C_DRUSH_MISSING=BOUNDED_FAIL_CLOSED")
    print("D_DRUSH_NOT_EXECUTABLE=BOUNDED_FAIL_CLOSED")


def test_exact_and_safe_mismatch_observation() -> None:
    assert probe.classify_command_result(0, b"agency_preprod\n") == (
        probe.OBSERVED,
        probe.ZERO,
        "agency_preprod",
    )
    assert probe.classify_command_result(0, b"other_preprod\n") == (
        probe.OBSERVED,
        probe.ZERO,
        "other_preprod",
    )
    print("A_EXPECTED_DB_PROBE=OBSERVED")
    print("B_SAFE_MISMATCH_PROBE=OBSERVED_FOR_EVALUATOR")


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
    print("E_DRUSH_NONZERO=BOUNDED_FAIL_CLOSED")
    print("F_EMPTY_OUTPUT=FAIL_CLOSED")


def test_unsafe_output_never_flattens() -> None:
    unsafe = [
        b"agency_\npreprod\n",
        b"agency_preprod\n\n",
        b"agency_preprod\r\nextra\r\n",
        b"agency-preprod\n",
        b"agency preprod\n",
        b"agency_preprod\x00suffix\n",
        b"caf\xc3\xa9\n",
        (b"a" * 65) + b"\n",
    ]
    for stdout in unsafe:
        state, exit_class, db_name = probe.classify_command_result(0, stdout)
        assert state == probe.OUTPUT_INVALID
        assert exit_class == probe.ZERO
        assert db_name == "NONE"
    print("G_MULTILINE_UNSAFE_OUTPUT=FAIL_CLOSED")
    print("G_NEWLINE_FLATTENING=FORBIDDEN")


def test_privacy_contract() -> None:
    source = (HERE / "runtime-db-identity-probe.py").read_text(encoding="utf-8")
    assert "stderr=subprocess.DEVNULL" in source
    assert "settings.php" not in source
    assert "runtime.env" not in source
    assert "printenv" not in source
    assert "os.environ" not in source
    assert "shell=True" not in source
    assert "sql" not in source.lower()
    assert "--field=db-name" in source
    assert "DRUSH = CURRENT / \"vendor/bin/drush\"" in source
    assert "CURRENT = Path(\"/var/www/agency-preprod/current\")" in source
    print("H_DRUSH_RAW_STDERR=ABSENT")
    print("H_SECRET_RUNTIME_CONTENT=ABSENT")
    print("H_FIXED_RUNTIME_PATHS=PASS")


def main() -> None:
    test_preconditions()
    test_exact_and_safe_mismatch_observation()
    test_nonzero_and_empty()
    test_unsafe_output_never_flattens()
    test_privacy_contract()
    print("#891_RUNTIME_DB_PROBE_MATRIX=PASS")


if __name__ == "__main__":
    main()
