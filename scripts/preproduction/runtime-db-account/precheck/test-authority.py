#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path
from types import SimpleNamespace

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "authority_895",
    HERE / "validate-successor-authority.py",
)
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

LIVE_MAIN = "e3acdb8eb40c5236722708548fc0d53258f73c47"
AUTHORITY_ISSUE = 990
REQUEST_ID = f"issue-{AUTHORITY_ISSUE}-precheck-{LIVE_MAIN[:8]}-r1"
COMMENT = (
    f"{mod.COMMAND} {REQUEST_ID} {LIVE_MAIN} {mod.PROFILE}"
)
MARKER = (
    "AGENCY_PREPROD_RUNTIME_DB_PRECHECK_AUTHORITY\n"
    "parent_issue=816\n"
    "implementation_issue=895\n"
    "allowed_action=precheck"
)


def invoke(
    *,
    issue_number: int = AUTHORITY_ISSUE,
    issue_state: str = "open",
    issue_creator: str = mod.OWNER,
    issue_body: str = MARKER,
    comment_body: str = COMMENT,
    actor: str = mod.OWNER,
    comment_author: str = mod.OWNER,
    run_attempt: str = "1",
    live_main: str = LIVE_MAIN,
    comments: list[dict] | None = None,
):
    if comments is None:
        comments = [{"body": comment_body, "user": {"login": comment_author}}]
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        issue_path = root / "issue.json"
        comments_path = root / "comments.json"
        issue_path.write_text(
            json.dumps(
                {
                    "number": issue_number,
                    "state": issue_state,
                    "user": {"login": issue_creator},
                    "body": issue_body,
                }
            ),
            encoding="utf-8",
        )
        comments_path.write_text(json.dumps(comments), encoding="utf-8")
        args = SimpleNamespace(
            issue_json=str(issue_path),
            comments_json=str(comments_path),
            authority_issue_number=issue_number,
            event_name="issue_comment",
            event_action="created",
            github_actor=actor,
            comment_author=comment_author,
            comment_body=comment_body,
            run_attempt=run_attempt,
            live_main=live_main,
        )
        return mod.validate(args)


def expect_reject(**kwargs) -> None:
    try:
        invoke(**kwargs)
    except mod.Reject:
        return
    raise AssertionError(f"expected authority rejection for {kwargs}")


valid = invoke()
assert valid["request_id"] == REQUEST_ID
assert valid["main_sha"] == LIVE_MAIN
assert valid["operation_profile"] == mod.PROFILE
assert valid["authority_issue"] == str(AUTHORITY_ISSUE)
assert valid["implementation_issue"] == "895"

expect_reject(issue_number=895)
expect_reject(actor="someone-else")
expect_reject(comment_author="someone-else")
expect_reject(run_attempt="2")
expect_reject(issue_state="closed")
expect_reject(issue_creator="someone-else")
expect_reject(live_main="0" * 40)
expect_reject(
    issue_body=MARKER + "\n" + MARKER,
)
expect_reject(
    issue_body=MARKER.replace("implementation_issue=895", "implementation_issue=874"),
)
expect_reject(
    comment_body=(
        f"{mod.COMMAND} APPLY {REQUEST_ID} {LIVE_MAIN} {mod.PROFILE}"
    )
)
expect_reject(
    comments=[
        {"body": COMMENT, "user": {"login": mod.OWNER}},
        {"body": COMMENT, "user": {"login": mod.OWNER}},
    ]
)
expect_reject(
    comments=[
        {"body": COMMENT, "user": {"login": mod.OWNER}},
        {"body": f"consumed {REQUEST_ID}", "user": {"login": mod.OWNER}},
    ]
)

print("SUCCESSOR_AUTHORITY=PASS")
print("#895_SELF_EXECUTION=FORBIDDEN")
print("OWNER_AUTHORITY=PASS")
print("EXACT_MAIN_BINDING=PASS")
print("REQUEST_ID_SINGLE_USE=PASS")
print("RERUN_REPLAY=FAIL_CLOSED")
print("CALLER_CONTROLLED_ACTION=NO")
