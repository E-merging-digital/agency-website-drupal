#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path
from types import SimpleNamespace

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("auth899", HERE / "validate-successor-authority.py")
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

main = "a" * 40
issue_number = 901
body = "AGENCY_PREPROD_TEMP_KEY_ADD_AUTHORITY\nparent_issue=816\nimplementation_issue=899\nallowed_action=add"
command = f"/agency-preprod-temp-key-add issue-{issue_number}-add-{main[:8]}-r1 {main} agency-preprod-temp-key-add-v1"

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    issue = root / "issue.json"
    comments = root / "comments.json"
    issue.write_text(json.dumps({
        "number": issue_number,
        "state": "open",
        "user": {"login": "E-merging-digital"},
        "body": body,
    }))
    comments.write_text(json.dumps([{
        "body": command,
        "user": {"login": "E-merging-digital"},
    }]))
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
    assert output["request_id"].endswith("-r1")

    comments.write_text(json.dumps([
        {"body": command, "user": {"login": "E-merging-digital"}},
        {"body": "duplicate mention " + output["request_id"], "user": {"login": "E-merging-digital"}},
    ]))
    try:
        mod.validate(args)
    except mod.Reject:
        pass
    else:
        raise AssertionError("duplicate request-id mention accepted")

    comments.write_text(json.dumps([{
        "body": command,
        "user": {"login": "E-merging-digital"},
    }]))
    bad = SimpleNamespace(**vars(args))
    bad.authority_issue_number = 899
    issue.write_text(json.dumps({
        "number": 899,
        "state": "open",
        "user": {"login": "E-merging-digital"},
        "body": body,
    }))
    try:
        mod.validate(bad)
    except mod.Reject:
        pass
    else:
        raise AssertionError("#899 self authority accepted")

print("SUCCESSOR_AUTHORITY_TEST=PASS")
print("REQUEST_ID_ONE_SHOT_TEST=PASS")
print("#899_SELF_EXECUTION=FORBIDDEN")
