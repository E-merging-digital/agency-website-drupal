#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import pathlib
import re
from typing import Any

COMMAND = "/agency-preprod-capability-provision"
REQUEST_RE = re.compile(r"^[A-Za-z0-9._-]{8,80}$")


class AuthorityError(RuntimeError):
    """Raised when a provisioning authority request fails closed."""


def _flatten_comments(value: Any) -> list[dict[str, Any]]:
    comments: list[dict[str, Any]] = []
    if isinstance(value, dict):
        comments.append(value)
    elif isinstance(value, list):
        for item in value:
            comments.extend(_flatten_comments(item))
    return comments


def load_comments(path: pathlib.Path) -> list[dict[str, Any]]:
    return _flatten_comments(json.loads(path.read_text(encoding="utf-8")))


def _request_occurrences(
    comments: list[dict[str, Any]],
    request_id: str,
) -> list[dict[str, Any]]:
    occurrences: list[dict[str, Any]] = []
    for comment in comments:
        body = comment.get("body")
        if not isinstance(body, str) or not body.startswith(COMMAND + " "):
            continue
        parts = body.split()
        if len(parts) >= 3 and parts[2] == request_id:
            occurrences.append(comment)
    return occurrences


def validate_authority(
    *,
    profile: dict[str, Any],
    issue_number: str,
    issue_state: str,
    issue_owner: str,
    actor: str,
    comment_author: str,
    run_attempt: str,
    trigger_comment: str,
    live_main: str,
    event_default_sha: str,
    current_comments: list[dict[str, Any]],
    historical_comments: list[dict[str, Any]],
) -> dict[str, str]:
    lineage = profile.get("authority_lineage", {})
    authority = profile.get("authority", {})
    expected_issue = str(lineage.get("current_execution_authority_issue", ""))
    owner_actor = str(authority.get("owner_actor", ""))
    profile_id = str(profile.get("profile_id", ""))

    if lineage.get("provisioning_contract_origin_issue") != 851:
        raise AuthorityError("Unexpected provisioning contract origin issue.")
    if lineage.get("capability_revision_issue") != 859:
        raise AuthorityError("Unexpected capability revision issue.")
    if lineage.get("current_execution_authority_issue") != 861:
        raise AuthorityError("Unexpected current execution authority issue.")
    if authority.get("execution_authority_issue") != 861:
        raise AuthorityError("Profile execution authority is not #861.")
    if issue_number != expected_issue:
        raise AuthorityError("Wrong provisioning authority issue.")
    if issue_state != "open":
        raise AuthorityError("Provisioning authority issue is not open.")
    if issue_owner != owner_actor or actor != owner_actor:
        raise AuthorityError("Wrong provisioning authority actor.")
    if comment_author != actor:
        raise AuthorityError("Comment author does not equal workflow actor.")
    if run_attempt != "1":
        raise AuthorityError("Provisioning authority cannot be replayed by rerun.")
    if not re.fullmatch(r"[0-9a-f]{40}", live_main):
        raise AuthorityError("Live main identity is malformed.")
    if event_default_sha != live_main:
        raise AuthorityError("Event default SHA is not current live main.")

    parts = trigger_comment.split()
    if len(parts) != 5:
        raise AuthorityError("Malformed provisioning command.")
    command, mode, request_id, requested_main, requested_profile = parts
    if command != COMMAND:
        raise AuthorityError("Wrong provisioning command.")
    if mode not in {"plan", "apply"}:
        raise AuthorityError("Wrong provisioning mode.")
    if not REQUEST_RE.fullmatch(request_id):
        raise AuthorityError("Malformed provisioning request identity.")
    required_prefix = str(authority[f"{mode}_request_prefix"])
    if not request_id.startswith(required_prefix):
        raise AuthorityError("Request identity is not bound to provisioning mode.")
    if requested_main != live_main:
        raise AuthorityError("Requested main is not current live main.")
    if requested_profile != profile_id:
        raise AuthorityError("Wrong provisioning operation profile.")
    expected = f"{COMMAND} {mode} {request_id} {live_main} {profile_id}"
    if trigger_comment != expected:
        raise AuthorityError("Provisioning command is not exact.")

    current = _request_occurrences(current_comments, request_id)
    if len(current) != 1:
        raise AuthorityError("Request identity must occur exactly once on #861.")
    only = current[0]
    if only.get("body") != trigger_comment:
        raise AuthorityError("Current request occurrence is not the trigger comment.")
    if only.get("user", {}).get("login") != owner_actor:
        raise AuthorityError("Current request occurrence is not owner-authored.")
    if _request_occurrences(historical_comments, request_id):
        raise AuthorityError("Request identity was already used by historical authority.")

    return {
        "mode": mode,
        "request_id": request_id,
        "main_sha": live_main,
        "operation_profile": profile_id,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--profile", type=pathlib.Path, required=True)
    parser.add_argument("--current-comments-json", type=pathlib.Path, required=True)
    parser.add_argument(
        "--historical-comments-json",
        type=pathlib.Path,
        action="append",
        default=[],
    )
    args = parser.parse_args()

    profile = json.loads(args.profile.read_text(encoding="utf-8"))
    current_comments = load_comments(args.current_comments_json)
    historical_comments: list[dict[str, Any]] = []
    for path in args.historical_comments_json:
        historical_comments.extend(load_comments(path))

    result = validate_authority(
        profile=profile,
        issue_number=os.environ["ISSUE_NUMBER"],
        issue_state=os.environ["ISSUE_STATE"],
        issue_owner=os.environ["ISSUE_OWNER"],
        actor=os.environ["GITHUB_ACTOR"],
        comment_author=os.environ["COMMENT_AUTHOR"],
        run_attempt=os.environ["GITHUB_RUN_ATTEMPT"],
        trigger_comment=os.environ["TRIGGER_COMMENT"],
        live_main=os.environ["LIVE_MAIN"],
        event_default_sha=os.environ["EVENT_DEFAULT_SHA"],
        current_comments=current_comments,
        historical_comments=historical_comments,
    )
    for key in ("mode", "request_id", "main_sha", "operation_profile"):
        print(f"{key}={result[key]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
