#!/usr/bin/env python3
from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path

BASE = Path(__file__).resolve().parent
CONTRACT = BASE / "transaction_contract.py"
ABORT = BASE / "agency-preprod-refresh-authority-abort"
INSTALLER = BASE / "agency-preprod-refresh-authority-install"
CONTROL = BASE / "agency-preprod-refresh-control"
INGRESS = BASE / "agency-preprod-refresh-ingress"
CONTROL_SUDO = BASE / "provisioning/agency-preprod-refresh-control.sudoers"
INGRESS_SUDO = BASE / "provisioning/agency-preprod-refresh-ingress.sudoers"

spec = importlib.util.spec_from_file_location("agency917_contract", CONTRACT)
assert spec and spec.loader
contract = importlib.util.module_from_spec(spec)
spec.loader.exec_module(contract)


def reject(fn, label: str) -> None:
    try:
        fn()
    except Exception:
        print(f"{label}=FAIL_CLOSED")
        return
    raise AssertionError(f"{label} unexpectedly accepted")


def envelope() -> dict:
    return {
        "schema_version": 1,
        "successor_issue": 918,
        "request_id": "apply-918-917abort-r1",
        "main_sha": "9" * 40,
        "profile_id": contract.PROFILE_ID,
        "allowed_actions": list(contract.MUTATING_ACTIONS),
        "snapshot_bytes": 64,
        "snapshot_sha256": "a" * 64,
    }


def abort_request(state: dict) -> dict:
    return {
        "successor_issue": state["successor_issue"],
        "request_id": state["request_id"],
        "main_sha": state["main_sha"],
        "profile_id": state["profile_id"],
        "authority_id": state["authority_id"],
    }


def main() -> None:
    state = contract.new_authority_state(envelope())
    request = abort_request(state)
    assert state["state"] == contract.STATE_ARMED
    assert state["phase"] == contract.PHASE_AWAITING_INGRESS
    assert state["terminal"] is False
    assert contract.STATE_ABORTED == "ABORTED"
    assert contract.STATE_ABORTED in contract.TERMINAL_STATES

    parsed = contract.parse_abort_request(request)
    assert parsed == request
    bound, _ = contract.validate_pre_ingress_abort_binding(state, request)
    assert bound == state
    terminal = contract.pre_ingress_aborted_state(state, request)
    assert terminal["state"] == contract.STATE_ABORTED
    assert terminal["phase"] == contract.PHASE_TERMINAL
    assert terminal["terminal"] is True
    assert terminal["human_recovery_required"] is False
    contract.validate_aborted_terminal_binding(terminal, request)
    print("ARMED_AWAITING_INGRESS_ABORT=PASS")
    print("ABORTED_TERMINAL_STATE=PASS")
    print("EXACT_BINDING=PASS")

    mutations = {
        "WRONG_ISSUE": {"successor_issue": 999},
        "WRONG_REQUEST": {"request_id": "apply-999-wrong-r1"},
        "WRONG_MAIN": {"main_sha": "8" * 40},
        "WRONG_PROFILE": {"profile_id": "wrong-profile"},
        "WRONG_AUTHORITY_ID": {"authority_id": "0" * 64},
    }
    for label, change in mutations.items():
        bad = dict(request)
        bad.update(change)
        reject(lambda b=bad: contract.validate_pre_ingress_abort_binding(state, b), label)

    for forbidden in ("path", "filename", "sql", "database", "table", "executable", "command", "state"):
        bad = dict(request)
        bad[forbidden] = "caller-controlled"
        reject(lambda b=bad: contract.parse_abort_request(b), f"CALLER_{forbidden.upper()}")

    wrong_state = copy.deepcopy(state)
    wrong_state.update(state=contract.STATE_IN_PROGRESS, phase=contract.PHASE_IMPORTING)
    reject(lambda: contract.validate_pre_ingress_abort_binding(wrong_state, request), "WRONG_STATE")
    wrong_phase = copy.deepcopy(state)
    wrong_phase["phase"] = contract.PHASE_SNAPSHOT_READY
    reject(lambda: contract.validate_pre_ingress_abort_binding(wrong_phase, request), "WRONG_PHASE")
    for terminal_state in (contract.STATE_COMMITTED, contract.STATE_ROLLED_BACK, contract.STATE_FAILED_RECOVERY):
        value = copy.deepcopy(state)
        value.update(state=terminal_state, phase=contract.PHASE_TERMINAL, terminal=True)
        if terminal_state == contract.STATE_FAILED_RECOVERY:
            value["human_recovery_required"] = True
        reject(lambda v=value: contract.validate_pre_ingress_abort_binding(v, request), f"TERMINAL_{terminal_state}")
    reject(lambda: contract.validate_pre_ingress_abort_binding(terminal, request), "SECOND_ABORT_TERMINAL")

    for key, value in (
        ("preactivation_runtime_sha256", "b" * 64),
        ("application_release_sha256", "c" * 64),
        ("backup_sha256", "d" * 64),
        ("backup_bytes", 1),
        ("human_recovery_required", True),
    ):
        bad = copy.deepcopy(state)
        bad[key] = value
        reject(lambda b=bad: contract.validate_pre_ingress_abort_binding(b, request), f"NON_NULL_{key.upper()}")

    corrupt = copy.deepcopy(state)
    corrupt["authority_id"] = "0" * 64
    reject(lambda: contract.validate_authority_state(corrupt), "CORRUPTED_ACTIVE_AUTHORITY")
    reject(lambda: contract.rollback_strategy(contract.PHASE_AWAITING_INGRESS), "AWAITING_INGRESS_ROLLBACK")
    print("ABORT_IS_NOT_ROLLBACK=PASS")

    abort_text = ABORT.read_text()
    installer_text = INSTALLER.read_text()
    combined_sudo = CONTROL_SUDO.read_text() + "\n" + INGRESS_SUDO.read_text()
    assert "/run/lock/agency-preprod-refresh-authority.lock" in abort_text
    assert "atomic_replace_active(terminal)" in abort_text
    assert abort_text.index("atomic_replace_active(terminal)") < abort_text.index("write_history_exclusive(history_path, terminal)")
    assert abort_text.index("write_history_exclusive(history_path, terminal)") < abort_text.index("os.unlink(ACTIVE_AUTHORITY)")
    assert "os.path.lexists(FENCE_MARKER)" in abort_text
    for token in ("MARIADB_BIN", "subprocess.run", "/bin/bash", "sudo ", "shell=True"):
        assert token not in abort_text
    for forbidden in ("agency-preprod-refresh-authority-abort", "agency-preprod-refresh-authority-install", "NOPASSWD: ALL", " SETENV:", "mariadb", "bash", "python", " env"):
        assert forbidden not in combined_sudo
    assert "One-shot transaction authority replay refused." in installer_text
    assert "Active transaction collision/hijack refused." in installer_text
    assert "ABORT" not in json.dumps(list(contract.MUTATING_ACTIONS))
    assert "ABORT" not in CONTROL.read_text().split("def dispatch", 1)[1]
    assert "ABORT" not in INGRESS.read_text()
    print("CRASH_RECOVERY_MODEL=ACTIVE_TERMINAL_FIRST_HISTORY_SECOND")
    print("NORMAL_SUDO_EXPOSURE=NONE")
    print("GENERIC_EXECUTION=NONE")
    print("PERMANENT_ENABLE=NONE")
    print("LOCAL_917_CONTRACT=PASS")


if __name__ == "__main__":
    main()
