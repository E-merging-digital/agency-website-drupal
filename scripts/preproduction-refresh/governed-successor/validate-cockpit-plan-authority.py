#!/usr/bin/env python3
"""Fail-closed human GitHub UI authority for Cockpit PREPROD PLAN."""
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
SCHEMA_VERSION = 1
MARKER_PREFIX = "AGENCY_PREPROD_COCKPIT_PLAN_AUTHORITY="
EXPECTED_TITLE = "[Ops][PLAN] Prepare PREPROD refresh PLAN"
MARKER = {
    "authorized_actor": AUTHORIZED_ACTOR,
    "implementation_issue": IMPLEMENTATION_ISSUE,
    "mode": "PLAN",
    "parent_issue": PARENT_ISSUE,
    "profile_id": PROFILE_ID,
    "run_attempt": 1,
    "schema_version": SCHEMA_VERSION,
}
CANONICAL_MARKER = json.dumps(MARKER, sort_keys=True, separators=(",", ":"))
EXPECTED_BODY = (
    "Parent: #816\n\n"
    "## Cockpit PREPROD PLAN authority\n\n"
    "This issue authorizes one PLAN against the live main resolved when the issue is opened.\n\n"
    "PLAN = analysis / preparation only\n"
    "NO DATA MUTATION\n"
    "APPLY = NOT AUTHORIZED\n\n"
    f"{MARKER_PREFIX}{CANONICAL_MARKER}\n"
)
REQUIRED_LABELS = {"Task", "P1", "status:in-progress"}
SHA40 = re.compile(r"^[0-9a-f]{40}$")


class AuthorityError(RuntimeError):
    """Raised when the cockpit authority must fail closed."""


def load_json(path: str) -> Any:
    return json.loads(Path(path).read_text(encoding="utf-8"))


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
        raise AuthorityError("Exactly one cockpit authority marker is required.")
    raw = lines[0][len(MARKER_PREFIX):]
    try:
        marker = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise AuthorityError("Cockpit authority marker is invalid JSON.") from exc
    if marker != MARKER:
        raise AuthorityError("Cockpit authority marker schema/value mismatch.")
    if raw != CANONICAL_MARKER:
        raise AuthorityError("Cockpit authority marker must be canonical JSON.")
    return marker


def validate(
    *,
    issue: dict[str, Any],
    authority_issue: int,
    github_actor: str,
    event_name: str,
    event_action: str,
    run_attempt: str,
    live_main: str,
    checked_out_head: str | None = None,
    expected_request_id: str | None = None,
    expected_main: str | None = None,
    expected_profile: str | None = None,
) -> dict[str, str]:
    if event_name != "issues" or event_action != "opened":
        raise AuthorityError("Only a newly-opened issue may authorize cockpit PLAN.")
    if github_actor != AUTHORIZED_ACTOR:
        raise AuthorityError("Wrong actor.")
    if run_attempt != "1":
        raise AuthorityError("Rerun/replay is forbidden.")
    if not SHA40.fullmatch(live_main):
        raise AuthorityError("Live main is invalid.")
    if authority_issue <= LAST_NON_EXECUTION_AUTHORITY:
        raise AuthorityError("Implementation/governance issues cannot execute.")
    if issue.get("number") != authority_issue or issue.get("pull_request") is not None:
        raise AuthorityError("Authority must be a distinct issue.")
    if issue.get("state") != "open":
        raise AuthorityError("Authority issue is not open.")
    if issue.get("user", {}).get("login") != AUTHORIZED_ACTOR:
        raise AuthorityError("Authority issue was not created by the owner account.")
    if issue.get("author_association") != "OWNER":
        raise AuthorityError("Authority issue author is not repository owner.")
    if issue.get("performed_via_github_app") is not None:
        raise AuthorityError("App-mediated authority is forbidden.")
    created_at = issue.get("created_at")
    updated_at = issue.get("updated_at")
    if not isinstance(created_at, str) or created_at == "" or created_at != updated_at:
        raise AuthorityError("Authority issue was edited after creation.")
    if not REQUIRED_LABELS.issubset(labels(issue)):
        raise AuthorityError("Required cockpit authority labels are missing.")
    if issue.get("title") != EXPECTED_TITLE:
        raise AuthorityError("Cockpit authority title mismatch.")
    body = issue.get("body")
    if not isinstance(body, str) or body != EXPECTED_BODY:
        raise AuthorityError("Cockpit authority body mismatch.")
    marker_from_body(body)

    request_id = f"plan-{authority_issue}-cockpit-v1-r1"
    if checked_out_head is not None and checked_out_head != live_main:
        raise AuthorityError("Checked-out HEAD is not live main.")
    for actual, expected, label in (
        (request_id, expected_request_id, "request"),
        (live_main, expected_main, "main"),
        (PROFILE_ID, expected_profile, "profile"),
    ):
        if expected is not None and actual != expected:
            raise AuthorityError(f"JIT {label} changed.")

    return {
        "implementation_issue": str(IMPLEMENTATION_ISSUE),
        "authority_issue": str(authority_issue),
        "mode": "PLAN",
        "request_id": request_id,
        "main_sha": live_main,
        "prod_release_sha": "AUTO",
        "operation_profile": PROFILE_ID,
        "authorized_actor": AUTHORIZED_ACTOR,
        "authority_shape": "COCKPIT_ISSUE_OPEN_PLAN",
    }


def write_outputs(path: str, outputs: dict[str, str]) -> None:
    with open(path, "a", encoding="utf-8") as handle:
        for key, value in outputs.items():
            if "\n" in value or "\r" in value:
                raise AuthorityError("Multiline output is forbidden.")
            handle.write(f"{key}={value}\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--issue-json", required=True)
    parser.add_argument("--authority-issue-number", required=True, type=int)
    parser.add_argument("--github-actor", required=True)
    parser.add_argument("--event-name", required=True)
    parser.add_argument("--event-action", required=True)
    parser.add_argument("--run-attempt", required=True)
    parser.add_argument("--live-main", required=True)
    parser.add_argument("--checked-out-head")
    parser.add_argument("--expected-request-id")
    parser.add_argument("--expected-main")
    parser.add_argument("--expected-profile")
    parser.add_argument("--github-output")
    args = parser.parse_args()
    outputs = validate(
        issue=load_json(args.issue_json),
        authority_issue=args.authority_issue_number,
        github_actor=args.github_actor,
        event_name=args.event_name,
        event_action=args.event_action,
        run_attempt=args.run_attempt,
        live_main=args.live_main,
        checked_out_head=args.checked_out_head,
        expected_request_id=args.expected_request_id,
        expected_main=args.expected_main,
        expected_profile=args.expected_profile,
    )
    if args.github_output:
        write_outputs(args.github_output, outputs)
    for key, value in outputs.items():
        print(f"{key}={value}")
    print("COCKPIT_GITHUB_WRITE=NONE")
    print("ISSUE_OPEN_APPLY=IMPOSSIBLE")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AuthorityError, json.JSONDecodeError, OSError) as exc:
        print(f"AUTHORITY=FAIL_CLOSED:{exc}")
        raise SystemExit(80)
