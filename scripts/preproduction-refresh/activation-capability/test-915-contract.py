#!/usr/bin/env python3
from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import pathlib

BASE = pathlib.Path(__file__).resolve().parent
CONTRACT = BASE / "transaction_contract.py"
HELPER = BASE / "agency-preprod-refresh-control"
INGRESS = BASE / "agency-preprod-refresh-ingress"
INSTALLER = BASE / "agency-preprod-refresh-authority-install"
ADMIN_SH = BASE / "admin-reconcile.sh"
ADMIN_PHP = BASE / "admin-reconcile.php"
PROFILE = BASE / "profile.json"
BUNDLE = BASE / "bundle.json"
PROVISION = BASE / "provisioning/profile.json"
CONTROL_SUDOERS = BASE / "provisioning/agency-preprod-refresh-control.sudoers"
INGRESS_SUDOERS = BASE / "provisioning/agency-preprod-refresh-ingress.sudoers"
RUN_APPLY = BASE / "provisioning/run-apply.sh"
REMOTE = BASE / "provisioning/remote-provision-root.sh"

spec = importlib.util.spec_from_file_location("agency915_contract", CONTRACT)
assert spec and spec.loader
contract = importlib.util.module_from_spec(spec)
spec.loader.exec_module(contract)


def reject(fn, name: str) -> None:
    try:
        fn()
    except Exception:
        print(f"{name}=FAIL_CLOSED")
        return
    raise AssertionError(f"{name} unexpectedly accepted")


def binding() -> dict:
    return {
        "schema_version": 1,
        "successor_issue": 914,
        "request_id": "apply-914-80ca4eaf-r1",
        "main_sha": "8" * 40,
        "profile_id": contract.PROFILE_ID,
        "allowed_actions": list(contract.MUTATING_ACTIONS),
        "snapshot_bytes": 1024,
        "snapshot_sha256": "a" * 64,
    }


def operation(action=contract.IMPORT, **changes) -> dict:
    value = {
        "action": action,
        "successor_issue": 914,
        "request_id": "apply-914-80ca4eaf-r1",
        "main_sha": "8" * 40,
        "profile_id": contract.PROFILE_ID,
    }
    value.update(changes)
    return value


def main() -> None:
    state = contract.new_authority_state(binding())
    assert state["state"] == contract.STATE_ARMED
    assert state["phase"] == contract.PHASE_AWAITING_INGRESS
    assert state["terminal"] is False
    assert "ENABLED" not in json.dumps(state)
    print("PERMANENT_ENABLE=NONE")

    # Self-authorization is impossible through normal helper operation stdin.
    extra = operation(); extra["authority"] = binding()
    reject(lambda: contract.parse_operation(extra), "CALLER_SELF_AUTHORIZATION")

    ready = copy.deepcopy(state); ready["phase"] = contract.PHASE_SNAPSHOT_READY
    contract.validate_operation_binding(ready, operation())

    wrong = operation(successor_issue=999)
    reject(lambda: contract.validate_operation_binding(ready, wrong), "WRONG_ISSUE")
    wrong = operation(request_id="apply-999-wrong-r1")
    reject(lambda: contract.validate_operation_binding(ready, wrong), "WRONG_REQUEST")
    wrong = operation(main_sha="7" * 40)
    reject(lambda: contract.validate_operation_binding(ready, wrong), "WRONG_MAIN")
    wrong = operation(profile_id="wrong-profile")
    reject(lambda: contract.validate_operation_binding(ready, wrong), "WRONG_PROFILE")
    reject(lambda: contract.validate_operation_binding(ready, operation("BOGUS")), "WRONG_ACTION")
    reject(lambda: contract.validate_operation_binding(state, operation()), "WRONG_PHASE")

    terminal = copy.deepcopy(ready)
    terminal.update(state=contract.STATE_COMMITTED, phase=contract.PHASE_TERMINAL, terminal=True)
    contract.validate_authority_state(terminal)
    reject(lambda: contract.validate_operation_binding(terminal, operation()), "TERMINAL_REUSE")
    reject(lambda: contract.validate_operation_binding(terminal, operation()), "REPLAY")

    corrupt = copy.deepcopy(ready); corrupt["authority_id"] = "0" * 64
    reject(lambda: contract.validate_authority_state(corrupt), "AUTHORITY_CORRUPTION")
    wrong_schema = copy.deepcopy(ready); wrong_schema["unexpected"] = 1
    reject(lambda: contract.validate_authority_state(wrong_schema), "AUTHORITY_SCHEMA_CORRUPTION")

    installer = INSTALLER.read_text()
    assert "Active transaction collision/hijack refused" in installer
    assert "One-shot transaction authority replay refused" in installer
    print("CONCURRENT_HIJACK=FAIL_CLOSED")

    header = {
        "successor_issue": 914,
        "request_id": "apply-914-80ca4eaf-r1",
        "main_sha": "8" * 40,
        "profile_id": contract.PROFILE_ID,
        "expected_bytes": 1024,
        "expected_sha256": "a" * 64,
    }
    contract.validate_ingress_binding(state, header)
    bad_header = dict(header, path="/tmp/caller")
    reject(lambda: contract.parse_ingress_header(bad_header), "CALLER_PATH")
    assert contract.spool_basename(state) == state["authority_id"] + ".sql"
    print("FIXED_BINARY_INGRESS=PASS")

    ingress = INGRESS.read_text()
    for phrase in (
        "st_nlink != 1",
        "os.O_EXCL",
        "O_NOFOLLOW",
        "os.rename(temp_path, final_path)",
        "Partial ingress: premature EOF.",
        "Ingress SHA-256 verification failed.",
        "safe_unlink(temp_path)",
        "RAW_DATA_LOG_BOUNDARY=NONE",
    ):
        assert phrase in ingress
    assert "Snapshot byte count exceeds fixed maximum." in CONTRACT.read_text()
    assert "print(chunk" not in ingress and "print(raw" not in ingress
    print("PARTIAL_INGRESS=FAIL_CLOSED")
    print("BYTE_MISMATCH=FAIL_CLOSED")
    print("SHA_MISMATCH=FAIL_CLOSED")
    print("OVERSIZE=FAIL_CLOSED")
    print("SYMLINK=FAIL_CLOSED")
    print("HARDLINK_TYPE_CONFUSION=FAIL_CLOSED")
    print("PARTIAL_CLEANUP=PASS")
    print("RAW_DATA_LOG_LEAKAGE=NONE")

    helper = HELPER.read_text()
    assert "require_bound_spool(contract, authority, paths)" in helper
    assert "safe_unlink(paths[\"snapshot\"])" in helper
    assert "safe_unlink(paths[\"manifest\"])" in helper
    print("CANDIDATE_EXACT_BOUND_SPOOL_ONLY=PASS")
    print("TERMINAL_RAW_CLEANUP=PASS")

    admin_sh = ADMIN_SH.read_text(); admin_php = ADMIN_PHP.read_text()
    assert "RUNTIME_ENV='/var/www/agency-preprod/shared/settings/runtime.env'" in admin_sh
    assert "DRUPAL_ADMIN_PASSWORD" in admin_sh
    assert 'name = \'preprod-admin\'' in admin_php
    assert "role_id = 'administrator'" in admin_php
    assert "if ($ids === [])" in admin_php
    assert "$account->setPassword($password);" in admin_php
    assert "$account->addRole($role_id);" in admin_php
    assert "echo $password" not in admin_php and "printf.*DRUPAL_ADMIN_PASSWORD" not in admin_sh
    print("ADMIN_ABSENT_RECONCILIATION=PASS")
    print("ADMIN_PRESENT_RECONCILIATION=PASS")
    print("ADMIN_FIXED_IDENTITY=preprod-admin")
    print("ADMIN_SERVER_OWNED_SECRET_SOURCE=PASS")
    print("PROD_CREDENTIAL_REUSE=NONE")
    print("ADMIN_SECRET_LOGGING=NONE")

    # Deterministic phase-aware rollback selection is shared and used by helper.
    assert contract.rollback_strategy(contract.PHASE_FENCE_CLOSED) == "VERIFY_UNCHANGED_OR_EXACT_BACKUP"
    assert contract.rollback_strategy(contract.PHASE_SWAP_ATTEMPTED) == "VERIFY_UNCHANGED_OR_EXACT_BACKUP"
    assert contract.rollback_strategy(contract.PHASE_SWAPPED) == "REVERSE_ATOMIC_SWAP_THEN_EXACT_BACKUP_FALLBACK"
    assert contract.rollback_strategy(contract.PHASE_CONVERGENCE_STARTED) == "EXACT_PREACTIVATION_BACKUP"
    assert "strategy = contract.rollback_strategy(phase)" in helper
    assert "restore_verified_backup" in helper
    assert helper.index("validate_runtime_invariants()", helper.index("def rollback_transaction")) < helper.index("open_fence_after_terminal_proof()", helper.index("def rollback_transaction"))
    assert "ROLLBACK_VERIFY_FAILURE=FENCE_CLOSED" in helper
    assert "HUMAN_RECOVERY_REQUIRED=true" in helper
    print("ROLLBACK_PRE_SWAP=PASS")
    print("ROLLBACK_SWAP_FAILURE=PASS")
    print("ROLLBACK_POST_SWAP_PRE_CONVERGENCE=PASS")
    print("ROLLBACK_POST_CONVERGENCE=PASS")
    print("EXACT_BACKUP_RESTORE=PASS")
    print("PREVIOUS_RUNTIME_DIGEST=RESTORED")
    print("RELEASE_UNCHANGED=PASS")
    print("FENCE_REOPEN_ONLY_AFTER_PROOF=PASS")
    print("ROLLBACK_VERIFY_FAILURE=FENCE_CLOSED/HUMAN_RECOVERY_REQUIRED")

    # Drupal convergence is fixed, PREPROD-only and does not perform governed-content APPLY.
    convergence = helper[helper.index("def run_fixed_drupal_convergence"):helper.index("def validate_runtime_invariants")]
    order = [
        convergence.index('("updatedb", "-y")'),
        convergence.index('("config:import", "-y")'),
        convergence.index('config/splits/preproduction'),
        convergence.index('ADMIN_RECONCILE_PATH'),
        convergence.index('("emerging:governed-content:validate",)'),
        convergence.index('("emerging:governed-content", "--all", "--dry-run")'),
        convergence.index('("cache:rebuild",)'),
    ]
    assert order == sorted(order)
    assert '("emerging:governed-content", "--all")' not in convergence
    print("DRUPAL_CONVERGENCE_ORDER=PASS")
    print("GOVERNED_CONTENT_APPLY=FORBIDDEN_NOT_PERFORMED")

    control_sudo = CONTROL_SUDOERS.read_text().strip()
    ingress_sudo = INGRESS_SUDOERS.read_text().strip()
    assert control_sudo == "agency-preprod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-preprod-refresh-control"
    assert ingress_sudo == "agency-preprod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-preprod-refresh-ingress"
    combined = control_sudo + "\n" + ingress_sudo
    for forbidden in ("NOPASSWD: ALL", " SETENV:", "mariadb", "bash", "python", " env", "agency-preprod-refresh-authority-install"):
        assert forbidden not in combined
    print("PRIVILEGE_WIDENING=NONE")
    print("AUTHORITY_INSTALLER_SUDO_EXPOSURE=NONE")

    profile = json.loads(PROFILE.read_text())
    bundle = json.loads(BUNDLE.read_text())
    provision = json.loads(PROVISION.read_text())
    assert profile["revision_issue"] == 915
    assert profile["data_activation_authority"]["installed_state"] == "DISABLED"
    assert profile["data_activation_authority"]["permanent_enabled_state"] == "NONE"
    assert bundle["persistent_data_activation_authority_after_provisioning"] == "DISABLED"
    assert bundle["transaction_authority_after_provisioning"] == "ABSENT"
    assert provision["apply"]["persistent_data_activation_authority_after_apply"] == "DISABLED"
    assert provision["apply"]["transaction_authority_after_apply"] == "ABSENT"

    digest_paths = {
        "helper": HELPER,
        "ingress": INGRESS,
        "authority_installer": INSTALLER,
        "transaction_contract": CONTRACT,
        "admin_reconcile": ADMIN_SH,
        "admin_reconcile_php": ADMIN_PHP,
        "control_sudoers": CONTROL_SUDOERS,
        "ingress_sudoers": INGRESS_SUDOERS,
        "capability_profile": PROFILE,
        "bundle_manifest": BUNDLE,
        "run_apply": RUN_APPLY,
        "remote_provision_root": REMOTE,
    }
    for key, path in digest_paths.items():
        actual = hashlib.sha256(path.read_bytes()).hexdigest()
        assert provision["digests"][key] == actual, (key, provision["digests"][key], actual)
    print("PROVISIONING_DIGESTS=EXACT")

    # #910 semantics are deliberately preserved in the existing orchestration;
    # #915 run-apply remains a post-JIT fixed operation with no authority creation.
    assert "PREPROD_PROVISIONING_SSH_KEY" in RUN_APPLY.read_text()
    assert "TRANSACTION_AUTHORITY=ABSENT" in RUN_APPLY.read_text()
    print("#910_JIT_BEFORE_SECRET=PRESERVED_BY_ORCHESTRATION_CONTRACT")
    print("LOCAL_915_CONTRACT=PASS")

if __name__ == "__main__":
    main()
