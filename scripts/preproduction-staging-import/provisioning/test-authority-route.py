#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import pathlib

BASE = pathlib.Path(__file__).resolve().parent
PROFILE = json.loads((BASE / "profile.json").read_text(encoding="utf-8"))
VALIDATOR_PATH = BASE / "validate-provisioning-authority.py"
SPEC = importlib.util.spec_from_file_location("authority_validator", VALIDATOR_PATH)
assert SPEC and SPEC.loader
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)

MAIN = "75bc8d236cd38f8d524aba13c135ec104f44261f"
PROFILE_ID = "agency-preprod-capability-provision-v1"
OWNER = "E-merging-digital"


def comment(body: str, author: str = OWNER) -> dict[str, object]:
    return {"body": body, "user": {"login": author}}


def command(mode: str, request_id: str, main: str = MAIN, profile: str = PROFILE_ID) -> str:
    return f"/agency-preprod-capability-provision {mode} {request_id} {main} {profile}"


def validate(
    body: str,
    *,
    issue_number: str = "861",
    issue_state: str = "open",
    issue_owner: str = OWNER,
    actor: str = OWNER,
    comment_author: str = OWNER,
    run_attempt: str = "1",
    live_main: str = MAIN,
    event_default_sha: str = MAIN,
    current: list[dict[str, object]] | None = None,
    historical: list[dict[str, object]] | None = None,
):
    if current is None:
        current = [comment(body, comment_author)]
    if historical is None:
        historical = []
    return VALIDATOR.validate_authority(
        profile=PROFILE,
        issue_number=issue_number,
        issue_state=issue_state,
        issue_owner=issue_owner,
        actor=actor,
        comment_author=comment_author,
        run_attempt=run_attempt,
        trigger_comment=body,
        live_main=live_main,
        event_default_sha=event_default_sha,
        current_comments=current,
        historical_comments=historical,
    )


def rejected(body: str, **kwargs) -> None:
    try:
        validate(body, **kwargs)
    except VALIDATOR.AuthorityError:
        return
    raise AssertionError("Authority request unexpectedly accepted")


plan_id = "plan-861-synthetic-route-r1"
apply_id = "apply-861-synthetic-route-r1"
plan = command("plan", plan_id)
apply = command("apply", apply_id)

assert validate(plan)["mode"] == "plan"
assert validate(apply)["mode"] == "apply"
print("ISSUE_861_AUTHORITY_BINDING=PASS")

rejected(plan, issue_number="851")
print("ISSUE_851_CANNOT_TRIGGER_CURRENT_ROUTE=PASS")
print("WRONG_ISSUE_REJECTED=PASS")

rejected(plan, actor="not-owner")
rejected(plan, comment_author="not-owner")
print("WRONG_ACTOR_REJECTED=PASS")

wrong_main = "1" * 40
rejected(command("plan", plan_id, main=wrong_main))
rejected(plan, live_main=wrong_main)
rejected(plan, event_default_sha=wrong_main)
print("WRONG_MAIN_REJECTED=PASS")

rejected(command("plan", plan_id, profile="wrong-profile"))
print("WRONG_PROFILE_REJECTED=PASS")

rejected(plan + " extra")
rejected("/agency-preprod-capability-provision plan")
print("MALFORMED_COMMAND_REJECTED=PASS")

rejected(plan, current=[comment(plan), comment(plan)])
rejected(plan, historical=[comment(plan)])
print("DUPLICATE_REQUEST_REJECTED=PASS")

rejected(plan, run_attempt="2")
print("RERUN_REPLAY_REJECTED=PASS")

rejected(command("apply", plan_id))
rejected(command("plan", apply_id))
print("PLAN_APPLY_AUTHORITY_SEPARATION=PASS")
print("APPLY_REQUIRES_EXPLICIT_SEPARATE_AUTHORITY=PASS")

rejected(plan, issue_state="closed")
rejected(plan, issue_owner="not-owner")

assert PROFILE["authority_lineage"] == {
    "provisioning_contract_origin_issue": 851,
    "capability_revision_issue": 859,
    "current_execution_authority_issue": 861,
}
assert PROFILE["authority"]["execution_authority_issue"] == 861
assert PROFILE["authority"]["historical_request_issues"] == [834, 851]
assert PROFILE["authority"]["plan_authority_does_not_authorize_apply"] is True
assert PROFILE["authority"]["apply_requires_fresh_separate_owner_comment"] is True
assert PROFILE["plan"]["preprod_mutation"] == "NONE"
assert PROFILE["plan"]["helper_execution"] == "NONE"
assert PROFILE["plan"]["sudo_execution"] == "NONE"
assert PROFILE["real_plan_performed_in_issue_861_phase_a"] is False
assert PROFILE["real_provisioning_performed_in_issue_861_phase_a"] is False
print("PLAN_MUTATION_ROUTE=NONE")
print("PLAN_HELPER_EXECUTION=NONE")
print("PLAN_SUDO_EXECUTION=NONE")
print("REAL_REQUEST_ID_CONSUMED=NO")
print("REAL_PREPROD_PLAN_EXECUTION=NOT_PERFORMED")
