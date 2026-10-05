#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path
from types import SimpleNamespace

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("auth912", HERE / "validate-remove-successor-authority.py")
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

main = "b" * 40
issue_number = 913
body = "AGENCY_PREPROD_TEMP_KEY_REMOVE_AUTHORITY\nparent_issue=816\nimplementation_issue=912\nallowed_action=remove"
command = f"/agency-preprod-temp-key-remove issue-{issue_number}-remove-{main[:8]}-r1 {main} agency-preprod-temp-key-remove-v1"


def expect_reject(args, label: str) -> None:
    try:
        mod.validate(args)
    except mod.Reject:
        print(f"{label}=FAIL_CLOSED")
    else:
        raise AssertionError(f"{label} accepted")


with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    issue = root / "issue.json"
    comments = root / "comments.json"

    def write_issue(*, state: str = "open", marker_body: str = body, number: int = issue_number) -> None:
        issue.write_text(json.dumps({
            "number": number,
            "state": state,
            "user": {"login": "E-merging-digital"},
            "body": marker_body,
        }))

    def write_comments(items) -> None:
        comments.write_text(json.dumps(items))

    write_issue()
    write_comments([{"body": command, "user": {"login": "E-merging-digital"}}])
    args = SimpleNamespace(
        issue_json=str(issue),
        comments_json=str(comments),
        authority_issue_number=issue_number,
        event_name="issue_comment",
        event_action="created",
        run_attempt="1",
        github_actor="E-merging-digital",
        comment_author="E-merging-digital",
        comment_body=command,
        live_main=main,
    )
    output = mod.validate(args)
    assert output == {
        "request_id": f"issue-{issue_number}-remove-{main[:8]}-r1",
        "main_sha": main,
        "operation_profile": "agency-preprod-temp-key-remove-v1",
        "authority_issue": str(issue_number),
        "implementation_issue": "912",
    }
    print("UNCHANGED_MAIN_VALID_AUTHORITY=PASS")

    bad = SimpleNamespace(**vars(args))
    bad.live_main = "c" * 40
    expect_reject(bad, "JIT_MAIN_ADVANCE")

    write_issue(state="closed")
    expect_reject(args, "JIT_AUTHORITY_REVOKED")
    write_issue()

    write_issue(marker_body=body.replace("allowed_action=remove", "allowed_action=add"))
    expect_reject(args, "AUTHORITY_MARKER_CHANGED")
    write_issue()

    write_comments([])
    expect_reject(args, "AUTHORIZATION_COMMENT_MISSING")
    write_comments([
        {"body": command, "user": {"login": "E-merging-digital"}},
        {"body": command, "user": {"login": "E-merging-digital"}},
    ])
    expect_reject(args, "AUTHORIZATION_COMMENT_DUPLICATED")
    changed_command = command.replace("-r1", "-r2")
    write_comments([{"body": changed_command, "user": {"login": "E-merging-digital"}}])
    expect_reject(args, "AUTHORIZATION_COMMENT_CHANGED")
    write_comments([{"body": command, "user": {"login": "E-merging-digital"}}])

    bad = SimpleNamespace(**vars(args))
    bad.comment_body = command.replace(f"issue-{issue_number}-remove-", f"issue-{issue_number + 1}-remove-")
    expect_reject(bad, "REQUEST_MISMATCH")

    bad = SimpleNamespace(**vars(args))
    bad.comment_body = command.replace("agency-preprod-temp-key-remove-v1", "agency-preprod-temp-key-add-v1")
    expect_reject(bad, "PROFILE_MISMATCH")

    bad = SimpleNamespace(**vars(args))
    bad.comment_body = command.replace("/agency-preprod-temp-key-remove", "/agency-preprod-temp-key-add")
    expect_reject(bad, "ACTION_MISMATCH")

    bad = SimpleNamespace(**vars(args))
    bad.run_attempt = "2"
    expect_reject(bad, "RUN_ATTEMPT_NOT_1")

    write_issue(number=912)
    bad = SimpleNamespace(**vars(args))
    bad.authority_issue_number = 912
    expect_reject(bad, "#912_SELF_EXECUTION")
    write_issue()

    write_comments([
        {"body": command, "user": {"login": "E-merging-digital"}},
        {"body": "consumed " + output["request_id"], "user": {"login": "E-merging-digital"}},
    ])
    expect_reject(args, "REQUEST_ID_DUPLICATE_MENTION")

print("SUCCESSOR_REMOVE_AUTHORITY_TEST=PASS")
print("REQUEST_ID_ONE_SHOT_TEST=PASS")
