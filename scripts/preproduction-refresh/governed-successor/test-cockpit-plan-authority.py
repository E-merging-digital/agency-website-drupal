#!/usr/bin/env python3
"""Synthetic fail-closed matrix for Cockpit issue-open PLAN authority."""
from __future__ import annotations

import copy
import importlib.util
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "cockpit_authority",
    HERE / "validate-cockpit-plan-authority.py",
)
assert SPEC and SPEC.loader
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)

MAIN = "1" * 40
ISSUE = 1090
STAMP = "2026-09-07T18:00:00Z"


def valid_issue():
    return {
        "number": ISSUE,
        "state": "open",
        "pull_request": None,
        "user": {"login": "E-merging-digital"},
        "author_association": "OWNER",
        "performed_via_github_app": None,
        "labels": [
            {"name": "Task"},
            {"name": "P1"},
            {"name": "status:in-progress"},
        ],
        "title": M.EXPECTED_TITLE,
        "body": M.EXPECTED_BODY,
        "created_at": STAMP,
        "updated_at": STAMP,
    }


def run(issue=None, **kwargs):
    return M.validate(
        issue=issue or valid_issue(),
        authority_issue=ISSUE,
        github_actor=kwargs.pop("github_actor", "E-merging-digital"),
        event_name=kwargs.pop("event_name", "issues"),
        event_action=kwargs.pop("event_action", "opened"),
        run_attempt=kwargs.pop("run_attempt", "1"),
        live_main=kwargs.pop("live_main", MAIN),
        **kwargs,
    )


def fails(fn):
    try:
        fn()
    except M.AuthorityError:
        return
    raise AssertionError("Expected fail-closed rejection")


out = run()
assert out["mode"] == "PLAN"
assert out["request_id"] == f"plan-{ISSUE}-cockpit-v1-r1"
assert out["main_sha"] == MAIN
assert out["prod_release_sha"] == "AUTO"
assert out["operation_profile"] == "agency-preprod-refresh-simple-v1"
print("COCKPIT_ISSUE_OPEN_PLAN=PASS")

fails(lambda: run(github_actor="someone-else"))
print("WRONG_ACTOR=FAIL_CLOSED")

app_issue = valid_issue()
app_issue["performed_via_github_app"] = {"slug": "chatgpt-codex-connector"}
fails(lambda: run(app_issue))
print("APP_MEDIATED_ISSUE=FAIL_CLOSED")

wrong_parent = valid_issue()
wrong_parent["body"] = wrong_parent["body"].replace("Parent: #816", "Parent: #870")
fails(lambda: run(wrong_parent))
print("WRONG_PARENT=FAIL_CLOSED")

missing_label = valid_issue()
missing_label["labels"] = [
    {"name": "Task"},
    {"name": "P1"},
]
fails(lambda: run(missing_label))
print("MISSING_IN_PROGRESS=FAIL_CLOSED")

edited = valid_issue()
edited["updated_at"] = "2026-09-07T18:00:01Z"
fails(lambda: run(edited))
print("EDITED_AUTHORITY=FAIL_CLOSED")

fails(lambda: run(run_attempt="2"))
print("RUN_ATTEMPT_2=FAIL_CLOSED")

fails(lambda: run(event_name="issue_comment", event_action="created"))
print("LEGACY_EVENT_NOT_COCKPIT_AUTHORITY=FAIL_CLOSED")

apply_issue = valid_issue()
apply_issue["body"] = apply_issue["body"].replace(
    "\"mode\":\"PLAN\"",
    "\"mode\":\"APPLY\"",
)
fails(lambda: run(apply_issue))
print("ISSUE_OPEN_APPLY=IMPOSSIBLE")

profile_issue = valid_issue()
profile_issue["body"] = profile_issue["body"].replace(
    "agency-preprod-refresh-simple-v1",
    "caller-profile",
)
fails(lambda: run(profile_issue))
print("CALLER_CONTROLLED_PROFILE=NONE")

for forbidden in ("host=", "ref=", "command=", "shell=", "SQL", "Drush"):
    changed = valid_issue()
    changed["body"] += f"{forbidden}caller\n"
    fails(lambda changed=changed: run(changed))
print("CALLER_CONTROLLED_EXECUTION_FIELDS=NONE")

expected_request = f"plan-{ISSUE}-cockpit-v1-r1"
out = run(
    checked_out_head=MAIN,
    expected_request_id=expected_request,
    expected_main=MAIN,
    expected_profile="agency-preprod-refresh-simple-v1",
)
assert out["request_id"] == expected_request
print("JIT_REVALIDATION=PASS")

fails(lambda: run(checked_out_head="2" * 40))
fails(lambda: run(expected_main="2" * 40))
print("JIT_MAIN_DRIFT=FAIL_CLOSED")
print("COCKPIT_GITHUB_WRITE=NONE")
print("REAL_PLAN=NONE")
