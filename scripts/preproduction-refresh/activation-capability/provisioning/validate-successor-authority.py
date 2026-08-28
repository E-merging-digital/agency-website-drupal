#!/usr/bin/env python3
"""Fail-closed successor execution-authority validator for #874 capability provisioning."""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path

OWNER = "E-merging-digital"
PARENT_ISSUE = 816
IMPLEMENTATION_ISSUE = 874
PROFILE = "agency-preprod-refresh-capability-provision-v1"
COMMAND = "/agency-preprod-refresh-capability-provision"
MARKER_RE = re.compile(
    r"(?m)^AGENCY_PREPROD_REFRESH_CAPABILITY_PROVISION_AUTHORITY\n"
    r"parent_issue=(\d+)\n"
    r"implementation_issue=(\d+)\n"
    r"allowed_mode=(plan|apply)$"
)
COMMAND_RE = re.compile(
    rf"^{re.escape(COMMAND)} (plan|apply) ([A-Za-z0-9._-]{{8,80}}) ([0-9a-f]{{40}}) "
    rf"{re.escape(PROFILE)}$"
)


class Reject(Exception):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise Reject(message)


def load_json(path: str):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def flatten_comments(raw):
    require(isinstance(raw, list), "comments payload must be a list")
    if raw and all(isinstance(item, list) for item in raw):
        return [comment for page in raw for comment in page]
    require(all(isinstance(item, dict) for item in raw), "comments payload shape invalid")
    return raw


def validate(args) -> dict[str, str]:
    issue = load_json(args.issue_json)
    comments = flatten_comments(load_json(args.comments_json))

    require(args.event_name == "issue_comment", "event must be issue_comment")
    require(args.event_action == "created", "issue_comment action must be created")
    require(args.run_attempt == "1", "fresh authorization requires run attempt 1")
    require(args.github_actor == OWNER, "GitHub actor is not the owner")
    require(args.comment_author == OWNER, "comment author is not the owner")
    require(args.comment_author == args.github_actor, "comment author/actor mismatch")

    require(isinstance(issue, dict), "issue payload must be an object")
    require(issue.get("number") == args.authority_issue_number, "event/live issue identity mismatch")
    require("pull_request" not in issue, "pull request comments cannot authorize")
    require(issue.get("state") == "open", "authority issue must be open")
    require((issue.get("user") or {}).get("login") == OWNER, "authority issue creator is not the owner")
    require(args.authority_issue_number != IMPLEMENTATION_ISSUE, "#874 cannot be live execution authority")

    body = issue.get("body")
    require(isinstance(body, str), "authority issue body missing")
    markers = list(MARKER_RE.finditer(body))
    require(len(markers) == 1, "authority marker must occur exactly once")
    parent_issue, implementation_issue, allowed_mode = markers[0].groups()
    require(int(parent_issue) == PARENT_ISSUE, "wrong parent_issue")
    require(int(implementation_issue) == IMPLEMENTATION_ISSUE, "wrong implementation_issue")

    require("\n" not in args.comment_body and "\r" not in args.comment_body, "command must be one exact line")
    command_match = COMMAND_RE.fullmatch(args.comment_body)
    require(command_match is not None, "command grammar/profile/main shape invalid")
    mode, request_id, supplied_main = command_match.groups()
    require(mode == allowed_mode, "command mode is not authorized by issue marker")
    require(supplied_main == args.live_main, "supplied main SHA is not live main")

    expected_prefix = f"issue-{args.authority_issue_number}-{mode}-{args.live_main[:8]}-r"
    require(request_id.startswith(expected_prefix), "request ID is not bound to authority issue/mode/main")
    revision = request_id[len(expected_prefix):]
    require(re.fullmatch(r"[1-9][0-9]*", revision) is not None, "request ID revision is invalid")

    exact_owner_comments = [
        comment for comment in comments
        if isinstance(comment, dict)
        and comment.get("body") == args.comment_body
        and (comment.get("user") or {}).get("login") == OWNER
    ]
    require(len(exact_owner_comments) == 1, "exact owner authorization comment must be unique")

    return {
        "mode": mode,
        "request_id": request_id,
        "main_sha": args.live_main,
        "operation_profile": PROFILE,
        "authority_issue": str(args.authority_issue_number),
        "implementation_issue": str(IMPLEMENTATION_ISSUE),
    }


def parse_args():
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
        with open(args.github_output, "a", encoding="utf-8") as handle:
            for key, value in outputs.items():
                handle.write(f"{key}={value}\n")
    for key, value in outputs.items():
        print(f"{key.upper()}={value}")
    print("SUCCESSOR_EXECUTION_AUTHORITY=PASS")
    print("#874_LIVE_EXECUTION_AUTHORITY=FORBIDDEN")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
