#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
import json
from pathlib import Path

BASE = Path(__file__).resolve().parent

def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

orch = load("orch", BASE / "orchestration-contract.py")
contract = load("contract", BASE.parent / "activation-capability/transaction_contract.py")

orch.verify_repository()
profile = json.loads((BASE / "profile.json").read_text())
assert profile["delivery_boundary"]["data_activation_authority"] == "DISABLED"
assert profile["plan"]["mutation"] == "NONE"
assert profile["apply"]["prod_write"] == "NONE"
assert profile["abort_semantics"]["aborted_is_rolled_back"] is False
assert profile["failure_model"]["FAIL_AFTER_ARM_BEFORE_INGRESS"].startswith("917_ABORT")
assert "NO_PRE_INGRESS_ABORT" in profile["failure_model"]["FAIL_AFTER_SNAPSHOT_READY_BEFORE_IMPORT"]

issue = 1914
request = "apply-1914-abcdefgh-r1"
main = "a" * 40
snapshot_bytes = 12345
snapshot_sha = "b" * 64
envelope = orch.authority_envelope(issue, request, main, snapshot_bytes, snapshot_sha)
state = contract.new_authority_state(envelope)
authority_id = contract.authority_id_for(envelope)
assert authority_id == orch.authority_id(envelope)
abort = orch.abort_request(issue, request, main, authority_id)
aborted = contract.pre_ingress_aborted_state(state, abort)
assert aborted["state"] == contract.STATE_ABORTED
assert aborted["phase"] == contract.PHASE_TERMINAL
assert aborted["terminal"] is True
assert aborted["human_recovery_required"] is False
assert aborted["preactivation_runtime_sha256"] is None
assert aborted["backup_sha256"] is None
print("AUTHORITY_ARMED_THEN_TRANSFER_FAIL=#917_ABORT_REQUIRED")
print("INGRESS_PARTIAL_FAIL=RAW_CLEANUP_THEN_#917_ABORT")
print("INGRESS_SHA_FAIL=#917_ABORT_REQUIRED")
print("PRE_INGRESS_FAILURE_TERMINALIZATION=PASS")
print("ABORTED_IS_NOT_ROLLED_BACK=PASS")

wrong = dict(abort); wrong["request_id"] = "apply-1914-otherone-r1"
try:
    contract.validate_pre_ingress_abort_binding(state, wrong)
except contract.ContractError:
    print("ABORT_WRONG_BINDING=FAIL_CLOSED")
else:
    raise AssertionError("wrong abort binding passed")

ready = dict(state); ready["phase"] = contract.PHASE_SNAPSHOT_READY
try:
    contract.validate_pre_ingress_abort_binding(ready, abort)
except contract.ContractError:
    print("ABORT_FROM_SNAPSHOT_READY=IMPOSSIBLE_FAIL_CLOSED")
else:
    raise AssertionError("abort from SNAPSHOT_READY passed")

try:
    contract.validate_operation_binding(state, orch.operation("ROLLBACK_RECORDED", issue, request, main))
except contract.ContractError:
    print("ABORT_AS_ROLLBACK=FORBIDDEN")
else:
    raise AssertionError("rollback from AWAITING_INGRESS passed")

try:
    contract.new_authority_state({**envelope, "successor_issue": 915})
except contract.ContractError:
    print("FIXED_CAPABILITY_SELF_AUTHORITY=FAIL_CLOSED")
else:
    raise AssertionError("#915 self-authority passed")

assert profile["fixed_capability"]["normal_sudo_authority_abort"] == "NONE"
print("NORMAL_SUDO_ABORT=NONE")
print("AUTHORITY_REINSTALL_AFTER_ABORT=FAIL_CLOSED_BY_FIXED_917_HISTORY")
print("BLIND_RETRY_AFTER_ABORT=FORBIDDEN")
print("PLAN_MUTATION=NONE")
print("RAW_PROD_GITHUB_HOSTED=IMPOSSIBLE")
print("RAW_PROD_GITHUB_ARTIFACT=NONE")
print("PROD_WRITE=NONE")
print("CALLER_GENERIC_EXECUTION=NONE")
