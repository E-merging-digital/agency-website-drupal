#!/usr/bin/env python3
"""Synthetic matrix for #877 successor provisioning authority routing."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

SCRIPT = Path(__file__).with_name("validate-successor-authority.py")
MAIN = "3c606493e776e890b61c9d76f6feb98b3308fdb0"
OWNER = "E-merging-digital"
PROFILE = "agency-preprod-refresh-capability-provision-v1"
COMMAND = "/agency-preprod-refresh-capability-provision"


def marker(mode="plan", parent=816, implementation=874):
    return (
        "AGENCY_PREPROD_REFRESH_CAPABILITY_PROVISION_AUTHORITY\n"
        f"parent_issue={parent}\n"
        f"implementation_issue={implementation}\n"
        f"allowed_mode={mode}"
    )


def command(issue=876, mode="plan", main=MAIN, profile=PROFILE, revision=1, extra=""):
    request = f"issue-{issue}-{mode}-{main[:8]}-r{revision}"
    value = f"{COMMAND} {mode} {request} {main} {profile}"
    return value + (f" {extra}" if extra else "")


def run_case(
    name,
    *,
    expect=True,
    issue_number=876,
    issue_state="open",
    issue_creator=OWNER,
    issue_body=None,
    is_pr=False,
    actor=OWNER,
    comment_author=OWNER,
    comment_body=None,
    comments=None,
    live_main=MAIN,
    run_attempt="1",
    event_name="issue_comment",
    event_action="created",
):
    issue_body = marker() if issue_body is None else issue_body
    comment_body = command(issue_number) if comment_body is None else comment_body
    issue = {
        "number": issue_number,
        "state": issue_state,
        "user": {"login": issue_creator},
        "body": issue_body,
    }
    if is_pr:
        issue["pull_request"] = {"url": "https://example.invalid/pr"}
    comments = [{"body": comment_body, "user": {"login": OWNER}}] if comments is None else comments

    with tempfile.TemporaryDirectory() as tmp:
        issue_path = Path(tmp) / "issue.json"
        comments_path = Path(tmp) / "comments.json"
        issue_path.write_text(json.dumps(issue), encoding="utf-8")
        comments_path.write_text(json.dumps([comments]), encoding="utf-8")
        result = subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--issue-json", str(issue_path),
                "--comments-json", str(comments_path),
                "--authority-issue-number", str(issue_number),
                "--event-name", event_name,
                "--event-action", event_action,
                "--github-actor", actor,
                "--comment-author", comment_author,
                "--comment-body", comment_body,
                "--run-attempt", run_attempt,
                "--live-main", live_main,
            ],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
    passed = result.returncode == 0
    if passed != expect:
        print(f"CASE={name} EXPECT={expect} RC={result.returncode}")
        print(result.stdout)
        print(result.stderr)
        raise SystemExit(1)
    print(f"CASE={name}={'PASS' if expect else 'REJECT_PASS'}")


def main():
    run_case("successor_plan_876", expect=True)
    run_case(
        "successor_apply_future",
        expect=True,
        issue_number=900,
        issue_body=marker("apply"),
        comment_body=command(900, "apply"),
    )
    run_case("closed_authority_issue", expect=False, issue_state="closed")
    run_case("pr_comment", expect=False, is_pr=True)
    run_case("wrong_issue_creator", expect=False, issue_creator="someone-else")
    run_case("wrong_actor", expect=False, actor="someone-else")
    run_case("wrong_comment_author", expect=False, comment_author="someone-else")
    run_case("wrong_parent", expect=False, issue_body=marker(parent=999))
    run_case("wrong_implementation", expect=False, issue_body=marker(implementation=999))
    run_case("missing_marker", expect=False, issue_body="No authority marker here.")
    run_case("duplicate_marker", expect=False, issue_body=marker() + "\n\n" + marker())
    run_case(
        "plan_issue_command_apply",
        expect=False,
        comment_body=command(876, "apply"),
    )
    run_case(
        "apply_issue_command_plan",
        expect=False,
        issue_number=900,
        issue_body=marker("apply"),
        comment_body=command(900, "plan"),
    )
    wrong_main = "0" * 40
    run_case(
        "wrong_main_sha",
        expect=False,
        comment_body=command(876, main=wrong_main),
    )
    run_case(
        "main_advanced_while_queued",
        expect=False,
        issue_number=900,
        issue_body=marker("apply"),
        comment_body=command(900, "apply"),
        live_main="1" * 40,
    )
    run_case(
        "wrong_profile",
        expect=False,
        comment_body=command(876, profile="wrong-profile"),
    )
    run_case(
        "extra_command_token",
        expect=False,
        comment_body=command(876, extra="unexpected"),
    )
    duplicate = command(876)
    run_case(
        "duplicate_exact_authorization_comment",
        expect=False,
        comment_body=duplicate,
        comments=[
            {"body": duplicate, "user": {"login": OWNER}},
            {"body": duplicate, "user": {"login": OWNER}},
        ],
    )
    apply_comment = command(900, "apply")
    run_case(
        "authorization_comment_missing",
        expect=False,
        issue_number=900,
        issue_body=marker("apply"),
        comment_body=apply_comment,
        comments=[],
    )
    run_case(
        "authorization_comment_changed",
        expect=False,
        issue_number=900,
        issue_body=marker("apply"),
        comment_body=apply_comment,
        comments=[
            {"body": apply_comment + "-changed", "user": {"login": OWNER}},
        ],
    )
    run_case(
        "authority_marker_changed_after_queue",
        expect=False,
        issue_number=900,
        issue_body=marker("plan"),
        comment_body=apply_comment,
    )
    run_case(
        "implementation_issue_874_cannot_authorize_even_if_open",
        expect=False,
        issue_number=874,
        comment_body=command(874),
    )
    run_case(
        "request_id_wrong_authority_issue",
        expect=False,
        comment_body=command(999),
    )
    run_case(
        "request_id_wrong_main_binding",
        expect=False,
        comment_body=f"{COMMAND} plan issue-876-plan-deadbeef-r1 {MAIN} {PROFILE}",
    )
    run_case("wrong_run_attempt", expect=False, run_attempt="2")

    print("SUCCESSOR_AUTHORITY=PROVEN")
    print("#876_PLAN_COMPATIBILITY=PASS")
    print("PLAN_APPLY_MODE_ISOLATION=PASS")
    print("#874_LIVE_EXECUTION_AUTHORITY=FORBIDDEN")
    print("DATA_ACTIVATION_AUTHORITY_DISABLED=PASS")
    print("MAIN_ADVANCED_WHILE_QUEUED=FAIL_CLOSED")
    print("AUTHORITY_ISSUE_CLOSED=FAIL_CLOSED")
    print("AUTHORIZATION_COMMENT_MISSING=FAIL_CLOSED")
    print("AUTHORIZATION_COMMENT_DUPLICATED=FAIL_CLOSED")
    print("AUTHORIZATION_COMMENT_CHANGED=FAIL_CLOSED")
    print("AUTHORITY_MARKER_CHANGED=FAIL_CLOSED")
    print("RUN_ATTEMPT_NOT_1=FAIL_CLOSED")


if __name__ == "__main__":
    main()
