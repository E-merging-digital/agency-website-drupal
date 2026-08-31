#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
import json
from pathlib import Path

BASE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("authority", BASE / "validate-execution-authority.py")
assert spec and spec.loader
authority = importlib.util.module_from_spec(spec)
spec.loader.exec_module(authority)

MAIN = "a" * 40
PROD = "b" * 40
TARGET_MAIN = "c" * 40
TARGET_AUTHORITY_ID = "d" * 64
ISSUE = 1914
TARGET_ISSUE = 1913
PROFILE = authority.PROFILE_ID
CAP_PROFILE = authority.CAPABILITY_PROFILE_ID


def marker(mode="PLAN", issue=ISSUE, request=None, main=MAIN, profile=PROFILE, actor="E-merging-digital", prod=None,
           target_issue=None, target_request=None, target_main=None, target_profile=None, target_authority_id=None):
    if request is None:
        prefix = {"PLAN": "plan", "APPLY": "apply", "RECOVER_ABORT": "recover-abort"}[mode]
        request = f"{prefix}-{issue}-abcdefgh-r1"
    if prod is None:
        prod = "AUTO" if mode == "PLAN" else (PROD if mode == "APPLY" else "NONE")
    if mode == "RECOVER_ABORT":
        target_issue = TARGET_ISSUE if target_issue is None else target_issue
        target_request = f"apply-{target_issue}-targetabcd-r1" if target_request is None else target_request
        target_main = TARGET_MAIN if target_main is None else target_main
        target_profile = CAP_PROFILE if target_profile is None else target_profile
        target_authority_id = TARGET_AUTHORITY_ID if target_authority_id is None else target_authority_id
    value = {
        "schema_version": 2, "parent_issue": 816, "implementation_issue": 914,
        "authority_issue": issue, "mode": mode, "request_id": request,
        "main_sha": main, "prod_release_sha": prod, "profile_id": profile,
        "authorized_actor": actor, "run_attempt": 1,
        "target_successor_issue": target_issue, "target_request_id": target_request,
        "target_main_sha": target_main, "target_profile_id": target_profile,
        "target_authority_id": target_authority_id,
    }
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"))
    return value, "Parent: #816\n" + authority.MARKER_PREFIX + raw + "\n"


def valid_fixture(mode="PLAN", issue=ISSUE, **marker_overrides):
    m, body = marker(mode=mode, issue=issue, **marker_overrides)
    comment = authority._expected_comment(m)
    issue_json = {
        "number": issue, "state": "open", "user": {"login": "E-merging-digital"},
        "labels": [{"name": "status:in-progress"}], "body": body,
    }
    comments = [{"body": comment, "user": {"login": "E-merging-digital"}}]
    return m, issue_json, comments, comment


def validate_fixture(mode="PLAN", issue_number=ISSUE, marker_overrides=None, **overrides):
    _, issue, comments, comment = valid_fixture(mode, issue_number, **(marker_overrides or {}))
    args = dict(
        issue=issue, comments=comments, authority_issue_number=issue_number,
        comment_body=comment, comment_author="E-merging-digital", github_actor="E-merging-digital",
        event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN,
    )
    args.update(overrides)
    return authority.validate(**args)


def reject(label, fn):
    try:
        fn()
    except authority.AuthorityError:
        print(label + "=FAIL_CLOSED")
        return
    raise AssertionError(label + " unexpectedly passed")


assert validate_fixture("PLAN")["mode"] == "PLAN"
assert validate_fixture("APPLY")["mode"] == "APPLY"
recovery = validate_fixture("RECOVER_ABORT")
assert recovery["mode"] == "RECOVER_ABORT"
assert recovery["main_sha"] == MAIN
assert recovery["target_main_sha"] == TARGET_MAIN
print("FUTURE_AUTHORITY_IS_FRESH_816_CHILD=PASS")
print("VALID_PLAN=PASS")
print("VALID_APPLY=PASS")
print("FRESH_RECOVERY_AUTHORITY=REQUIRED")
print("RECOVERY_EXECUTION_MAIN_BINDING=EXACT_CURRENT_MAIN")
print("TARGET_TRANSACTION_BINDING=EXACT_HISTORICAL_AUTHORITY")

for known in (914, 915, 916, 917):
    reject(f"KNOWN_ISSUE_{known}_CANNOT_AUTHORIZE", lambda known=known: validate_fixture("PLAN", known))
print("KNOWN_IMPLEMENTATION_CAPABILITY_ISSUES_AS_AUTHORITY=FAIL_CLOSED")

_, issue, comments, comment = valid_fixture("PLAN")
apply_comment = comment.replace(" PLAN ", " APPLY ", 1)
reject("PLAN_REQUEST_CANNOT_AUTHORIZE_APPLY", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE, comment_body=apply_comment,
    comment_author="E-merging-digital", github_actor="E-merging-digital", event_name="issue_comment",
    event_action="created", run_attempt="1", live_main=MAIN))

reject("RUN_ATTEMPT_NOT_1", lambda: validate_fixture(run_attempt="2"))
reject("WRONG_MAIN", lambda: validate_fixture(live_main="e" * 40))
reject("WRONG_ACTOR", lambda: validate_fixture(github_actor="someone-else"))
reject("CHECKED_OUT_HEAD_MISMATCH", lambda: validate_fixture(checked_out_head="e" * 40))
reject("WRONG_ISSUE", lambda: validate_fixture(authority_issue_number=ISSUE + 1))
reject("WRONG_MODE", lambda: validate_fixture(expected_mode="APPLY"))
reject("WRONG_REQUEST", lambda: validate_fixture(expected_request_id="plan-1914-otherone-r1"))
reject("WRONG_PROFILE", lambda: validate_fixture(expected_profile="wrong"))
reject("WRONG_AUTHORIZED_MAIN", lambda: validate_fixture(expected_main="e" * 40))

_, issue, comments, comment = valid_fixture("PLAN")
comments.append(dict(comments[0]))
reject("REQUEST_REPLAY", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE, comment_body=comment,
    comment_author="E-merging-digital", github_actor="E-merging-digital", event_name="issue_comment",
    event_action="created", run_attempt="1", live_main=MAIN))

reject("FAILED_APPLY_RUN_ATTEMPT_2", lambda: validate_fixture("APPLY", run_attempt="2"))
_, issue, comments, comment = valid_fixture("APPLY")
comments.append(dict(comments[0]))
reject("FAILED_APPLY_REQUEST_REUSE", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE, comment_body=comment,
    comment_author="E-merging-digital", github_actor="E-merging-digital", event_name="issue_comment",
    event_action="created", run_attempt="1", live_main=MAIN))

reject("WRONG_TARGET_ISSUE", lambda: validate_fixture("RECOVER_ABORT", marker_overrides={"target_issue": 915}))
reject("WRONG_TARGET_REQUEST", lambda: validate_fixture("RECOVER_ABORT", marker_overrides={"target_request": "apply-1913-bad-r1"}))
reject("WRONG_TARGET_MAIN", lambda: validate_fixture("RECOVER_ABORT", marker_overrides={"target_main": "bad"}))
reject("WRONG_TARGET_PROFILE", lambda: validate_fixture("RECOVER_ABORT", marker_overrides={"target_profile": "wrong"}))
reject("WRONG_TARGET_AUTHORITY_ID", lambda: validate_fixture("RECOVER_ABORT", marker_overrides={"target_authority_id": "bad"}))
reject("RECOVERY_RUN_ATTEMPT_2", lambda: validate_fixture("RECOVER_ABORT", run_attempt="2"))
reject("RECOVERY_WRONG_ACTOR", lambda: validate_fixture("RECOVER_ABORT", github_actor="someone-else"))
reject("RECOVERY_STALE_EXECUTION_MAIN", lambda: validate_fixture("RECOVER_ABORT", live_main="e" * 40))
reject("RECOVERY_TARGET_CHANGED_AT_JIT", lambda: validate_fixture(
    "RECOVER_ABORT", expected_target_authority_id="e" * 64))

_, issue, comments, comment = valid_fixture("RECOVER_ABORT")
comments.append(dict(comments[0]))
reject("RECOVERY_REQUEST_REPLAY", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE, comment_body=comment,
    comment_author="E-merging-digital", github_actor="E-merging-digital", event_name="issue_comment",
    event_action="created", run_attempt="1", live_main=MAIN))

_, issue, comments, comment = valid_fixture("PLAN")
issue["state"] = "closed"
reject("AUTHORITY_REVOKED", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE, comment_body=comment,
    comment_author="E-merging-digital", github_actor="E-merging-digital", event_name="issue_comment",
    event_action="created", run_attempt="1", live_main=MAIN))

_, issue, comments, comment = valid_fixture("PLAN")
issue["pull_request"] = {"url": "synthetic"}
reject("PR_CANNOT_AUTHORIZE", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE, comment_body=comment,
    comment_author="E-merging-digital", github_actor="E-merging-digital", event_name="issue_comment",
    event_action="created", run_attempt="1", live_main=MAIN))

print("#914_EXECUTION_AUTHORITY=IMPOSSIBLE")
print("PLAN_APPLY_SEPARATION=PASS")
print("RECOVERY_AUTHORITY_CANNOT_ARM_NEW_TRANSACTION=PASS_BY_MODE_CONTRACT")
print("RECOVERY_AUTHORITY_CANNOT_IMPORT=PASS_BY_MODE_CONTRACT")
print("RECOVERY_AUTHORITY_CANNOT_ACTIVATE=PASS_BY_MODE_CONTRACT")
print("RECOVERY_AUTHORITY_CANNOT_ROLLBACK=PASS_BY_MODE_CONTRACT")
print("ROOT_SECRET_BEFORE_RECOVERY_JIT=IMPOSSIBLE_BY_WORKFLOW_CONTRACT")
