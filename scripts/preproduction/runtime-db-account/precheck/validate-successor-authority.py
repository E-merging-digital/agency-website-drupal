#!/usr/bin/env python3
"""Fail-closed successor authority validator for #895 runtime DB PRECHECK."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from types import SimpleNamespace
from typing import Any

OWNER = "E-merging-digital"
PARENT_ISSUE = 816
IMPLEMENTATION_ISSUE = 895
PROFILE = "agency-preprod-runtime-db-precheck-v1"
COMMAND = "/agency-preprod-runtime-db-precheck"

MARKER_RE = re.compile(
    r"(?m)^AGENCY_PREPROD_RUNTIME_DB_PRECHECK_AUTHORITY\n"
    r"parent_issue=(\d+)\n"
    r"implementation_issue=(\d+)\n"
    r"allowed_action=precheck$"
)
COMMAND_RE = re.compile(
    rf"^{re.escape(COMMAND)} "
    rf"([A-Za-z0-9._-]{{8,80}}) "
    rf"([0-9a-f]{{40}}) "
    rf"{re.escape(PROFILE)}$"
)


class Reject(RuntimeError):
    """Fail-closed authority rejection."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise Reject(message)


def load_json(path: str) -> Any:
    with Path(path).open("r", encoding="utf-8") as handle:
        return json.load(handle)


def flatten_comments(raw: Any) -> list[dict[str, Any]]:
    require(isinstance(raw, list), "comments payload must be a list")
    if raw and all(isinstance(item, list) for item in raw):
        raw = [comment for page in raw for comment in page]
    require(
        all(isinstance(item, dict) for item in raw),
        "comments payload shape invalid",
    )
    return list(raw)


def validate(args: Any) -> dict[str, str]:
    issue = load_json(args.issue_json)
    comments = flatten_comments(load_json(args.comments_json))

    require(args.event_name == "issue_comment", "event must be issue_comment")
    require(args.event_action == "created", "issue_comment action must be created")
    require(args.run_attempt == "1", "fresh authorization requires run attempt 1")
    require(args.github_actor == OWNER, "GitHub actor is not the owner")
    require(args.comment_author == OWNER, "comment author is not the owner")
    require(
        args.comment_author == args.github_actor,
        "comment author/actor mismatch",
    )

    require(isinstance(issue, dict), "issue payload must be an object")
    require(
        issue.get("number") == args.authority_issue_number,
        "event/live issue identity mismatch",
    )
    require("pull_request" not in issue, "pull request comments cannot authorize")
    require(issue.get("state") == "open", "authority issue must be open")
    require(
        (issue.get("user") or {}).get("login") == OWNER,
        "authority issue creator is not the owner",
    )
    require(
        args.authority_issue_number != IMPLEMENTATION_ISSUE,
        "#895 cannot be live execution authority",
    )

    body = issue.get("body")
    require(isinstance(body, str), "authority issue body missing")
    markers = list(MARKER_RE.finditer(body))
    require(len(markers) == 1, "authority marker must occur exactly once")
    parent_issue, implementation_issue = markers[0].groups()
    require(int(parent_issue) == PARENT_ISSUE, "wrong parent_issue")
    require(
        int(implementation_issue) == IMPLEMENTATION_ISSUE,
        "wrong implementation_issue",
    )

    require(
        "\n" not in args.comment_body and "\r" not in args.comment_body,
        "command must be one exact line",
    )
    match = COMMAND_RE.fullmatch(args.comment_body)
    require(match is not None, "command grammar/profile/main shape invalid")
    request_id, supplied_main = match.groups()
    require(supplied_main == args.live_main, "supplied main SHA is not live main")

    prefix = (
        f"issue-{args.authority_issue_number}-precheck-"
        f"{args.live_main[:8]}-r"
    )
    require(request_id.startswith(prefix), "request ID is not authority/main bound")
    revision = request_id[len(prefix):]
    require(
        re.fullmatch(r"[1-9][0-9]*", revision) is not None,
        "request ID revision is invalid",
    )

    exact_owner_comments = [
        comment
        for comment in comments
        if comment.get("body") == args.comment_body
        and (comment.get("user") or {}).get("login") == OWNER
    ]
    require(
        len(exact_owner_comments) == 1,
        "exact owner authorization comment must be unique",
    )

    request_mentions = [
        comment
        for comment in comments
        if isinstance(comment.get("body"), str)
        and request_id in comment["body"]
    ]
    require(
        len(request_mentions) == 1,
        "request ID must appear exactly once in authority comments",
    )
    require(
        request_mentions[0] is exact_owner_comments[0],
        "request ID mention is not the exact owner authorization",
    )

    return {
        "request_id": request_id,
        "main_sha": args.live_main,
        "operation_profile": PROFILE,
        "authority_issue": str(args.authority_issue_number),
        "implementation_issue": str(IMPLEMENTATION_ISSUE),
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
        print(f"AUTHORITY_REJECTED={exc}", file=__import__("sys").stderr)
        return 1

    if args.github_output:
        with Path(args.github_output).open("a", encoding="utf-8") as handle:
            for key, value in outputs.items():
                handle.write(f"{key}={value}\n")

    for key, value in outputs.items():
        print(f"{key.upper()}={value}")
    print("SUCCESSOR_PRECHECK_AUTHORITY=PASS")
    print("#895_LIVE_EXECUTION_AUTHORITY=FORBIDDEN")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
