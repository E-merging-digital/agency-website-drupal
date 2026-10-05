#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys
from typing import Mapping

KEY_RE = re.compile(r"^[a-z][a-z0-9_]*$")


def load_contract(path: pathlib.Path) -> dict:
    contract = json.loads(path.read_text(encoding="utf-8"))
    if contract.get("schema_version") != 1:
        raise ValueError("Unsupported APPLY evidence contract schema.")
    if contract.get("contract_id") != "agency-preprod-capability-provision-apply-evidence-v1":
        raise ValueError("Unexpected APPLY evidence contract identity.")
    if contract.get("provisioning_authority_issue") != 861:
        raise ValueError("Unexpected provisioning authority issue.")
    if contract.get("evidence_contract_revision_issue") != 864:
        raise ValueError("Unexpected evidence contract revision issue.")
    if contract.get("operation_profile") != "agency-preprod-capability-provision-v1":
        raise ValueError("Unexpected operation profile.")
    if contract.get("execution_mode") != "APPLY":
        raise ValueError("Unexpected execution mode.")
    if contract.get("metadata_only") is not True:
        raise ValueError("APPLY evidence must remain metadata-only.")
    if contract.get("pii_allowed") is not False or contract.get("secrets_allowed") is not False:
        raise ValueError("PII and secrets must remain forbidden in APPLY evidence.")
    assertions = contract.get("assertions")
    if not isinstance(assertions, dict) or not assertions:
        raise ValueError("APPLY assertions are missing.")
    for key, value in assertions.items():
        if not KEY_RE.fullmatch(key) or not isinstance(value, str) or not value:
            raise ValueError("Invalid APPLY assertion contract entry.")
    return contract


def load_env(path: pathlib.Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for line_number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        if not raw or "=" not in raw:
            raise ValueError(f"Malformed evidence line {line_number}.")
        key, value = raw.split("=", 1)
        if not KEY_RE.fullmatch(key):
            raise ValueError(f"Invalid evidence key on line {line_number}.")
        if key in values:
            raise ValueError(f"Duplicate evidence key: {key}.")
        if not value or any(character in value for character in "\r\n\x00"):
            raise ValueError(f"Invalid evidence value for {key}.")
        values[key] = value
    return values


def validate_dynamic(contract: Mapping, request_id: str, repository_sha: str) -> None:
    dynamic = contract["dynamic_fields"]
    if re.fullmatch(dynamic["request_id"]["regex"], request_id) is None:
        raise ValueError("Invalid APPLY request identity in evidence.")
    if re.fullmatch(dynamic["repository_sha"]["regex"], repository_sha) is None:
        raise ValueError("Invalid repository SHA in evidence.")


def expected_remote(contract: Mapping, request_id: str, repository_sha: str) -> dict[str, str]:
    validate_dynamic(contract, request_id, repository_sha)
    return {
        "request_id": request_id,
        "repository_sha": repository_sha,
        "execution_mode": contract["execution_mode"],
        **contract["assertions"],
    }


def expected_evidence(
    contract: Mapping,
    request_id: str,
    repository_sha: str,
    operation_profile: str,
) -> dict[str, str]:
    validate_dynamic(contract, request_id, repository_sha)
    if operation_profile != contract["operation_profile"]:
        raise ValueError("Unexpected operation profile in evidence.")
    return {
        "schema_version": str(contract["schema_version"]),
        "request_id": request_id,
        "repository_sha": repository_sha,
        "operation_profile": operation_profile,
        "execution_mode": contract["execution_mode"],
        **contract["assertions"],
    }


def require_exact(actual: Mapping[str, str], expected: Mapping[str, str], label: str) -> None:
    actual_keys = set(actual)
    expected_keys = set(expected)
    missing = sorted(expected_keys - actual_keys)
    unexpected = sorted(actual_keys - expected_keys)
    if missing:
        raise ValueError(f"{label} is missing required fields: {', '.join(missing)}.")
    if unexpected:
        raise ValueError(f"{label} contains unexpected fields: {', '.join(unexpected)}.")
    wrong = [key for key in expected if actual[key] != expected[key]]
    if wrong:
        raise ValueError(f"{label} contains wrong values for: {', '.join(wrong)}.")


def validate_remote(args: argparse.Namespace, contract: Mapping) -> dict[str, str]:
    actual = load_env(args.input)
    expected = expected_remote(contract, args.expected_request_id, args.expected_repository_sha)
    require_exact(actual, expected, "Remote APPLY proof")
    return actual


def validate_evidence(args: argparse.Namespace, contract: Mapping) -> dict[str, str]:
    actual = load_env(args.input)
    request_id = args.expected_request_id or actual.get("request_id", "")
    repository_sha = args.expected_repository_sha or actual.get("repository_sha", "")
    operation_profile = args.expected_operation_profile or actual.get("operation_profile", "")
    expected = expected_evidence(contract, request_id, repository_sha, operation_profile)
    require_exact(actual, expected, "Persisted APPLY evidence")
    return actual


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description="Validate canonical metadata-only #861 APPLY evidence.")
    subparsers = result.add_subparsers(dest="command", required=True)
    for command in ("remote", "emit", "evidence"):
        child = subparsers.add_parser(command)
        child.add_argument("--contract", type=pathlib.Path, required=True)
        child.add_argument("--input", type=pathlib.Path, required=True)
        child.add_argument("--expected-request-id")
        child.add_argument("--expected-repository-sha")
        child.add_argument("--expected-operation-profile")
    return result


def main() -> int:
    args = parser().parse_args()
    try:
        contract = load_contract(args.contract)
        if args.command in {"remote", "emit"}:
            if not args.expected_request_id or not args.expected_repository_sha:
                raise ValueError("Remote proof validation requires exact request and repository identities.")
            remote = validate_remote(args, contract)
            if args.command == "remote":
                return 0
            if not args.expected_operation_profile:
                raise ValueError("Evidence emission requires the exact operation profile.")
            evidence = expected_evidence(
                contract,
                remote["request_id"],
                remote["repository_sha"],
                args.expected_operation_profile,
            )
            for key, value in evidence.items():
                print(f"{key}={value}")
            return 0
        validate_evidence(args, contract)
        return 0
    except (OSError, KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
        print(f"APPLY_EVIDENCE_CONTRACT_REJECTED: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
