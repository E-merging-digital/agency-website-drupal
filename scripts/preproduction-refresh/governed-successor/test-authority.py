#!/usr/bin/env python3
"""Synthetic authority matrix for #914 schema v4."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("authority", HERE / "validate-execution-authority.py")
assert SPEC and SPEC.loader
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)

MAIN = "1" * 40
PROD = "2" * 40
ISSUE = 930


def marker(mode: str, **extra):
    value = {
        "schema_version": 4,
        "parent_issue": 816,
        "implementation_issue": 914,
        "authority_issue": ISSUE,
        "mode": mode,
        "request_id": f"{mode.lower()}-{ISSUE}-abcdefgh-r1",
        "main_sha": MAIN,
        "prod_release_sha": "AUTO" if mode == "PLAN" else PROD,
        "profile_id": "agency-preprod-refresh-simple-v1",
        "authorized_actor": "E-merging-digital",
        "run_attempt": 1,
    }
    value.update(extra)
    return value


def issue_for(value):
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"))
    return {
        "number": ISSUE, "state": "open", "pull_request": None,
        "user": {"login": "E-merging-digital"},
        "labels": [{"name": "status:in-progress"}],
        "body": f"Parent: #816\n{M.MARKER_PREFIX}{raw}",
    }


def run(value, *, attempt="1", live=MAIN, comments=None, checked=None):
    body = M.expected_comment(value)
    return M.validate(
        issue=issue_for(value), comments=comments or [{"body": body}], authority_issue=ISSUE,
        comment_body=body, comment_author="E-merging-digital", github_actor="E-merging-digital",
        event_name="issue_comment", event_action="created", run_attempt=attempt,
        live_main=live, checked_out_head=checked,
    )


def fails(fn):
    try:
        fn()
    except M.AuthorityError:
        return
    raise AssertionError("Expected fail-closed rejection")


for mode in ("PLAN", "APPLY"):
    out = run(marker(mode))
    assert out["mode"] == mode
print("AUTHORITY_PLAN_APPLY=PASS")

fails(lambda: run(marker("PLAN"), attempt="2"))
print("RUN_ATTEMPT_2=FAIL_CLOSED")
fails(lambda: run(marker("APPLY"), live="3" * 40))
print("STALE_MAIN=FAIL_CLOSED")
value = marker("APPLY")
body = M.expected_comment(value)
fails(lambda: run(value, comments=[{"body": body}, {"body": body}]))
print("DUPLICATE_REQUEST=FAIL_CLOSED")

historical = marker("APPLY")
historical["target_authority_id"] = "a" * 64
fails(lambda: run(historical))
print("HISTORICAL_TARGET_FIELDS=FAIL_CLOSED")

low = marker("PLAN")
low["authority_issue"] = 920
low["request_id"] = "plan-920-abcdefgh-r1"
raw = json.dumps(low, sort_keys=True, separators=(",", ":"))
issue = issue_for(low)
issue["number"] = 920
issue["body"] = f"Parent: #816\n{M.MARKER_PREFIX}{raw}"
body = M.expected_comment(low)
fails(lambda: M.validate(
    issue=issue, comments=[{"body": body}], authority_issue=920, comment_body=body,
    comment_author="E-merging-digital", github_actor="E-merging-digital",
    event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN,
))
print("IMPLEMENTATION_GOVERNANCE_AUTHORITY=FAIL_CLOSED")
print("GITHUB_TRANSACTIONAL_STATE=NONE")
