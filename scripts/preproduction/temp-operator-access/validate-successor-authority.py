#!/usr/bin/env python3
"""Fail-closed successor authority validator for #899 temporary operator-key ADD."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

OWNER = "E-merging-digital"
PARENT_ISSUE = 816
IMPLEMENTATION_ISSUE = 899
PROFILE = "agency-preprod-temp-key-add-v1"
COMMAND = "/agency-preprod-temp-key-add"
MARKER_RE = re.compile(
    r"(?m)^AGENCY_PREPROD_TEMP_KEY_ADD_AUTHORITY\n"
    r"parent_issue=(\d+)\n"
    r"implementation_issue=(\d+)\n"
    r"allowed_action=add$"
)
COMMAND_RE = re.compile(
    rf"^{re.escape(COMMAND)} ([A-Za-z0-9._-]{{8,80}}) ([0-9a-f]{{40}}) {re.escape(PROFILE)}$"
)


class Reject(RuntimeError):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise Reject(message)


def load(path: str) -> Any:
    with Path(path).open("r", encoding="utf-8") as handle:
        return json.load(handle)


def flatten(raw: Any) -> list[dict[str, Any]]:
    require(isinstance(raw, list), "comments payload invalid")
    if raw and all(isinstance(item, list) for item in raw):
        raw = [comment for page in raw for comment in page]
    require(all(isinstance(item, dict) for item in raw), "comments shape invalid")
    return list(raw)


def validate(args: Any) -> dict[str, str]:
    issue = load(args.issue_json)
    comments = flatten(load(args.comments_json))

    require(args.event_name == "issue_comment", "event invalid")
    require(args.event_action == "created", "action invalid")
    require(args.run_attempt == "1", "rerun forbidden")
    require(args.github_actor == OWNER and args.comment_author == OWNER, "owner required")
    require(args.github_actor == args.comment_author, "actor mismatch")
    require(isinstance(issue, dict) and issue.get("number") == args.authority_issue_number, "issue mismatch")
    require("pull_request" not in issue and issue.get("state") == "open", "open issue required")
    require((issue.get("user") or {}).get("login") == OWNER, "owner-created issue required")
    require(args.authority_issue_number != IMPLEMENTATION_ISSUE, "#899 cannot authorize execution")

    body = issue.get("body")
    require(isinstance(body, str), "body missing")
    markers = list(MARKER_RE.finditer(body))
    require(len(markers) == 1, "authority marker must be unique")
    parent_issue, implementation_issue = markers[0].groups()
    require(int(parent_issue) == PARENT_ISSUE and int(implementation_issue) == IMPLEMENTATION_ISSUE, "authority lineage invalid")

    require("\n" not in args.comment_body and "\r" not in args.comment_body, "command must be one line")
    match = COMMAND_RE.fullmatch(args.comment_body)
    require(match is not None, "command grammar invalid")
    request_id, supplied_main = match.groups()
    require(supplied_main == args.live_main, "main mismatch")

    prefix = f"issue-{args.authority_issue_number}-add-{args.live_main[:8]}-r"
    require(request_id.startswith(prefix), "request binding invalid")
    require(re.fullmatch(r"[1-9][0-9]*", request_id[len(prefix):]) is not None, "revision invalid")

    exact = [
        comment for comment in comments
        if comment.get("body") == args.comment_body
        and (comment.get("user") or {}).get("login") == OWNER
    ]
    require(len(exact) == 1, "exact owner command must be unique")
    mentions = [
        comment for comment in comments
        if isinstance(comment.get("body"), str) and request_id in comment["body"]
    ]
    require(len(mentions) == 1 and mentions[0] is exact[0], "request id must be one-shot")

    return {
        "request_id": request_id,
        "main_sha": args.live_main,
        "operation_profile": PROFILE,
        "authority_issue": str(args.authority_issue_number),
        "implementation_issue": "899",
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--issue-json", required=True)
    parser.add_argument("--comments-json", required=True)
    parser.add_argument("--authority-issue-number", type=int, required=True)
    parser.add_argument("--event-name", required=True)
    parser.add_argument("--event-action", required=True)
    parser.add_argument("--github-actor", required=True)
    parser.add_argument("--comment-author", required=True)
    parser.add_argument("--comment-body", required=True)
    parser.add_argument("--run-attempt", required=True)
    parser.add_argument("--live-main", required=True)
    parser.add_argument("--github-output")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        outputs = validate(args)
    except Reject as exc:
        print(f"AUTHORITY_REJECTED={exc}", file=sys.stderr)
        return 1

    if args.github_output:
        with Path(args.github_output).open("a", encoding="utf-8") as handle:
            for key, value in outputs.items():
                handle.write(f"{key}={value}\n")

    for key, value in outputs.items():
        print(f"{key.upper()}={value}")
    print("SUCCESSOR_TEMP_KEY_ADD_AUTHORITY=PASS")
    print("#899_LIVE_EXECUTION_AUTHORITY=FORBIDDEN")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
