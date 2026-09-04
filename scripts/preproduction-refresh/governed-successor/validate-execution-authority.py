#!/usr/bin/env python3
"""Fail-closed one-shot PLAN/APPLY authority for #914."""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

PARENT_ISSUE = 816
IMPLEMENTATION_ISSUE = 914
LAST_NON_EXECUTION_AUTHORITY = 920
AUTHORIZED_ACTOR = "E-merging-digital"
PROFILE_ID = "agency-preprod-refresh-simple-v1"
MARKER_PREFIX = "AGENCY_PREPROD_REFRESH_SUCCESSOR_AUTHORITY="
TRIGGER = "/agency-preprod-refresh-successor"
MODES = {"PLAN", "APPLY"}
MARKER_KEYS = {
    "schema_version", "parent_issue", "implementation_issue", "authority_issue",
    "mode", "request_id", "main_sha", "prod_release_sha", "profile_id",
    "authorized_actor", "run_attempt",
}
SHA40 = re.compile(r"^[0-9a-f]{40}$")
SUFFIX = re.compile(r"^[A-Za-z0-9._-]{8,40}$")


class AuthorityError(RuntimeError):
    pass


def load_json(path: str) -> Any:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def flatten(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        raise AuthorityError("Comments must be an array.")
    out: list[dict[str, Any]] = []
    for item in value:
        if isinstance(item, list):
            out.extend(flatten(item))
        elif isinstance(item, dict):
            out.append(item)
        else:
            raise AuthorityError("Comments contain a non-object.")
    return out


def labels(issue: dict[str, Any]) -> set[str]:
    result: set[str] = set()
    for item in issue.get("labels", []):
        if isinstance(item, dict) and isinstance(item.get("name"), str):
            result.add(item["name"])
        elif isinstance(item, str):
            result.add(item)
    return result


def marker_from_body(body: str) -> dict[str, Any]:
    lines = [line for line in body.splitlines() if line.startswith(MARKER_PREFIX)]
    if len(lines) != 1:
        raise AuthorityError("Exactly one authority marker is required.")
    raw = lines[0][len(MARKER_PREFIX):]
    try:
        marker = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise AuthorityError("Authority marker is invalid JSON.") from exc
    if not isinstance(marker, dict) or set(marker) != MARKER_KEYS:
        raise AuthorityError("Authority marker schema mismatch.")
    if raw != json.dumps(marker, sort_keys=True, separators=(",", ":")):
        raise AuthorityError("Authority marker must be canonical JSON.")
    return marker


def expected_request(mode: str, issue: int, request_id: str) -> bool:
    prefix = f"{mode.lower()}-{issue}-"
    if not request_id.startswith(prefix) or not request_id.endswith("-r1"):
        return False
    return bool(SUFFIX.fullmatch(request_id[len(prefix):-3]))


def expected_comment(marker: dict[str, Any]) -> str:
    return (
        f"{TRIGGER} {marker['mode']} {marker['request_id']} {marker['main_sha']} "
        f"{marker['prod_release_sha']} {marker['profile_id']}"
    )


def validate(
    *, issue: dict[str, Any], comments: list[dict[str, Any]], authority_issue: int,
    comment_body: str, comment_author: str, github_actor: str, event_name: str,
    event_action: str, run_attempt: str, live_main: str, checked_out_head: str | None = None,
    expected_mode: str | None = None, expected_request_id: str | None = None,
    expected_main: str | None = None, expected_profile: str | None = None,
) -> dict[str, str]:
    if event_name != "issue_comment" or event_action != "created":
        raise AuthorityError("Only a newly-created issue comment may authorize execution.")
    if github_actor != AUTHORIZED_ACTOR or comment_author != AUTHORIZED_ACTOR:
        raise AuthorityError("Wrong actor.")
    if run_attempt != "1":
        raise AuthorityError("Rerun/replay is forbidden.")
    if not SHA40.fullmatch(live_main):
        raise AuthorityError("Live main is invalid.")
    if authority_issue <= LAST_NON_EXECUTION_AUTHORITY:
        raise AuthorityError("Implementation/governance issues cannot execute.")
    if issue.get("number") != authority_issue or issue.get("pull_request") is not None:
        raise AuthorityError("Authority must be a distinct issue.")
    if issue.get("state") != "open" or issue.get("user", {}).get("login") != AUTHORIZED_ACTOR:
        raise AuthorityError("Authority issue is not open and owner-created.")
    if "status:in-progress" not in labels(issue):
        raise AuthorityError("Authority issue is not active.")
    body = issue.get("body")
    if not isinstance(body, str) or "Parent: #816" not in body:
        raise AuthorityError("Authority issue is not under #816.")

    marker = marker_from_body(body)
    if marker["schema_version"] != 4:
        raise AuthorityError("Wrong authority schema.")
    if marker["parent_issue"] != PARENT_ISSUE or marker["implementation_issue"] != IMPLEMENTATION_ISSUE:
        raise AuthorityError("Wrong implementation binding.")
    if marker["authority_issue"] != authority_issue:
        raise AuthorityError("Wrong authority issue binding.")
    mode = marker["mode"]
    if mode not in MODES:
        raise AuthorityError("Only PLAN/APPLY are supported.")
    request_id = marker["request_id"]
    if not isinstance(request_id, str) or not expected_request(mode, authority_issue, request_id):
        raise AuthorityError("Invalid one-shot request identity.")
    if marker["main_sha"] != live_main or not SHA40.fullmatch(marker["main_sha"]):
        raise AuthorityError("Stale or invalid main authority.")
    if marker["profile_id"] != PROFILE_ID or marker["authorized_actor"] != AUTHORIZED_ACTOR:
        raise AuthorityError("Wrong profile/actor binding.")
    if marker["run_attempt"] != 1:
        raise AuthorityError("Marker must authorize attempt 1 only.")
    prod_release = marker["prod_release_sha"]
    if mode == "PLAN" and prod_release != "AUTO":
        raise AuthorityError("PLAN must observe PROD release metadata only.")
    if mode == "APPLY" and (not isinstance(prod_release, str) or not SHA40.fullmatch(prod_release)):
        raise AuthorityError("APPLY requires exact PROD release SHA.")

    exact_comment = expected_comment(marker)
    if comment_body != exact_comment:
        raise AuthorityError("Trigger does not exactly match marker.")
    bodies = [c.get("body") for c in comments if isinstance(c.get("body"), str)]
    if sum(body == exact_comment for body in bodies) != 1:
        raise AuthorityError("Exact trigger must occur once.")
    if sum(request_id in body for body in bodies) != 1:
        raise AuthorityError("Request identity is duplicated/reused.")

    if checked_out_head is not None and checked_out_head != live_main:
        raise AuthorityError("Checked-out HEAD is not live main.")
    for actual, expected, label in (
        (mode, expected_mode, "mode"),
        (request_id, expected_request_id, "request"),
        (marker["main_sha"], expected_main, "main"),
        (marker["profile_id"], expected_profile, "profile"),
    ):
        if expected is not None and actual != expected:
            raise AuthorityError(f"JIT {label} changed.")

    return {
        "implementation_issue": str(IMPLEMENTATION_ISSUE),
        "authority_issue": str(authority_issue),
        "mode": mode,
        "request_id": request_id,
        "main_sha": marker["main_sha"],
        "prod_release_sha": prod_release,
        "operation_profile": PROFILE_ID,
        "authorized_actor": AUTHORIZED_ACTOR,
    }


def write_outputs(path: str, outputs: dict[str, str]) -> None:
    with open(path, "a", encoding="utf-8") as handle:
        for key, value in outputs.items():
            if "\n" in value or "\r" in value:
                raise AuthorityError("Multiline output is forbidden.")
            handle.write(f"{key}={value}\n")


def main() -> int:
    p = argparse.ArgumentParser()
    for arg in ("issue-json", "comments-json", "comment-body", "comment-author", "github-actor",
                "event-name", "event-action", "run-attempt", "live-main"):
        p.add_argument(f"--{arg}", required=True)
    p.add_argument("--authority-issue-number", required=True, type=int)
    p.add_argument("--checked-out-head")
    p.add_argument("--expected-mode")
    p.add_argument("--expected-request-id")
    p.add_argument("--expected-main")
    p.add_argument("--expected-profile")
    p.add_argument("--github-output")
    a = p.parse_args()
    outputs = validate(
        issue=load_json(a.issue_json), comments=flatten(load_json(a.comments_json)),
        authority_issue=a.authority_issue_number, comment_body=a.comment_body,
        comment_author=a.comment_author, github_actor=a.github_actor,
        event_name=a.event_name, event_action=a.event_action, run_attempt=a.run_attempt,
        live_main=a.live_main, checked_out_head=a.checked_out_head,
        expected_mode=a.expected_mode, expected_request_id=a.expected_request_id,
        expected_main=a.expected_main, expected_profile=a.expected_profile,
    )
    if a.github_output:
        write_outputs(a.github_output, outputs)
    for key, value in outputs.items():
        print(f"{key}={value}")
    print("GITHUB_TRANSACTIONAL_STATE=NONE")
    print("HISTORICAL_RECOVERY_TARGET=NONE")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AuthorityError, json.JSONDecodeError, OSError) as exc:
        print(f"AUTHORITY=FAIL_CLOSED:{exc}")
        raise SystemExit(80)
