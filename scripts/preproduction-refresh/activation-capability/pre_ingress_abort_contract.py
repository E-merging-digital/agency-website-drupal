#!/usr/bin/python3 -I
"""Narrow #917 extension of the #915 transaction state machine.

The existing #915 transaction contract remains authoritative for all active
non-terminal states. This extension adds exactly one terminal state, ABORTED,
valid only as the result of a pre-ingress terminalization from the exact
ARMED/AWAITING_INGRESS state. No I/O is performed here.
"""
from __future__ import annotations

from typing import Any, Mapping
from types import ModuleType

STATE_ABORTED = "ABORTED"
ABORT_REQUEST_KEYS = frozenset({"successor_issue", "request_id", "main_sha", "profile_id", "authority_id"})

class AbortContractError(RuntimeError):
    pass

def parse_abort_request(base: ModuleType, payload: Mapping[str, Any]) -> dict[str, Any]:
    if not isinstance(payload, Mapping) or set(payload) != ABORT_REQUEST_KEYS:
        raise AbortContractError("Abort schema mismatch; path/state/SQL/DB/table/executable/command fields are forbidden.")
    successor_issue = payload.get("successor_issue")
    if isinstance(successor_issue, bool) or not isinstance(successor_issue, int) or successor_issue < 1:
        raise AbortContractError("Invalid successor issue.")
    request_id = payload.get("request_id")
    if not isinstance(request_id, str) or not base.REQUEST_RE.fullmatch(request_id):
        raise AbortContractError("Invalid request identity.")
    main_sha = payload.get("main_sha")
    if not isinstance(main_sha, str) or not base.SHA40_RE.fullmatch(main_sha):
        raise AbortContractError("Invalid main SHA.")
    if payload.get("profile_id") != base.PROFILE_ID:
        raise AbortContractError("Wrong operation profile.")
    authority_id = payload.get("authority_id")
    if not isinstance(authority_id, str) or not base.AUTHORITY_ID_RE.fullmatch(authority_id):
        raise AbortContractError("Invalid authority identity.")
    return {"successor_issue":successor_issue,"request_id":request_id,"main_sha":main_sha,
            "profile_id":base.PROFILE_ID,"authority_id":authority_id}

def _require_exact_binding(state: Mapping[str, Any], request: Mapping[str, Any]) -> None:
    for key in ("successor_issue", "request_id", "main_sha", "profile_id", "authority_id"):
        if state.get(key) != request.get(key):
            raise AbortContractError(f"Abort binding mismatch: {key}.")

def _require_no_activation_metadata(state: Mapping[str, Any]) -> None:
    for key in ("preactivation_runtime_sha256", "application_release_sha256", "backup_sha256", "backup_bytes"):
        if state.get(key) is not None:
            raise AbortContractError(f"Abort metadata proof failed: {key} is non-null.")
    if state.get("human_recovery_required") is not False:
        raise AbortContractError("Abort metadata proof failed: human recovery marker is not false.")

def validate_abort_source(base: ModuleType, authority: Mapping[str, Any], request: Mapping[str, Any]) -> dict[str, Any]:
    try: state = base.validate_authority_state(authority)
    except Exception as exc: raise AbortContractError("Active authority corruption/state validation failed.") from exc
    parsed = parse_abort_request(base, request)
    _require_exact_binding(state, parsed)
    if state["state"] != base.STATE_ARMED or state["phase"] != base.PHASE_AWAITING_INGRESS or state["terminal"] is not False:
        raise AbortContractError("Abort source must be exact ARMED/AWAITING_INGRESS non-terminal state.")
    _require_no_activation_metadata(state)
    return state

def aborted_terminal_state(base: ModuleType, authority: Mapping[str, Any], request: Mapping[str, Any]) -> dict[str, Any]:
    state = validate_abort_source(base, authority, request)
    updated = dict(state)
    updated.update(state=STATE_ABORTED, phase=base.PHASE_TERMINAL, terminal=True)
    return validate_aborted_terminal(base, updated, request)

def validate_aborted_terminal(base: ModuleType, authority: Mapping[str, Any], request: Mapping[str, Any]) -> dict[str, Any]:
    parsed = parse_abort_request(base, request)
    if not isinstance(authority, Mapping) or set(authority) != base.AUTHORITY_KEYS:
        raise AbortContractError("ABORTED authority schema mismatch/corruption.")
    try:
        binding = base.canonical_binding(authority)
        if authority.get("schema_version") != 1:
            raise AbortContractError("Authority schema version mismatch.")
        if authority.get("authority_id") != base.authority_id_for(binding):
            raise AbortContractError("Authority identity digest mismatch.")
    except Exception as exc:
        if isinstance(exc, AbortContractError): raise
        raise AbortContractError("ABORTED canonical binding is invalid.") from exc
    _require_exact_binding(authority, parsed)
    if authority.get("state") != STATE_ABORTED or authority.get("phase") != base.PHASE_TERMINAL or authority.get("terminal") is not True:
        raise AbortContractError("Crash recovery requires exact ABORTED/TERMINAL state.")
    _require_no_activation_metadata(authority)
    return dict(authority)
