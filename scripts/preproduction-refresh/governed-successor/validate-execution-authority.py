#!/usr/bin/env python3
"""Fail-closed successor execution authority validator for #914."""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

IMPLEMENTATION_ISSUE = 914
LAST_KNOWN_NON_EXECUTION_AUTHORITY_ISSUE = 917
PARENT_ISSUE = 816
PROFILE_ID = "agency-preprod-governed-refresh-successor-v1"
CAPABILITY_PROFILE_ID = "agency-preprod-refresh-capability-v1"
AUTHORIZED_ACTOR = "E-merging-digital"
MARKER_PREFIX = "AGENCY_PREPROD_REFRESH_SUCCESSOR_AUTHORITY="
TRIGGER = "/agency-preprod-refresh-successor"
SHA40_RE = re.compile(r"^[0-9a-f]{40}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
REQUEST_SUFFIX_RE = re.compile(r"^[A-Za-z0-9._-]{8,40}$")
MARKER_KEYS = {
    "schema_version", "parent_issue", "implementation_issue", "authority_issue",
    "mode", "request_id", "main_sha", "prod_release_sha", "profile_id",
    "authorized_actor", "run_attempt", "target_successor_issue",
    "target_request_id", "target_main_sha", "target_profile_id",
    "target_authority_id", "target_recovery_record_comment_id",
}
MODES = {"PLAN", "APPLY", "RECOVER_ABORT"}


class AuthorityError(RuntimeError):
    pass


def _load_json(path: str) -> Any:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def _flatten_comments(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        raise AuthorityError("Comments JSON must be an array.")
    out: list[dict[str, Any]] = []
    for item in value:
        if isinstance(item, list):
            out.extend(_flatten_comments(item))
        elif isinstance(item, dict):
            out.append(item)
        else:
            raise AuthorityError("Comments JSON contains a non-object.")
    return out


def _single_marker(body: str) -> dict[str, Any]:
    lines = [line for line in body.splitlines() if line.startswith(MARKER_PREFIX)]
    if len(lines) != 1:
        raise AuthorityError("Authority issue must contain exactly one machine-readable marker.")
    raw = lines[0][len(MARKER_PREFIX):]
    try:
        marker = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise AuthorityError("Authority marker JSON is invalid.") from exc
    if not isinstance(marker, dict) or set(marker) != MARKER_KEYS:
        raise AuthorityError("Authority marker schema mismatch.")
    canonical = json.dumps(marker, sort_keys=True, separators=(",", ":"))
    if raw != canonical:
        raise AuthorityError("Authority marker must use canonical JSON serialization.")
    return marker


def _labels(issue: dict[str, Any]) -> set[str]:
    result: set[str] = set()
    for item in issue.get("labels", []):
        if isinstance(item, dict) and isinstance(item.get("name"), str):
            result.add(item["name"])
        elif isinstance(item, str):
            result.add(item)
    return result


def _request_prefix(mode: str) -> str:
    return {"PLAN": "plan", "APPLY": "apply", "RECOVER_ABORT": "recover-abort"}[mode]


def _expected_request(mode: str, issue_number: int, request_id: str) -> bool:
    expected_prefix = f"{_request_prefix(mode)}-{issue_number}-"
    if not request_id.startswith(expected_prefix) or not request_id.endswith("-r1"):
        return False
    middle = request_id[len(expected_prefix):-3]
    return bool(REQUEST_SUFFIX_RE.fullmatch(middle))


def _require_no_target(marker: dict[str, Any]) -> None:
    for key in (
        "target_successor_issue", "target_request_id", "target_main_sha",
        "target_profile_id", "target_authority_id", "target_recovery_record_comment_id",
    ):
        if marker[key] is not None:
            raise AuthorityError("PLAN/APPLY must not carry recovery target binding.")


def _validate_recovery_target(marker: dict[str, Any], authority_issue_number: int) -> None:
    target_issue = marker["target_successor_issue"]
    if not isinstance(target_issue, int) or target_issue <= LAST_KNOWN_NON_EXECUTION_AUTHORITY_ISSUE:
        raise AuthorityError("Recovery target issue is not a valid historical successor authority.")
    if target_issue == authority_issue_number:
        raise AuthorityError("Recovery authority must be distinct from target APPLY authority.")
    target_request = marker["target_request_id"]
    if not isinstance(target_request, str) or not _expected_request("APPLY", target_issue, target_request):
        raise AuthorityError("Recovery target request is not an exact historical APPLY request.")
    if not isinstance(marker["target_main_sha"], str) or not SHA40_RE.fullmatch(marker["target_main_sha"]):
        raise AuthorityError("Recovery target historical main is invalid.")
    if marker["target_profile_id"] != CAPABILITY_PROFILE_ID:
        raise AuthorityError("Recovery target fixed capability profile is invalid.")
    if not isinstance(marker["target_authority_id"], str) or not SHA256_RE.fullmatch(marker["target_authority_id"]):
        raise AuthorityError("Recovery target authority id is invalid.")
    comment_id = marker["target_recovery_record_comment_id"]
    if not isinstance(comment_id, int) or isinstance(comment_id, bool) or comment_id <= 0:
        raise AuthorityError("Recovery target durable record comment id is invalid.")


def _expected_comment(marker: dict[str, Any]) -> str:
    base = (
        f"{TRIGGER} {marker['mode']} {marker['request_id']} {marker['main_sha']} "
        f"{marker['prod_release_sha']} {marker['profile_id']}"
    )
    if marker["mode"] == "RECOVER_ABORT":
        base += (
            f" {marker['target_successor_issue']} {marker['target_request_id']}"
            f" {marker['target_main_sha']} {marker['target_profile_id']}"
            f" {marker['target_authority_id']} {marker['target_recovery_record_comment_id']}"
        )
    return base


def validate(
    *, issue: dict[str, Any], comments: list[dict[str, Any]], authority_issue_number: int,
    comment_body: str, comment_author: str, github_actor: str, event_name: str,
    event_action: str, run_attempt: str, live_main: str, checked_out_head: str | None = None,
    expected_mode: str | None = None, expected_request_id: str | None = None,
    expected_profile: str | None = None, expected_main: str | None = None,
    expected_target_successor_issue: str | None = None,
    expected_target_request_id: str | None = None,
    expected_target_main_sha: str | None = None,
    expected_target_profile_id: str | None = None,
    expected_target_authority_id: str | None = None,
    expected_target_recovery_record_comment_id: str | None = None,
) -> dict[str, str]:
    if event_name != "issue_comment" or event_action != "created":
        raise AuthorityError("Authority is valid only for a freshly created issue comment event.")
    if github_actor != AUTHORIZED_ACTOR or comment_author != AUTHORIZED_ACTOR:
        raise AuthorityError("Wrong owner actor.")
    if run_attempt != "1":
        raise AuthorityError("Request replay/rerun is forbidden.")
    if not SHA40_RE.fullmatch(live_main):
        raise AuthorityError("Live main identity is invalid.")
    if authority_issue_number <= LAST_KNOWN_NON_EXECUTION_AUTHORITY_ISSUE:
        raise AuthorityError("Known implementation/capability issues through #917 cannot authorize execution.")
    if issue.get("number") != authority_issue_number:
        raise AuthorityError("Authority issue number mismatch.")
    if issue.get("pull_request") is not None:
        raise AuthorityError("A pull request cannot be successor execution authority.")
    if issue.get("state") != "open":
        raise AuthorityError("Authority issue is not open.")
    if issue.get("user", {}).get("login") != AUTHORIZED_ACTOR:
        raise AuthorityError("Authority issue is not owner-created.")
    if "status:in-progress" not in _labels(issue):
        raise AuthorityError("Authority issue is not active.")
    body = issue.get("body")
    if not isinstance(body, str) or "Parent: #816" not in body:
        raise AuthorityError("Authority issue is not explicitly under #816.")

    marker = _single_marker(body)
    if marker["schema_version"] != 3:
        raise AuthorityError("Wrong authority schema version.")
    if marker["parent_issue"] != PARENT_ISSUE or marker["implementation_issue"] != IMPLEMENTATION_ISSUE:
        raise AuthorityError("Wrong parent/implementation authority binding.")
    if marker["authority_issue"] != authority_issue_number:
        raise AuthorityError("Wrong successor authority issue binding.")
    mode = marker["mode"]
    if mode not in MODES:
        raise AuthorityError("Wrong execution mode.")
    request_id = marker["request_id"]
    if not isinstance(request_id, str) or not _expected_request(mode, authority_issue_number, request_id):
        raise AuthorityError("Request identity is not mode/issue-bound one-shot identity.")
    main_sha = marker["main_sha"]
    if main_sha != live_main or not SHA40_RE.fullmatch(main_sha):
        raise AuthorityError("Wrong or stale recovery/execution main authority.")
    if marker["profile_id"] != PROFILE_ID or marker["authorized_actor"] != AUTHORIZED_ACTOR:
        raise AuthorityError("Wrong operation profile/authorized actor.")
    if marker["run_attempt"] != 1:
        raise AuthorityError("Marker does not authorize exactly run attempt 1.")

    prod_release = marker["prod_release_sha"]
    if mode == "PLAN":
        _require_no_target(marker)
        if prod_release != "AUTO":
            raise AuthorityError("PLAN must use metadata-only AUTO PROD release observation.")
    elif mode == "APPLY":
        _require_no_target(marker)
        if not isinstance(prod_release, str) or not SHA40_RE.fullmatch(prod_release):
            raise AuthorityError("APPLY requires exact PROD release identity.")
    else:
        if prod_release != "NONE":
            raise AuthorityError("RECOVER_ABORT must not bind or consume PROD release access.")
        _validate_recovery_target(marker, authority_issue_number)

    expected_comment = _expected_comment(marker)
    if comment_body != expected_comment:
        raise AuthorityError("Trigger comment does not exactly match authority marker.")
    all_bodies = [c.get("body") for c in comments if isinstance(c.get("body"), str)]
    if sum(value == expected_comment for value in all_bodies) != 1:
        raise AuthorityError("Exact authority trigger must occur once.")
    if sum(request_id in value for value in all_bodies) != 1:
        raise AuthorityError("Request identity is duplicated/reused in authority comments.")

    if checked_out_head is not None and checked_out_head != live_main:
        raise AuthorityError("Checked-out HEAD is not current live main.")
    if expected_mode is not None and mode != expected_mode:
        raise AuthorityError("JIT mode no longer matches originally authorized mode.")
    if expected_request_id is not None and request_id != expected_request_id:
        raise AuthorityError("JIT request no longer matches originally authorized request.")
    if expected_profile is not None and marker["profile_id"] != expected_profile:
        raise AuthorityError("JIT profile no longer matches originally authorized profile.")
    if expected_main is not None and main_sha != expected_main:
        raise AuthorityError("JIT main no longer matches originally authorized current main.")

    target_outputs = {
        "target_successor_issue": "" if marker["target_successor_issue"] is None else str(marker["target_successor_issue"]),
        "target_request_id": "" if marker["target_request_id"] is None else marker["target_request_id"],
        "target_main_sha": "" if marker["target_main_sha"] is None else marker["target_main_sha"],
        "target_profile_id": "" if marker["target_profile_id"] is None else marker["target_profile_id"],
        "target_authority_id": "" if marker["target_authority_id"] is None else marker["target_authority_id"],
        "target_recovery_record_comment_id": "" if marker["target_recovery_record_comment_id"] is None else str(marker["target_recovery_record_comment_id"]),
    }
    expected_targets = {
        "target_successor_issue": expected_target_successor_issue,
        "target_request_id": expected_target_request_id,
        "target_main_sha": expected_target_main_sha,
        "target_profile_id": expected_target_profile_id,
        "target_authority_id": expected_target_authority_id,
        "target_recovery_record_comment_id": expected_target_recovery_record_comment_id,
    }
    for key, expected in expected_targets.items():
        if expected is not None and target_outputs[key] != expected:
            raise AuthorityError(f"JIT recovery target changed: {key}.")

    return {
        "implementation_issue": str(IMPLEMENTATION_ISSUE),
        "authority_issue": str(authority_issue_number),
        "mode": mode,
        "request_id": request_id,
        "main_sha": main_sha,
        "prod_release_sha": prod_release,
        "operation_profile": PROFILE_ID,
        "authorized_actor": AUTHORIZED_ACTOR,
        **target_outputs,
    }


def write_outputs(path: str, outputs: dict[str, str]) -> None:
    with open(path, "a", encoding="utf-8") as handle:
        for key, value in outputs.items():
            if "\n" in value or "\r" in value:
                raise AuthorityError("Multiline GitHub output is forbidden.")
            handle.write(f"{key}={value}\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--issue-json", required=True)
    parser.add_argument("--comments-json", required=True)
    parser.add_argument("--authority-issue-number", required=True, type=int)
    parser.add_argument("--comment-body", required=True)
    parser.add_argument("--comment-author", required=True)
    parser.add_argument("--github-actor", required=True)
    parser.add_argument("--event-name", required=True)
    parser.add_argument("--event-action", required=True)
    parser.add_argument("--run-attempt", required=True)
    parser.add_argument("--live-main", required=True)
    parser.add_argument("--checked-out-head")
    parser.add_argument("--expected-mode")
    parser.add_argument("--expected-request-id")
    parser.add_argument("--expected-profile")
    parser.add_argument("--expected-main")
    parser.add_argument("--expected-target-successor-issue")
    parser.add_argument("--expected-target-request-id")
    parser.add_argument("--expected-target-main-sha")
    parser.add_argument("--expected-target-profile-id")
    parser.add_argument("--expected-target-authority-id")
    parser.add_argument("--expected-target-recovery-record-comment-id")
    parser.add_argument("--github-output")
    args = parser.parse_args()
    outputs = validate(
        issue=_load_json(args.issue_json), comments=_flatten_comments(_load_json(args.comments_json)),
        authority_issue_number=args.authority_issue_number, comment_body=args.comment_body,
        comment_author=args.comment_author, github_actor=args.github_actor,
        event_name=args.event_name, event_action=args.event_action,
        run_attempt=args.run_attempt, live_main=args.live_main,
        checked_out_head=args.checked_out_head, expected_mode=args.expected_mode,
        expected_request_id=args.expected_request_id, expected_profile=args.expected_profile,
        expected_main=args.expected_main,
        expected_target_successor_issue=args.expected_target_successor_issue,
        expected_target_request_id=args.expected_target_request_id,
        expected_target_main_sha=args.expected_target_main_sha,
        expected_target_profile_id=args.expected_target_profile_id,
        expected_target_authority_id=args.expected_target_authority_id,
        expected_target_recovery_record_comment_id=args.expected_target_recovery_record_comment_id,
    )
    if args.github_output:
        write_outputs(args.github_output, outputs)
    for key, value in outputs.items():
        print(f"{key}={value}")
    print("#914_EXECUTION_AUTHORITY=IMPOSSIBLE")
    print("KNOWN_IMPLEMENTATION_CAPABILITY_ISSUES_AS_AUTHORITY=FAIL_CLOSED")
    print("PLAN_APPLY_RECOVERY_SEPARATION=PASS")
    print("REQUEST_REPLAY=FAIL_CLOSED")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AuthorityError, OSError, ValueError, json.JSONDecodeError) as exc:
        print(str(exc), file=__import__("sys").stderr)
        raise SystemExit(80)
