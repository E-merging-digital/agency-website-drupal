#!/usr/bin/env python3
"""Fail-closed one-shot Development Seed publication authority for #956."""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

AUTHORITY_ISSUE = 956
PARENT_ISSUE = 873
AUTHORIZED_ACTOR = "E-merging-digital"
TRIGGER = "/agency-development-seed publish"
SHA40 = re.compile(r"^[0-9a-f]{40}$")
REFRESH_ID = re.compile(r"^[A-Za-z0-9._:-]{8,80}$")
REQUEST_SUFFIX = re.compile(r"^[A-Za-z0-9._-]{8,40}$")


class AuthorityError(RuntimeError):
    """Bounded authority validation failure."""


def load_json(path: str) -> Any:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def flatten(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        raise AuthorityError("Comments must be an array.")
    result: list[dict[str, Any]] = []
    for item in value:
        if isinstance(item, list):
            result.extend(flatten(item))
        elif isinstance(item, dict):
            result.append(item)
        else:
            raise AuthorityError("Comments contain a non-object.")
    return result


def labels(issue: dict[str, Any]) -> set[str]:
    result: set[str] = set()
    for item in issue.get("labels", []):
        if isinstance(item, dict) and isinstance(item.get("name"), str):
            result.add(item["name"])
        elif isinstance(item, str):
            result.add(item)
    return result


def parse_comment(body: str, issue_number: int) -> dict[str, str]:
    parts = body.split(" ")
    if len(parts) != 6 or parts[0] != "/agency-development-seed" or parts[1] != "publish":
        raise AuthorityError("Unsupported Development Seed command.")
    request_id, main_sha, source_refresh, source_release = parts[2:]
    prefix = f"seed-{issue_number}-"
    if not request_id.startswith(prefix) or not request_id.endswith("-r1"):
        raise AuthorityError("Invalid one-shot seed request identity.")
    suffix = request_id[len(prefix):-3]
    if not REQUEST_SUFFIX.fullmatch(suffix):
        raise AuthorityError("Invalid one-shot seed request suffix.")
    if not SHA40.fullmatch(main_sha):
        raise AuthorityError("Invalid exact main identity.")
    if not REFRESH_ID.fullmatch(source_refresh):
        raise AuthorityError("Invalid PREPROD refresh identity.")
    if not SHA40.fullmatch(source_release):
        raise AuthorityError("Invalid PREPROD release identity.")
    return {
        "request_id": request_id,
        "main_sha": main_sha,
        "source_refresh": source_refresh,
        "source_release": source_release,
    }


def validate(
    *,
    issue: dict[str, Any],
    comments: list[dict[str, Any]],
    authority_issue: int,
    comment_body: str,
    comment_author: str,
    github_actor: str,
    event_name: str,
    event_action: str,
    run_attempt: str,
    live_main: str,
    checked_out_head: str | None = None,
    expected_request_id: str | None = None,
    expected_main: str | None = None,
    expected_source_refresh: str | None = None,
    expected_source_release: str | None = None,
) -> dict[str, str]:
    if authority_issue != AUTHORITY_ISSUE:
        raise AuthorityError("Only issue #956 owns first real seed publication authority.")
    if event_name != "issue_comment" or event_action != "created":
        raise AuthorityError("Only a newly-created issue comment may authorize publication.")
    if github_actor != AUTHORIZED_ACTOR or comment_author != AUTHORIZED_ACTOR:
        raise AuthorityError("Wrong actor.")
    if run_attempt != "1":
        raise AuthorityError("Rerun/replay is forbidden.")
    if not SHA40.fullmatch(live_main):
        raise AuthorityError("Live main is invalid.")
    if issue.get("number") != authority_issue or issue.get("pull_request") is not None:
        raise AuthorityError("Authority must be issue #956, not a pull request.")
    if issue.get("state") != "open" or issue.get("user", {}).get("login") != AUTHORIZED_ACTOR:
        raise AuthorityError("Authority issue is not open and owner-created.")
    if "status:in-progress" not in labels(issue):
        raise AuthorityError("Authority issue is not active.")
    body = issue.get("body")
    if not isinstance(body, str) or "Parent: #873" not in body:
        raise AuthorityError("Authority issue is not bound to #873.")

    parsed = parse_comment(comment_body, authority_issue)
    if parsed["main_sha"] != live_main:
        raise AuthorityError("Seed publication authority is stale relative to live main.")

    exact_comment = (
        f"{TRIGGER} {parsed['request_id']} {parsed['main_sha']} "
        f"{parsed['source_refresh']} {parsed['source_release']}"
    )
    if comment_body != exact_comment:
        raise AuthorityError("Trigger is not canonical.")

    bodies = [item.get("body") for item in comments if isinstance(item.get("body"), str)]
    if sum(value == exact_comment for value in bodies) != 1:
        raise AuthorityError("Exact trigger must occur exactly once.")
    if sum(parsed["request_id"] in value for value in bodies) != 1:
        raise AuthorityError("Request identity is duplicated or reused.")

    if checked_out_head is not None and checked_out_head != live_main:
        raise AuthorityError("Checked-out HEAD is not exact live main.")
    for actual, expected, label in (
        (parsed["request_id"], expected_request_id, "request"),
        (parsed["main_sha"], expected_main, "main"),
        (parsed["source_refresh"], expected_source_refresh, "source refresh"),
        (parsed["source_release"], expected_source_release, "source release"),
    ):
        if expected is not None and actual != expected:
            raise AuthorityError(f"JIT {label} changed.")

    return parsed


def write_outputs(path: str, outputs: dict[str, str]) -> None:
    with open(path, "a", encoding="utf-8") as handle:
        for key, value in outputs.items():
            if "\n" in value or "\r" in value:
                raise AuthorityError("Multiline output is forbidden.")
            handle.write(f"{key}={value}\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    for arg in (
        "issue-json", "comments-json", "comment-body", "comment-author",
        "github-actor", "event-name", "event-action", "run-attempt", "live-main",
    ):
        parser.add_argument(f"--{arg}", required=True)
    parser.add_argument("--authority-issue-number", required=True, type=int)
    parser.add_argument("--checked-out-head")
    parser.add_argument("--expected-request-id")
    parser.add_argument("--expected-main")
    parser.add_argument("--expected-source-refresh")
    parser.add_argument("--expected-source-release")
    parser.add_argument("--github-output")
    args = parser.parse_args()

    outputs = validate(
        issue=load_json(args.issue_json),
        comments=flatten(load_json(args.comments_json)),
        authority_issue=args.authority_issue_number,
        comment_body=args.comment_body,
        comment_author=args.comment_author,
        github_actor=args.github_actor,
        event_name=args.event_name,
        event_action=args.event_action,
        run_attempt=args.run_attempt,
        live_main=args.live_main,
        checked_out_head=args.checked_out_head,
        expected_request_id=args.expected_request_id,
        expected_main=args.expected_main,
        expected_source_refresh=args.expected_source_refresh,
        expected_source_release=args.expected_source_release,
    )
    if args.github_output:
        write_outputs(args.github_output, outputs)
    for key, value in outputs.items():
        print(f"{key}={value}")
    print("AUTHORITY=PASS")
    print("ONE_SHOT=PASS")
    print("REQUEST_REUSE=FORBIDDEN")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AuthorityError, json.JSONDecodeError, OSError) as exc:
        print(f"AUTHORITY=FAIL_CLOSED:{exc}")
        raise SystemExit(80)
