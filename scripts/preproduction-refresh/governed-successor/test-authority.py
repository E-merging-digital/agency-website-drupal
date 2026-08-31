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
ISSUE = 917
PROFILE = authority.PROFILE_ID


def marker(mode="PLAN", issue=ISSUE, request=None, main=MAIN, profile=PROFILE, actor="E-merging-digital", prod=None):
    if request is None:
        request = ("plan" if mode == "PLAN" else "apply") + f"-{issue}-abcdefgh-r1"
    if prod is None:
        prod = "AUTO" if mode == "PLAN" else PROD
    value = {
        "schema_version": 1,
        "parent_issue": 816,
        "implementation_issue": 914,
        "authority_issue": issue,
        "mode": mode,
        "request_id": request,
        "main_sha": main,
        "prod_release_sha": prod,
        "profile_id": profile,
        "authorized_actor": actor,
        "run_attempt": 1,
    }
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"))
    return value, "Parent: #816\n" + authority.MARKER_PREFIX + raw + "\n"


def valid_fixture(mode="PLAN", issue=ISSUE):
    m, body = marker(mode=mode, issue=issue)
    comment = f"{authority.TRIGGER} {m['mode']} {m['request_id']} {m['main_sha']} {m['prod_release_sha']} {m['profile_id']}"
    issue_json = {
        "number": issue,
        "state": "open",
        "user": {"login": "E-merging-digital"},
        "labels": [{"name": "status:in-progress"}],
        "body": body,
    }
    comments = [{"body": comment, "user": {"login": "E-merging-digital"}}]
    return m, issue_json, comments, comment


def call(mode="PLAN", **overrides):
    _, issue, comments, comment = valid_fixture(mode)
    args = dict(
        issue=issue,
        comments=comments,
        authority_issue_number=issue["number"],
        comment_body=comment,
        comment_author="E-merging-digital",
        github_actor="E-merging-digital",
        event_name="issue_comment",
        event_action="created",
        run_attempt="1",
        live_main=MAIN,
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


assert call("PLAN")["mode"] == "PLAN"
assert call("APPLY")["mode"] == "APPLY"
print("VALID_PLAN=PASS")
print("VALID_APPLY=PASS")

_, issue914, comments914, comment914 = valid_fixture("PLAN", 914)
reject("#914_CANNOT_BE_EXECUTION_AUTHORITY", lambda: authority.validate(
    issue=issue914, comments=comments914, authority_issue_number=914,
    comment_body=comment914, comment_author="E-merging-digital", github_actor="E-merging-digital",
    event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN
))

_, issue, comments, comment = valid_fixture("PLAN")
apply_comment = comment.replace(" PLAN ", " APPLY ", 1)
reject("PLAN_REQUEST_CANNOT_AUTHORIZE_APPLY", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE,
    comment_body=apply_comment, comment_author="E-merging-digital", github_actor="E-merging-digital",
    event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN
))

reject("RUN_ATTEMPT_NOT_1", lambda: call(run_attempt="2"))
reject("WRONG_MAIN", lambda: call(live_main="c" * 40))
reject("WRONG_ACTOR", lambda: call(github_actor="someone-else"))
reject("CHECKED_OUT_HEAD_MISMATCH", lambda: call(checked_out_head="d" * 40))

_, issue, comments, comment = valid_fixture("PLAN")
comments.append(dict(comments[0]))
reject("REQUEST_REPLAY", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE,
    comment_body=comment, comment_author="E-merging-digital", github_actor="E-merging-digital",
    event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN
))

_, issue, comments, comment = valid_fixture("PLAN")
issue["state"] = "closed"
reject("AUTHORITY_REVOKED", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE,
    comment_body=comment, comment_author="E-merging-digital", github_actor="E-merging-digital",
    event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN
))

m, issue, comments, comment = valid_fixture("PLAN")
bad_marker = dict(m)
bad_marker["profile_id"] = "wrong"
issue["body"] = "Parent: #816\n" + authority.MARKER_PREFIX + json.dumps(bad_marker, sort_keys=True, separators=(",", ":")) + "\n"
reject("WRONG_PROFILE", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE,
    comment_body=comment, comment_author="E-merging-digital", github_actor="E-merging-digital",
    event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN
))

_, issue, comments, comment = valid_fixture("PLAN")
reject("JIT_EXPECTED_MODE_MISMATCH", lambda: authority.validate(
    issue=issue, comments=comments, authority_issue_number=ISSUE,
    comment_body=comment, comment_author="E-merging-digital", github_actor="E-merging-digital",
    event_name="issue_comment", event_action="created", run_attempt="1", live_main=MAIN,
    checked_out_head=MAIN, expected_mode="APPLY"
))

print("PLAN_APPLY_SEPARATION=PASS")
print("SECRET_BEFORE_JIT=IMPOSSIBLE_BY_WORKFLOW_CONTRACT")
