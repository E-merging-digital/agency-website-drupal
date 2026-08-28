#!/usr/bin/env python3
from __future__ import annotations

import json
import pathlib
import subprocess
import tempfile

PROVISIONING = pathlib.Path(__file__).resolve().parent
REPO = pathlib.Path(__file__).resolve().parents[3]
CONTRACT_PATH = PROVISIONING / "apply-evidence-contract.json"
VALIDATOR = PROVISIONING / "validate-apply-evidence.py"
RUN_APPLY = (PROVISIONING / "run-apply.sh").read_text(encoding="utf-8")
REMOTE = (PROVISIONING / "remote-provision-root.sh").read_text(encoding="utf-8")
PROFILE = json.loads((PROVISIONING / "profile.json").read_text(encoding="utf-8"))
WORKFLOW = (REPO / ".github" / "workflows" / "preprod-capability-provisioning.yml").read_text(encoding="utf-8")
CONTRACT = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))

REQUEST_ID = "apply-861-synthetic-evidence-r1"
REPOSITORY_SHA = "a" * 40
PROFILE_ID = "agency-preprod-capability-provision-v1"


def write_env(path: pathlib.Path, values: dict[str, str]) -> None:
    path.write_text("".join(f"{key}={value}\n" for key, value in values.items()), encoding="utf-8")


def run_validator(command: str, path: pathlib.Path, expect_success: bool) -> subprocess.CompletedProcess[str]:
    args = [
        "python3",
        str(VALIDATOR),
        command,
        "--contract",
        str(CONTRACT_PATH),
        "--input",
        str(path),
        "--expected-request-id",
        REQUEST_ID,
        "--expected-repository-sha",
        REPOSITORY_SHA,
    ]
    if command in {"emit", "evidence"}:
        args.extend(["--expected-operation-profile", PROFILE_ID])
    completed = subprocess.run(args, check=False, capture_output=True, text=True)
    if expect_success:
        assert completed.returncode == 0, completed.stderr
    else:
        assert completed.returncode != 0, completed.stdout
        assert "APPLY_EVIDENCE_CONTRACT_REJECTED" in completed.stderr
    return completed


assert CONTRACT["schema_version"] == 1
assert CONTRACT["contract_id"] == "agency-preprod-capability-provision-apply-evidence-v1"
assert CONTRACT["provisioning_authority_issue"] == 861
assert CONTRACT["evidence_contract_revision_issue"] == 864
assert CONTRACT["operation_profile"] == PROFILE_ID
assert CONTRACT["execution_mode"] == "APPLY"
assert CONTRACT["metadata_only"] is True
assert CONTRACT["pii_allowed"] is False
assert CONTRACT["secrets_allowed"] is False

assert PROFILE["evidence"]["apply_contract"] == str(CONTRACT_PATH.relative_to(REPO))
assert PROFILE["evidence"]["apply_contract_id"] == CONTRACT["contract_id"]
assert PROFILE["evidence"]["apply_contract_revision_issue"] == 864
assert PROFILE["evidence"]["apply_validator"] == str(VALIDATOR.relative_to(REPO))

critical_wrong_values = {
    "helper_owner": "nobody",
    "helper_group": "nogroup",
    "helper_mode": "0777",
    "helper_symlink": "YES",
    "helper_digest": "FAIL",
    "sudoers_syntax": "FAIL",
    "sudoers_scope": "NOPASSWD_ALL",
    "staging_db_present_before": "YES",
    "staging_db_present_after": "YES",
    "staging_account_present_after": "YES",
    "preprod_runtime_db_touched": "YES",
    "prod_access": "READ",
}
for field in critical_wrong_values:
    assert field in CONTRACT["assertions"]

for key, value in CONTRACT["assertions"].items():
    assert f"'{key}={value}'" in REMOTE, key

for required in [
    "APPLY_EVIDENCE_CONTRACT='scripts/preproduction-staging-import/provisioning/apply-evidence-contract.json'",
    "APPLY_EVIDENCE_VALIDATOR='scripts/preproduction-staging-import/provisioning/validate-apply-evidence.py'",
    '"$APPLY_EVIDENCE_VALIDATOR" remote',
    '"$APPLY_EVIDENCE_VALIDATOR" emit',
    '"$APPLY_EVIDENCE_VALIDATOR" evidence',
]:
    assert required in RUN_APPLY, required
assert 'cat > "$evidence_dir/evidence.tmp"' not in RUN_APPLY

for required in [
    "validate-apply-evidence.py",
    "apply-evidence-contract.json",
    "--expected-request-id",
    "--expected-repository-sha",
    "--expected-operation-profile",
]:
    assert required in WORKFLOW, required
assert "for required in" not in WORKFLOW

remote_values = {
    "request_id": REQUEST_ID,
    "repository_sha": REPOSITORY_SHA,
    "execution_mode": CONTRACT["execution_mode"],
    **CONTRACT["assertions"],
}

with tempfile.TemporaryDirectory() as directory:
    temp = pathlib.Path(directory)
    remote_path = temp / "remote.env"
    evidence_path = temp / "evidence.env"
    write_env(remote_path, remote_values)
    run_validator("remote", remote_path, True)
    emitted = run_validator("emit", remote_path, True)
    evidence_path.write_text(emitted.stdout, encoding="utf-8")
    run_validator("evidence", evidence_path, True)

    evidence_values = dict(
        line.split("=", 1)
        for line in emitted.stdout.splitlines()
        if line
    )
    expected_evidence_keys = {
        "schema_version",
        "request_id",
        "repository_sha",
        "operation_profile",
        "execution_mode",
        *CONTRACT["assertions"].keys(),
    }
    assert set(evidence_values) == expected_evidence_keys
    assert evidence_values["schema_version"] == "1"
    assert evidence_values["request_id"] == REQUEST_ID
    assert evidence_values["repository_sha"] == REPOSITORY_SHA
    assert evidence_values["operation_profile"] == PROFILE_ID

    for field, wrong_value in critical_wrong_values.items():
        missing = dict(evidence_values)
        del missing[field]
        missing_path = temp / f"missing-{field}.env"
        write_env(missing_path, missing)
        run_validator("evidence", missing_path, False)

        wrong = dict(evidence_values)
        wrong[field] = wrong_value
        wrong_path = temp / f"wrong-{field}.env"
        write_env(wrong_path, wrong)
        run_validator("evidence", wrong_path, False)

    unexpected = dict(evidence_values)
    unexpected["secret_value"] = "forbidden"
    unexpected_path = temp / "unexpected.env"
    write_env(unexpected_path, unexpected)
    run_validator("evidence", unexpected_path, False)

    duplicate_path = temp / "duplicate.env"
    duplicate_path.write_text(emitted.stdout + "helper_owner=root\n", encoding="utf-8")
    run_validator("evidence", duplicate_path, False)

print("APPLY_EVIDENCE_CONTRACT=PASS")
print("SUCCESS_EVIDENCE_GENERATION=PASS")
print("REMOTE_PROOF_TO_PERSISTED_EVIDENCE=PASS")
print("WORKFLOW_USES_AUTHORITATIVE_CONTRACT=PASS")
print("MISSING_CRITICAL_FIELDS_FAIL_CLOSED=PASS")
print("WRONG_CRITICAL_FIELDS_FAIL_CLOSED=PASS")
print("UNEXPECTED_FIELDS_FAIL_CLOSED=PASS")
print("DUPLICATE_FIELDS_FAIL_CLOSED=PASS")
print("METADATA_ONLY=PASS")
print("PII_OR_SECRETS_ALLOWED=NO")
print("REAL_PREPROD_MUTATION=NOT_PERFORMED")
print("REAL_PROD_ACCESS=NONE")
print("APPLY_REPLAY=NOT_PERFORMED")
