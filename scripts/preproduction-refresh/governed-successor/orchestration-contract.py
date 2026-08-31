#!/usr/bin/env python3
"""Thin source-only orchestration contract for governed PREPROD refresh #914."""
from __future__ import annotations
import argparse
import hashlib
import importlib.util
import json
import re
from pathlib import Path
from typing import Any

BASE = Path(__file__).resolve().parent
REPO = BASE.parents[2]
PROFILE_PATH = BASE / "profile.json"
CAP_BASE = BASE.parent / "activation-capability"
CAP_PROFILE_PATH = CAP_BASE / "profile.json"
BUNDLE_PATH = CAP_BASE / "bundle.json"
CONTRACT_PATH = CAP_BASE / "transaction_contract.py"
CONTROL_SUDOERS = CAP_BASE / "provisioning/agency-preprod-refresh-control.sudoers"
INGRESS_SUDOERS = CAP_BASE / "provisioning/agency-preprod-refresh-ingress.sudoers"
INSTALLER_SOURCE = CAP_BASE / "agency-preprod-refresh-authority-install"
ABORT_SOURCE = CAP_BASE / "agency-preprod-refresh-authority-abort"
INGRESS_SOURCE = CAP_BASE / "agency-preprod-refresh-ingress"
PROFILE_ID = "agency-preprod-governed-refresh-successor-v1"
CAPABILITY_PROFILE_ID = "agency-preprod-refresh-capability-v1"
RECOVERY_TARGET_PREFIX = "AGENCY_PREPROD_REFRESH_RECOVERY_TARGET="
SHA40 = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")


class OrchestrationError(RuntimeError):
    pass


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise OrchestrationError(f"{path} is not a JSON object.")
    return value


def load_contract():
    spec = importlib.util.spec_from_file_location("agency_refresh_contract", CONTRACT_PATH)
    if spec is None or spec.loader is None:
        raise OrchestrationError("Fixed transaction contract cannot be loaded.")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def verify_repository() -> None:
    profile = load_json(PROFILE_PATH)
    capability = load_json(CAP_PROFILE_PATH)
    bundle = load_json(BUNDLE_PATH)
    if profile.get("issue_number") != 914 or profile.get("parent_issue") != 816 or profile.get("profile_id") != PROFILE_ID:
        raise OrchestrationError("Wrong #914 successor profile identity.")
    auth = profile.get("execution_authority", {})
    if auth.get("implementation_issue_may_execute") is not False or auth.get("authority_issue_must_be_greater_than") != 917:
        raise OrchestrationError("Known capability issues are not fail-closed.")
    if auth.get("modes") != ["PLAN", "APPLY", "RECOVER_ABORT"]:
        raise OrchestrationError("Successor execution mode set is not exact.")
    pre = profile.get("capability_precondition", {})
    if pre.get("revision_lineage") != [915, 917] or pre.get("persistent_data_activation_authority") != "DISABLED":
        raise OrchestrationError("Wrong fixed capability lineage/state.")
    fixed = profile.get("fixed_capability", {})
    expected_paths = {
        "authority_installer": "/usr/local/sbin/agency-preprod-refresh-authority-install",
        "authority_abort": "/usr/local/sbin/agency-preprod-refresh-authority-abort",
        "ingress": "/usr/local/sbin/agency-preprod-refresh-ingress",
        "control": "/usr/local/sbin/agency-preprod-refresh-control",
    }
    for key, expected in expected_paths.items():
        if fixed.get(key) != expected:
            raise OrchestrationError(f"Wrong fixed capability path: {key}.")
    if fixed.get("normal_sudo_authority_installer") != "NONE" or fixed.get("normal_sudo_authority_abort") != "NONE":
        raise OrchestrationError("Installer/abort must remain outside normal sudo.")
    if capability.get("revision_issue") != 915 or capability.get("abort_revision_issue") != 917:
        raise OrchestrationError("Capability source lineage mismatch.")
    if capability.get("data_activation_authority", {}).get("installed_state") != "DISABLED":
        raise OrchestrationError("Persistent activation authority is not disabled in source contract.")
    if bundle.get("revision_issue") != 915 or bundle.get("abort_revision_issue") != 917:
        raise OrchestrationError("Capability bundle lineage mismatch.")
    if bundle.get("normal_sudo_exposure_for_abort") != "NONE":
        raise OrchestrationError("Abort normal sudo exposure changed.")
    for key in ("authority_installer", "authority_abort", "ingress", "helper", "transaction_contract"):
        entry = bundle.get("files", {}).get(key, {})
        expected = entry.get("sha256")
        path_name = entry.get("path")
        if not isinstance(expected, str) or not SHA256.fullmatch(expected) or not isinstance(path_name, str):
            raise OrchestrationError(f"Invalid bundle digest entry: {key}.")
        source = CAP_BASE / path_name
        if sha256(source) != expected:
            raise OrchestrationError(f"Fixed capability source digest mismatch: {key}.")
    sudo_text = CONTROL_SUDOERS.read_text(encoding="utf-8") + "\n" + INGRESS_SUDOERS.read_text(encoding="utf-8")
    if "agency-preprod-refresh-authority-install" in sudo_text or "agency-preprod-refresh-authority-abort" in sudo_text:
        raise OrchestrationError("Root-only authority executables leaked into normal sudo.")
    forbidden = re.compile(r"NOPASSWD:\s*ALL|(?:^|\s)SETENV:|(?:^|\s)(?:bash|sh|python|python3|mariadb|env)(?:\s|$)", re.M)
    if forbidden.search(sudo_text):
        raise OrchestrationError("Generic sudo surface detected.")
    if profile.get("plan", {}).get("mutation") != "NONE" or profile.get("apply", {}).get("prod_write") != "NONE":
        raise OrchestrationError("PLAN/PROD safety boundary changed.")
    recovery = profile.get("recover_abort", {})
    if recovery.get("prod_access") != "NONE" or recovery.get("fixed_helper") != expected_paths["authority_abort"]:
        raise OrchestrationError("Recovery route is broader than fixed #917 abort-only composition.")
    if profile.get("abort_semantics", {}).get("aborted_is_rolled_back") is not False:
        raise OrchestrationError("ABORTED must not be modeled as ROLLED_BACK.")
    installer = INSTALLER_SOURCE.read_text(encoding="utf-8")
    abort = ABORT_SOURCE.read_text(encoding="utf-8")
    if "Active transaction collision/hijack refused." not in installer:
        raise OrchestrationError("Fixed #915 active authority collision protection missing.")
    for token in ("STATE_ABORTED", "Pre-ingress abort absence proof failed.", "Abort replay/already terminal."):
        if token not in abort:
            raise OrchestrationError("Fixed #917 recovery/absence semantics changed.")


def require_issue(value: int) -> int:
    if value <= 917:
        raise OrchestrationError("Successor authority must be a fresh issue > #917.")
    return value


def require_apply_request(issue: int, value: str) -> str:
    if not re.fullmatch(rf"apply-{issue}-[A-Za-z0-9._-]{{8,40}}-r1", value):
        raise OrchestrationError("Invalid historical/fresh APPLY request identity.")
    return value


def require_main(value: str) -> str:
    if not SHA40.fullmatch(value):
        raise OrchestrationError("Invalid main SHA.")
    return value


def require_snapshot(bytes_value: int, digest: str) -> tuple[int, str]:
    if bytes_value < 1 or bytes_value > 1_099_511_627_776:
        raise OrchestrationError("Invalid snapshot byte count.")
    if not SHA256.fullmatch(digest):
        raise OrchestrationError("Invalid snapshot SHA-256.")
    return bytes_value, digest


def authority_envelope(issue: int, request_id: str, main_sha: str, snapshot_bytes: int, snapshot_sha256: str) -> dict[str, Any]:
    require_issue(issue); require_apply_request(issue, request_id); require_main(main_sha); require_snapshot(snapshot_bytes, snapshot_sha256)
    envelope = {
        "schema_version": 1,
        "successor_issue": issue,
        "request_id": request_id,
        "main_sha": main_sha,
        "profile_id": CAPABILITY_PROFILE_ID,
        "allowed_actions": ["IMPORT_SANITIZE_HARDEN_RETAIN", "BACKUP_ACTIVATE_CONVERGE_VALIDATE", "ROLLBACK_RECORDED"],
        "snapshot_bytes": snapshot_bytes,
        "snapshot_sha256": snapshot_sha256,
    }
    load_contract().new_authority_state(envelope)
    return envelope


def authority_id(envelope: dict[str, Any]) -> str:
    return load_contract().authority_id_for(envelope)


def recovery_target_record(issue: int, request_id: str, main_sha: str, snapshot_bytes: int, snapshot_sha256: str) -> dict[str, Any]:
    envelope = authority_envelope(issue, request_id, main_sha, snapshot_bytes, snapshot_sha256)
    return {
        "schema_version": 1,
        "successor_issue": issue,
        "request_id": request_id,
        "main_sha": main_sha,
        "profile_id": CAPABILITY_PROFILE_ID,
        "authority_id": authority_id(envelope),
        "snapshot_bytes": snapshot_bytes,
        "snapshot_sha256": snapshot_sha256,
        "record_is_execution_authority": False,
    }


def validate_recovery_target_record(value: dict[str, Any], *, issue: int, request_id: str, main_sha: str, authority_id_value: str) -> None:
    expected = recovery_target_record(issue, request_id, main_sha, int(value.get("snapshot_bytes", 0)), str(value.get("snapshot_sha256", "")))
    if value != expected or expected["authority_id"] != authority_id_value:
        raise OrchestrationError("Durable recovery target metadata does not exactly bind target transaction.")


def _flatten_comment_objects(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        raise OrchestrationError("Target comments JSON must be an array.")
    out: list[dict[str, Any]] = []
    for item in value:
        if isinstance(item, list):
            out.extend(_flatten_comment_objects(item))
        elif isinstance(item, dict):
            out.append(item)
        else:
            raise OrchestrationError("Target comments JSON contains a non-object.")
    return out


def verify_recovery_target_comments(comments_value: Any, *, issue: int, request_id: str, main_sha: str, authority_id_value: str) -> None:
    require_issue(issue); require_apply_request(issue, request_id); require_main(main_sha)
    if not SHA256.fullmatch(authority_id_value):
        raise OrchestrationError("Invalid target authority id.")
    matches: list[dict[str, Any]] = []
    for comment in _flatten_comment_objects(comments_value):
        body = comment.get("body")
        if not isinstance(body, str):
            continue
        for line in body.splitlines():
            if not line.startswith(RECOVERY_TARGET_PREFIX):
                continue
            raw = line[len(RECOVERY_TARGET_PREFIX):]
            try:
                value = json.loads(raw)
            except json.JSONDecodeError as exc:
                raise OrchestrationError("Durable recovery target metadata JSON invalid.") from exc
            if not isinstance(value, dict) or raw != canonical(value):
                raise OrchestrationError("Durable recovery target metadata is not canonical.")
            if (value.get("successor_issue"), value.get("request_id"), value.get("main_sha"), value.get("authority_id")) == (issue, request_id, main_sha, authority_id_value):
                validate_recovery_target_record(value, issue=issue, request_id=request_id, main_sha=main_sha, authority_id_value=authority_id_value)
                matches.append(value)
    if len(matches) != 1:
        raise OrchestrationError("Exactly one durable metadata-only recovery target record is required.")


def ingress_header(issue: int, request_id: str, main_sha: str, snapshot_bytes: int, snapshot_sha256: str) -> dict[str, Any]:
    envelope = authority_envelope(issue, request_id, main_sha, snapshot_bytes, snapshot_sha256)
    return {
        "successor_issue": envelope["successor_issue"], "request_id": envelope["request_id"],
        "main_sha": envelope["main_sha"], "profile_id": CAPABILITY_PROFILE_ID,
        "expected_bytes": envelope["snapshot_bytes"], "expected_sha256": envelope["snapshot_sha256"],
    }


def operation(action: str, issue: int, request_id: str, main_sha: str) -> dict[str, Any]:
    require_issue(issue); require_apply_request(issue, request_id); require_main(main_sha)
    value = {"action": action, "successor_issue": issue, "request_id": request_id, "main_sha": main_sha, "profile_id": CAPABILITY_PROFILE_ID}
    load_contract().parse_operation(value)
    return value


def abort_request(issue: int, request_id: str, main_sha: str, authority_id_value: str, profile_id: str = CAPABILITY_PROFILE_ID) -> dict[str, Any]:
    require_issue(issue); require_apply_request(issue, request_id); require_main(main_sha)
    if profile_id != CAPABILITY_PROFILE_ID or not SHA256.fullmatch(authority_id_value):
        raise OrchestrationError("Invalid exact abort binding.")
    value = {
        "successor_issue": issue, "request_id": request_id, "main_sha": main_sha,
        "profile_id": profile_id, "authority_id": authority_id_value,
    }
    load_contract().parse_abort_request(value)
    return value


def canonical(value: dict[str, Any]) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"))


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("verify-repository")
    for name in ("authority-envelope", "ingress-header", "recovery-target-record"):
        p = sub.add_parser(name)
        p.add_argument("--issue", type=int, required=True); p.add_argument("--request-id", required=True)
        p.add_argument("--main-sha", required=True); p.add_argument("--snapshot-bytes", type=int, required=True)
        p.add_argument("--snapshot-sha256", required=True)
    p = sub.add_parser("authority-id")
    p.add_argument("--issue", type=int, required=True); p.add_argument("--request-id", required=True)
    p.add_argument("--main-sha", required=True); p.add_argument("--snapshot-bytes", type=int, required=True)
    p.add_argument("--snapshot-sha256", required=True)
    p = sub.add_parser("operation")
    p.add_argument("--action", required=True); p.add_argument("--issue", type=int, required=True)
    p.add_argument("--request-id", required=True); p.add_argument("--main-sha", required=True)
    p = sub.add_parser("abort-request")
    p.add_argument("--issue", type=int, required=True); p.add_argument("--request-id", required=True)
    p.add_argument("--main-sha", required=True); p.add_argument("--authority-id", required=True)
    p.add_argument("--profile-id", default=CAPABILITY_PROFILE_ID)
    p = sub.add_parser("verify-recovery-target-comments")
    p.add_argument("--comments-json", required=True); p.add_argument("--issue", type=int, required=True)
    p.add_argument("--request-id", required=True); p.add_argument("--main-sha", required=True)
    p.add_argument("--authority-id", required=True)
    args = parser.parse_args()
    if args.command == "verify-repository":
        verify_repository()
        print("FIXED_915_917_AUTHORITY_INSTALLER_COMPOSITION=PASS")
        print("FIXED_915_917_INGRESS_COMPOSITION=PASS")
        print("FIXED_917_ABORT_COMPOSITION=PASS")
        print("FIXED_915_917_CONTROL_COMPOSITION=PASS")
        print("FRESH_APPLY_OVER_STALE_ACTIVE=FAIL_CLOSED")
        print("NORMAL_SUDO_ABORT=NONE")
        print("CALLER_GENERIC_EXECUTION=NONE")
        return 0
    if args.command in {"authority-envelope", "ingress-header", "recovery-target-record"}:
        values = (args.issue, args.request_id, args.main_sha, args.snapshot_bytes, args.snapshot_sha256)
        if args.command == "authority-envelope": value = authority_envelope(*values)
        elif args.command == "ingress-header": value = ingress_header(*values)
        else: value = recovery_target_record(*values)
        print(canonical(value)); return 0
    if args.command == "authority-id":
        print(authority_id(authority_envelope(args.issue, args.request_id, args.main_sha, args.snapshot_bytes, args.snapshot_sha256))); return 0
    if args.command == "operation":
        print(canonical(operation(args.action, args.issue, args.request_id, args.main_sha))); return 0
    if args.command == "abort-request":
        print(canonical(abort_request(args.issue, args.request_id, args.main_sha, args.authority_id, args.profile_id))); return 0
    if args.command == "verify-recovery-target-comments":
        verify_recovery_target_comments(json.loads(Path(args.comments_json).read_text(encoding="utf-8")), issue=args.issue, request_id=args.request_id, main_sha=args.main_sha, authority_id_value=args.authority_id)
        print("DURABLE_RECOVERY_TARGET_METADATA=PASS"); return 0
    raise OrchestrationError("Unknown command.")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OrchestrationError, OSError, ValueError, json.JSONDecodeError) as exc:
        print(str(exc), file=__import__("sys").stderr)
        raise SystemExit(80)
