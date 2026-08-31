#!/usr/bin/python3 -I
"""Shared fixed transaction contract for Agency PREPROD refresh capability (#915/#917).

This module contains validation/derivation only. It has no CLI and performs no
I/O. Root-owned executables pin this file by SHA-256 before importing it.
"""
from __future__ import annotations

import hashlib
import json
import re
from typing import Any, Mapping

PROFILE_ID = "agency-preprod-refresh-capability-v1"
MAX_SNAPSHOT_BYTES = 1_099_511_627_776
REQUEST_RE = re.compile(r"^[A-Za-z0-9._-]{8,80}$")
SHA40_RE = re.compile(r"^[0-9a-f]{40}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
AUTHORITY_ID_RE = re.compile(r"^[0-9a-f]{64}$")

IMPORT = "IMPORT_SANITIZE_HARDEN_RETAIN"
ACTIVATE = "BACKUP_ACTIVATE_CONVERGE_VALIDATE"
ROLLBACK = "ROLLBACK_RECORDED"
MUTATING_ACTIONS = (IMPORT, ACTIVATE, ROLLBACK)
ALLOWED_ACTION_SET = frozenset(MUTATING_ACTIONS)

STATE_ARMED = "ARMED"
STATE_IN_PROGRESS = "IN_PROGRESS"
STATE_COMMITTED = "COMMITTED"
STATE_ROLLED_BACK = "ROLLED_BACK"
STATE_ABORTED = "ABORTED"
STATE_FAILED_RECOVERY = "FAILED_RECOVERY"
TERMINAL_STATES = frozenset({
    STATE_COMMITTED,
    STATE_ROLLED_BACK,
    STATE_ABORTED,
    STATE_FAILED_RECOVERY,
})

PHASE_AWAITING_INGRESS = "AWAITING_INGRESS"
PHASE_SNAPSHOT_READY = "SNAPSHOT_READY"
PHASE_IMPORTING = "IMPORTING"
PHASE_CANDIDATE_SEALED = "CANDIDATE_SEALED"
PHASE_FENCE_CLOSED = "FENCE_CLOSED"
PHASE_BACKUP_VERIFIED = "BACKUP_VERIFIED"
PHASE_SWAP_ATTEMPTED = "SWAP_ATTEMPTED"
PHASE_SWAPPED = "SWAPPED"
PHASE_CONVERGENCE_STARTED = "CONVERGENCE_STARTED"
PHASE_RUNTIME_VALIDATED = "RUNTIME_VALIDATED"
PHASE_ROLLBACK_STARTED = "ROLLBACK_STARTED"
PHASE_TERMINAL = "TERMINAL"

ACTION_PHASES = {
    IMPORT: frozenset({PHASE_SNAPSHOT_READY}),
    ACTIVATE: frozenset({PHASE_CANDIDATE_SEALED}),
    ROLLBACK: frozenset({
        PHASE_FENCE_CLOSED,
        PHASE_BACKUP_VERIFIED,
        PHASE_SWAP_ATTEMPTED,
        PHASE_SWAPPED,
        PHASE_CONVERGENCE_STARTED,
        PHASE_RUNTIME_VALIDATED,
        PHASE_ROLLBACK_STARTED,
    }),
}

AUTHORITY_KEYS = frozenset({
    "schema_version",
    "authority_id",
    "successor_issue",
    "request_id",
    "main_sha",
    "profile_id",
    "allowed_actions",
    "snapshot_bytes",
    "snapshot_sha256",
    "state",
    "phase",
    "terminal",
    "preactivation_runtime_sha256",
    "application_release_sha256",
    "backup_sha256",
    "backup_bytes",
    "human_recovery_required",
})

ENVELOPE_KEYS = frozenset({
    "schema_version",
    "successor_issue",
    "request_id",
    "main_sha",
    "profile_id",
    "allowed_actions",
    "snapshot_bytes",
    "snapshot_sha256",
})

OPERATION_KEYS = frozenset({
    "action",
    "successor_issue",
    "request_id",
    "main_sha",
    "profile_id",
})

INGRESS_HEADER_KEYS = frozenset({
    "successor_issue",
    "request_id",
    "main_sha",
    "profile_id",
    "expected_bytes",
    "expected_sha256",
})

ABORT_REQUEST_KEYS = frozenset({
    "successor_issue",
    "request_id",
    "main_sha",
    "profile_id",
    "authority_id",
})


class ContractError(RuntimeError):
    """Fail-closed transaction contract violation."""


def _require_int(value: Any, *, minimum: int, name: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < minimum:
        raise ContractError(f"Invalid {name}.")
    return value


def _require_str(value: Any, pattern: re.Pattern[str], name: str) -> str:
    if not isinstance(value, str) or not pattern.fullmatch(value):
        raise ContractError(f"Invalid {name}.")
    return value


def canonical_binding(payload: Mapping[str, Any]) -> dict[str, Any]:
    actions = payload.get("allowed_actions")
    if not isinstance(actions, list) or actions != list(MUTATING_ACTIONS):
        raise ContractError("Allowed action set must be the exact fixed transaction action sequence.")
    successor_issue = _require_int(payload.get("successor_issue"), minimum=1, name="successor issue")
    if successor_issue == 915:
        raise ContractError("Implementation issue #915 cannot self-authorize execution.")
    request_id = _require_str(payload.get("request_id"), REQUEST_RE, "request identity")
    main_sha = _require_str(payload.get("main_sha"), SHA40_RE, "main SHA")
    profile_id = payload.get("profile_id")
    if profile_id != PROFILE_ID:
        raise ContractError("Wrong operation profile.")
    snapshot_bytes = _require_int(payload.get("snapshot_bytes"), minimum=1, name="snapshot byte count")
    if snapshot_bytes > MAX_SNAPSHOT_BYTES:
        raise ContractError("Snapshot byte count exceeds fixed maximum.")
    snapshot_sha256 = _require_str(payload.get("snapshot_sha256"), SHA256_RE, "snapshot SHA-256")
    return {
        "schema_version": 1,
        "successor_issue": successor_issue,
        "request_id": request_id,
        "main_sha": main_sha,
        "profile_id": profile_id,
        "allowed_actions": list(MUTATING_ACTIONS),
        "snapshot_bytes": snapshot_bytes,
        "snapshot_sha256": snapshot_sha256,
    }


def authority_id_for(binding: Mapping[str, Any]) -> str:
    canonical = canonical_binding(binding)
    raw = json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def new_authority_state(envelope: Mapping[str, Any]) -> dict[str, Any]:
    if set(envelope) != ENVELOPE_KEYS:
        raise ContractError("Authority envelope schema mismatch.")
    binding = canonical_binding(envelope)
    return {
        **binding,
        "authority_id": authority_id_for(binding),
        "state": STATE_ARMED,
        "phase": PHASE_AWAITING_INGRESS,
        "terminal": False,
        "preactivation_runtime_sha256": None,
        "application_release_sha256": None,
        "backup_sha256": None,
        "backup_bytes": None,
        "human_recovery_required": False,
    }


def validate_authority_state(state: Mapping[str, Any]) -> dict[str, Any]:
    if not isinstance(state, Mapping) or set(state) != AUTHORITY_KEYS:
        raise ContractError("Authority state schema mismatch or corruption.")
    binding = canonical_binding(state)
    if state.get("schema_version") != 1:
        raise ContractError("Authority schema version mismatch.")
    authority_id = _require_str(state.get("authority_id"), AUTHORITY_ID_RE, "authority identity")
    if authority_id != authority_id_for(binding):
        raise ContractError("Authority identity digest mismatch.")
    state_name = state.get("state")
    if state_name not in {STATE_ARMED, STATE_IN_PROGRESS, *TERMINAL_STATES}:
        raise ContractError("Unknown authority state.")
    phase = state.get("phase")
    known_phases = {
        PHASE_AWAITING_INGRESS, PHASE_SNAPSHOT_READY, PHASE_IMPORTING,
        PHASE_CANDIDATE_SEALED, PHASE_FENCE_CLOSED, PHASE_BACKUP_VERIFIED,
        PHASE_SWAP_ATTEMPTED, PHASE_SWAPPED, PHASE_CONVERGENCE_STARTED,
        PHASE_RUNTIME_VALIDATED, PHASE_ROLLBACK_STARTED, PHASE_TERMINAL,
    }
    if phase not in known_phases:
        raise ContractError("Unknown authority phase.")
    terminal = state.get("terminal")
    if not isinstance(terminal, bool):
        raise ContractError("Authority terminal marker invalid.")
    if terminal != (state_name in TERMINAL_STATES):
        raise ContractError("Authority terminal/state mismatch.")
    if terminal and phase != PHASE_TERMINAL:
        raise ContractError("Terminal authority must use TERMINAL phase.")
    if not terminal and phase == PHASE_TERMINAL:
        raise ContractError("Non-terminal authority cannot use TERMINAL phase.")
    for key in ("preactivation_runtime_sha256", "application_release_sha256", "backup_sha256"):
        value = state.get(key)
        if value is not None:
            _require_str(value, SHA256_RE, key)
    backup_bytes = state.get("backup_bytes")
    if backup_bytes is not None:
        _require_int(backup_bytes, minimum=1, name="backup byte count")
    if not isinstance(state.get("human_recovery_required"), bool):
        raise ContractError("Recovery marker invalid.")
    return dict(state)


def parse_operation(payload: Mapping[str, Any]) -> dict[str, Any]:
    if not isinstance(payload, Mapping) or set(payload) != OPERATION_KEYS:
        raise ContractError("Operation schema mismatch; SQL/path/DB/table/executable/authority fields are forbidden.")
    action = payload.get("action")
    if action not in ALLOWED_ACTION_SET:
        raise ContractError("Unknown fixed action.")
    successor_issue = _require_int(payload.get("successor_issue"), minimum=1, name="successor issue")
    request_id = _require_str(payload.get("request_id"), REQUEST_RE, "request identity")
    main_sha = _require_str(payload.get("main_sha"), SHA40_RE, "main SHA")
    if payload.get("profile_id") != PROFILE_ID:
        raise ContractError("Wrong operation profile.")
    return {
        "action": action,
        "successor_issue": successor_issue,
        "request_id": request_id,
        "main_sha": main_sha,
        "profile_id": PROFILE_ID,
    }


def validate_operation_binding(authority: Mapping[str, Any], operation: Mapping[str, Any]) -> None:
    state = validate_authority_state(authority)
    op = parse_operation(operation)
    if state["terminal"]:
        raise ContractError("Terminal authority cannot be reused.")
    for key in ("successor_issue", "request_id", "main_sha", "profile_id"):
        if state[key] != op[key]:
            raise ContractError(f"Transaction binding mismatch: {key}.")
    if op["action"] not in state["allowed_actions"]:
        raise ContractError("Action is not authorized for this transaction.")
    if state["phase"] not in ACTION_PHASES[op["action"]]:
        raise ContractError("Action is not valid in the current transaction phase.")


def rollback_strategy(phase: str) -> str:
    if phase in {PHASE_FENCE_CLOSED, PHASE_BACKUP_VERIFIED, PHASE_SWAP_ATTEMPTED}:
        return "VERIFY_UNCHANGED_OR_EXACT_BACKUP"
    if phase == PHASE_SWAPPED:
        return "REVERSE_ATOMIC_SWAP_THEN_EXACT_BACKUP_FALLBACK"
    if phase in {PHASE_CONVERGENCE_STARTED, PHASE_RUNTIME_VALIDATED, PHASE_ROLLBACK_STARTED}:
        return "EXACT_PREACTIVATION_BACKUP"
    raise ContractError("Phase is not rollback-capable.")


def parse_ingress_header(payload: Mapping[str, Any]) -> dict[str, Any]:
    if not isinstance(payload, Mapping) or set(payload) != INGRESS_HEADER_KEYS:
        raise ContractError("Ingress header schema mismatch; caller path/filename/command fields are forbidden.")
    successor_issue = _require_int(payload.get("successor_issue"), minimum=1, name="successor issue")
    request_id = _require_str(payload.get("request_id"), REQUEST_RE, "request identity")
    main_sha = _require_str(payload.get("main_sha"), SHA40_RE, "main SHA")
    if payload.get("profile_id") != PROFILE_ID:
        raise ContractError("Wrong operation profile.")
    expected_bytes = _require_int(payload.get("expected_bytes"), minimum=1, name="snapshot byte count")
    if expected_bytes > MAX_SNAPSHOT_BYTES:
        raise ContractError("Snapshot byte count exceeds fixed maximum.")
    expected_sha256 = _require_str(payload.get("expected_sha256"), SHA256_RE, "snapshot SHA-256")
    return {
        "successor_issue": successor_issue,
        "request_id": request_id,
        "main_sha": main_sha,
        "profile_id": PROFILE_ID,
        "expected_bytes": expected_bytes,
        "expected_sha256": expected_sha256,
    }


def validate_ingress_binding(authority: Mapping[str, Any], header: Mapping[str, Any]) -> None:
    state = validate_authority_state(authority)
    parsed = parse_ingress_header(header)
    if state["terminal"] or state["state"] != STATE_ARMED or state["phase"] != PHASE_AWAITING_INGRESS:
        raise ContractError("Ingress requires one fresh ARMED transaction awaiting ingress.")
    for key in ("successor_issue", "request_id", "main_sha", "profile_id"):
        if state[key] != parsed[key]:
            raise ContractError(f"Ingress binding mismatch: {key}.")
    if state["snapshot_bytes"] != parsed["expected_bytes"]:
        raise ContractError("Ingress byte-count binding mismatch.")
    if state["snapshot_sha256"] != parsed["expected_sha256"]:
        raise ContractError("Ingress SHA-256 binding mismatch.")
    if IMPORT not in state["allowed_actions"]:
        raise ContractError("Ingress transaction does not authorize import.")


def parse_abort_request(payload: Mapping[str, Any]) -> dict[str, Any]:
    if not isinstance(payload, Mapping) or set(payload) != ABORT_REQUEST_KEYS:
        raise ContractError("Abort schema mismatch; caller path/state/command fields are forbidden.")
    successor_issue = _require_int(payload.get("successor_issue"), minimum=1, name="successor issue")
    request_id = _require_str(payload.get("request_id"), REQUEST_RE, "request identity")
    main_sha = _require_str(payload.get("main_sha"), SHA40_RE, "main SHA")
    if payload.get("profile_id") != PROFILE_ID:
        raise ContractError("Wrong operation profile.")
    authority_id = _require_str(payload.get("authority_id"), AUTHORITY_ID_RE, "authority identity")
    return {
        "successor_issue": successor_issue,
        "request_id": request_id,
        "main_sha": main_sha,
        "profile_id": PROFILE_ID,
        "authority_id": authority_id,
    }


def validate_pre_ingress_abort_binding(
    authority: Mapping[str, Any],
    abort_request: Mapping[str, Any],
) -> tuple[dict[str, Any], dict[str, Any]]:
    state = validate_authority_state(authority)
    request = parse_abort_request(abort_request)
    if state["terminal"]:
        raise ContractError("Terminal authority cannot be aborted.")
    if state["state"] != STATE_ARMED or state["phase"] != PHASE_AWAITING_INGRESS:
        raise ContractError("Pre-ingress abort requires ARMED/AWAITING_INGRESS.")
    for key in ("successor_issue", "request_id", "main_sha", "profile_id", "authority_id"):
        if state[key] != request[key]:
            raise ContractError(f"Abort binding mismatch: {key}.")
    for key in (
        "preactivation_runtime_sha256",
        "application_release_sha256",
        "backup_sha256",
        "backup_bytes",
    ):
        if state[key] is not None:
            raise ContractError(f"Abort activation metadata must remain null: {key}.")
    if state["human_recovery_required"] is not False:
        raise ContractError("Abort recovery marker must remain false.")
    return state, request


def pre_ingress_aborted_state(
    authority: Mapping[str, Any],
    abort_request: Mapping[str, Any],
) -> dict[str, Any]:
    state, _ = validate_pre_ingress_abort_binding(authority, abort_request)
    terminal = dict(state)
    terminal.update(
        state=STATE_ABORTED,
        phase=PHASE_TERMINAL,
        terminal=True,
        human_recovery_required=False,
    )
    return validate_authority_state(terminal)


def validate_aborted_terminal_binding(
    authority: Mapping[str, Any],
    abort_request: Mapping[str, Any],
) -> tuple[dict[str, Any], dict[str, Any]]:
    state = validate_authority_state(authority)
    request = parse_abort_request(abort_request)
    if state["state"] != STATE_ABORTED or state["phase"] != PHASE_TERMINAL or state["terminal"] is not True:
        raise ContractError("Crash recovery requires exact ABORTED/TERMINAL state.")
    for key in ("successor_issue", "request_id", "main_sha", "profile_id", "authority_id"):
        if state[key] != request[key]:
            raise ContractError(f"Aborted recovery binding mismatch: {key}.")
    for key in (
        "preactivation_runtime_sha256",
        "application_release_sha256",
        "backup_sha256",
        "backup_bytes",
    ):
        if state[key] is not None:
            raise ContractError(f"Aborted terminal activation metadata must remain null: {key}.")
    if state["human_recovery_required"] is not False:
        raise ContractError("Aborted terminal recovery marker must remain false.")
    return state, request


def spool_basename(authority: Mapping[str, Any]) -> str:
    state = validate_authority_state(authority)
    return state["authority_id"] + ".sql"


def manifest_basename(authority: Mapping[str, Any]) -> str:
    state = validate_authority_state(authority)
    return state["authority_id"] + ".json"


def derived_db_names(authority: Mapping[str, Any]) -> tuple[str, str, str]:
    state = validate_authority_state(authority)
    seed = state["authority_id"]
    return (
        "agency_preprod_stage_" + hashlib.sha256(("stage:" + seed).encode()).hexdigest()[:12],
        "agency_preprod_candidate_" + hashlib.sha256(("candidate:" + seed).encode()).hexdigest()[:12],
        "agency_preprod_rollback_" + hashlib.sha256(("rollback:" + seed).encode()).hexdigest()[:12],
    )
