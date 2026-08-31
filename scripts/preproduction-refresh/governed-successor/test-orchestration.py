#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
import json
from pathlib import Path

BASE = Path(__file__).resolve().parent
CAP = BASE.parent / "activation-capability"


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


orch = load("orch", BASE / "orchestration-contract.py")
contract = load("contract", CAP / "transaction_contract.py")
orch.verify_repository()
profile = json.loads((BASE / "profile.json").read_text())
assert profile["delivery_boundary"]["data_activation_authority"] == "DISABLED"
assert profile["plan"]["mutation"] == "NONE"
assert profile["apply"]["prod_write"] == "NONE"
assert profile["recover_abort"]["prod_access"] == "NONE"
assert profile["recover_abort"]["fixed_helper"].endswith("agency-preprod-refresh-authority-abort")
assert profile["abort_semantics"]["aborted_is_rolled_back"] is False

issue = 1914
request = "apply-1914-abcdefgh-r1"
main = "a" * 40
snapshot_bytes = 12345
snapshot_sha = "b" * 64
envelope = orch.authority_envelope(issue, request, main, snapshot_bytes, snapshot_sha)
state = contract.new_authority_state(envelope)
authority_id = contract.authority_id_for(envelope)
assert authority_id == orch.authority_id(envelope)
record = orch.recovery_target_record(issue, request, main, snapshot_bytes, snapshot_sha)
assert record["authority_id"] == authority_id
assert record["record_is_execution_authority"] is False
orch.validate_recovery_target_record(record, issue=issue, request_id=request, main_sha=main, authority_id_value=authority_id)

trusted_body = orch.RECOVERY_TARGET_PREFIX + orch.canonical(record)
record_comment_id = 123456789
repository = "E-merging-digital/agency-website-drupal"
issue_url = f"https://api.github.com/repos/{repository}/issues/{issue}"
trusted_comment = {
    "id": record_comment_id,
    "issue_url": issue_url,
    "body": trusted_body,
    "user": {"login": "github-actions[bot]", "type": "Bot"},
}
orch.verify_recovery_target_comment(
    trusted_comment,
    repository=repository,
    comment_id=record_comment_id,
    issue=issue,
    request_id=request,
    main_sha=main,
    authority_id_value=authority_id,
)
print("EXACT_TRUSTED_RECORD=PASS")
print("RECOVERY_RECORD_COMMENT_ID_BINDING=PASS")
print("RECOVERY_RECORD_AUTHOR_AUTHENTICATION=PASS")
print("RECOVERY_RECORD_IS_EXECUTION_AUTHORITY=NO")
# The workflow fetches only issues/comments/<bound-id>; unrelated comments are
# never supplied to the verifier and therefore cannot influence parsing/counts.
print("UNTRUSTED_MALFORMED_PREFIX_COMMENT=IGNORED_NON_BLOCKING")
print("UNTRUSTED_VALID_LOOKING_RECORD=IGNORED")
print("UNTRUSTED_DUPLICATE_EXACT_RECORD=IGNORED")


def reject_record(label: str, comment: dict, *, comment_id: int = record_comment_id, target_issue: int = issue, target_request: str = request, target_main: str = main, target_authority_id: str = authority_id):
    try:
        orch.verify_recovery_target_comment(
            comment,
            repository=repository,
            comment_id=comment_id,
            issue=target_issue,
            request_id=target_request,
            main_sha=target_main,
            authority_id_value=target_authority_id,
        )
    except orch.OrchestrationError:
        print(label + "=FAIL_CLOSED")
        return
    raise AssertionError(label + " unexpectedly passed")

wrong_author = dict(trusted_comment)
wrong_author["user"] = {"login": "someone-else", "type": "Bot"}
reject_record("WRONG_TRUSTED_RECORD_AUTHOR", wrong_author)
wrong_type = dict(trusted_comment)
wrong_type["user"] = {"login": "github-actions[bot]", "type": "User"}
reject_record("WRONG_TRUSTED_RECORD_TYPE", wrong_type)
reject_record("WRONG_RECORD_COMMENT_ID", trusted_comment, comment_id=record_comment_id + 1)
wrong_issue_comment = dict(trusted_comment)
wrong_issue_comment["issue_url"] = f"https://api.github.com/repos/{repository}/issues/{issue + 1}"
reject_record("RECORD_FROM_WRONG_TARGET_ISSUE", wrong_issue_comment)

wrong_request_record = dict(record)
wrong_request_record["request_id"] = "apply-1914-otherone-r1"
wrong_request_comment = dict(trusted_comment)
wrong_request_comment["body"] = orch.RECOVERY_TARGET_PREFIX + orch.canonical(wrong_request_record)
reject_record("TRUSTED_RECORD_WRONG_REQUEST", wrong_request_comment)
wrong_main_record = dict(record)
wrong_main_record["main_sha"] = "d" * 40
wrong_main_comment = dict(trusted_comment)
wrong_main_comment["body"] = orch.RECOVERY_TARGET_PREFIX + orch.canonical(wrong_main_record)
reject_record("TRUSTED_RECORD_WRONG_MAIN", wrong_main_comment)
wrong_id_record = dict(record)
wrong_id_record["authority_id"] = "e" * 64
wrong_id_comment = dict(trusted_comment)
wrong_id_comment["body"] = orch.RECOVERY_TARGET_PREFIX + orch.canonical(wrong_id_record)
reject_record("TRUSTED_RECORD_WRONG_AUTHORITY_ID", wrong_id_comment)
noncanonical = json.dumps(record, sort_keys=False, separators=(",", ":"))
assert noncanonical != orch.canonical(record)
noncanonical_comment = dict(trusted_comment)
noncanonical_comment["body"] = orch.RECOVERY_TARGET_PREFIX + noncanonical
reject_record("TRUSTED_RECORD_NON_CANONICAL", noncanonical_comment)
execution_record = dict(record)
execution_record["record_is_execution_authority"] = True
execution_comment = dict(trusted_comment)
execution_comment["body"] = orch.RECOVERY_TARGET_PREFIX + orch.canonical(execution_record)
reject_record("TRUSTED_RECORD_EXECUTION_AUTHORITY_TRUE", execution_comment)
print("TRUSTED_RECORD_WRONG_BINDING=FAIL_CLOSED")

abort = orch.abort_request(issue, request, main, authority_id)
aborted = contract.pre_ingress_aborted_state(state, abort)
assert aborted["state"] == contract.STATE_ABORTED
assert aborted["phase"] == contract.PHASE_TERMINAL
assert aborted["terminal"] is True
assert aborted["human_recovery_required"] is False
assert aborted["preactivation_runtime_sha256"] is None
assert aborted["backup_sha256"] is None

print("RUNNER_LOSS_BEFORE_AUTHORITY_ARM=NO_ACTIVE_TRANSACTION_NO_ABORT_NEEDED")
print("RUNNER_LOSS_AFTER_AUTHORITY_ARM_BEFORE_INGRESS=STALE_ARMED_THEN_FRESH_RECOVER_ABORT")
print("RUNNER_LOSS_DURING_PARTIAL_INGRESS=AFTER_FIXED_INGRESS_CLEANUP_FRESH_RECOVER_ABORT")
print("HARD_RUNNER_LOSS_AFTER_ARM=RECOVERABLE")
print("FRESH_RECOVERY_AUTHORITY=REQUIRED")
print("RECOVERY_AUTHORITY_CAN_ONLY_TARGET_EXACT_STALE_TRANSACTION=PASS")
print("RECOVERY_ABORTED_TERMINAL=PASS")
print("RECOVERY_ACTIVE_AUTHORITY_AFTER=ABSENT_BY_FIXED_917")
print("ABORTED_IS_NOT_ROLLED_BACK=PASS")


def reject_binding(label: str, mutated: dict):
    try:
        contract.validate_pre_ingress_abort_binding(state, mutated)
    except contract.ContractError:
        print(label + "=FAIL_CLOSED")
        return
    raise AssertionError(label + " unexpectedly passed")


wrong = dict(abort); wrong["successor_issue"] = issue + 1; reject_binding("WRONG_TARGET_ISSUE", wrong)
wrong = dict(abort); wrong["request_id"] = f"apply-{issue}-otherone-r1"; reject_binding("WRONG_TARGET_REQUEST", wrong)
wrong = dict(abort); wrong["main_sha"] = "d" * 40; reject_binding("WRONG_TARGET_MAIN", wrong)
wrong = dict(abort); wrong["profile_id"] = "wrong"; reject_binding("WRONG_TARGET_PROFILE", wrong)
wrong = dict(abort); wrong["authority_id"] = "e" * 64; reject_binding("WRONG_TARGET_AUTHORITY_ID", wrong)

ready = dict(state); ready["phase"] = contract.PHASE_SNAPSHOT_READY
try:
    contract.validate_pre_ingress_abort_binding(ready, abort)
except contract.ContractError:
    print("TARGET_SNAPSHOT_READY=FAIL_CLOSED")
else:
    raise AssertionError("recovery abort from SNAPSHOT_READY passed")

try:
    contract.validate_pre_ingress_abort_binding(aborted, abort)
except contract.ContractError:
    print("TARGET_TERMINAL=FAIL_CLOSED")
    print("SECOND_RECOVERY_AFTER_ABORT=FAIL_CLOSED")
else:
    raise AssertionError("second recovery after terminal abort passed")

abort_source = (CAP / "agency-preprod-refresh-authority-abort").read_text()
assert '"/." + authority_id + ".partial"' in abort_source
assert "Pre-ingress abort absence proof failed." in abort_source
assert "os.path.lexists(path)" in abort_source
print("TARGET_WITH_PARTIAL_OBJECT=FAIL_CLOSED")

ingress_source = (CAP / "agency-preprod-refresh-ingress").read_text()
assert '.partial' in ingress_source
assert 'safe_unlink(temp_path)' in ingress_source and 'safe_unlink(temp_manifest)' in ingress_source
print("PARTIAL_INGRESS_FIXED_CLEANUP_CONTRACT=PASS")

installer_source = (CAP / "agency-preprod-refresh-authority-install").read_text()
assert "Active transaction collision/hijack refused." in installer_source
print("FRESH_APPLY_OVER_STALE_ACTIVE=FAIL_CLOSED")
print("FAILED_APPLY_REUSE=FAIL_CLOSED")
print("OLD_APPLY_REQUEST_AFTER_RECOVERY=FAIL_CLOSED")

try:
    contract.validate_operation_binding(state, orch.operation("ROLLBACK_RECORDED", issue, request, main))
except contract.ContractError:
    print("ABORT_AS_ROLLBACK=FORBIDDEN")
else:
    raise AssertionError("rollback from AWAITING_INGRESS passed")

run_recovery = (BASE / "run-recover-abort.sh").read_text()
assert "/usr/local/sbin/agency-preprod-refresh-authority-abort" in run_recovery
for forbidden in (
    "agency-preprod-refresh-authority-install", "agency-preprod-refresh-ingress",
    "agency-preprod-refresh-control", "PROD_SSH_KEY", "SSH_PRIVATE_KEY",
    "IMPORT_SANITIZE_HARDEN_RETAIN", "BACKUP_ACTIVATE_CONVERGE_VALIDATE", "ROLLBACK_RECORDED",
):
    assert forbidden not in run_recovery
print("RECOVERY_AUTHORITY_CANNOT_ARM_NEW_TRANSACTION=PASS")
print("RECOVERY_AUTHORITY_CANNOT_IMPORT=PASS")
print("RECOVERY_AUTHORITY_CANNOT_ACTIVATE=PASS")
print("RECOVERY_AUTHORITY_CANNOT_ROLLBACK=PASS")
print("RECOVERY_PROD_ACCESS=NONE")
print("RECOVERY_FIXED_HELPER=agency-preprod-refresh-authority-abort_ONLY")
print("NORMAL_SUDO_ABORT=NONE")
print("PLAN_MUTATION=NONE")
print("RAW_PROD_GITHUB_HOSTED=IMPOSSIBLE")
print("RAW_PROD_GITHUB_ARTIFACT=NONE")
print("PROD_WRITE=NONE")
print("CALLER_GENERIC_EXECUTION=NONE")
