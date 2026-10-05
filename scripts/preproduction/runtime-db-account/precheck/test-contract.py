#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import stat
import subprocess
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = Path(__file__).resolve().parents[4]
REMOTE = HERE / "remote-precheck-root.py"
RUNNER = HERE / "run-precheck.sh"
PROFILE = HERE / "profile.json"
WORKFLOW = REPO / ".github" / "workflows" / "preprod-895-runtime-db-precheck.yml"
HELPER = REPO / "scripts" / "preproduction" / "runtime-db-account" / "agency-preprod-runtime-db-account"
MANIFEST = REPO / "scripts" / "preproduction" / "runtime-db-account" / "capability.json"

SPEC = importlib.util.spec_from_file_location("remote_895", REMOTE)
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

remote_source = REMOTE.read_text(encoding="utf-8")
runner_source = RUNNER.read_text(encoding="utf-8")
workflow_source = WORKFLOW.read_text(encoding="utf-8")
profile = json.loads(PROFILE.read_text(encoding="utf-8"))
manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
helper_source = HELPER.read_text(encoding="utf-8")

route_source = "\n".join((remote_source, runner_source, workflow_source))

assert profile["issue_number"] == 895
assert profile["implementation_only"] is True
assert profile["future_execution"]["action"] == "PRECHECK"
assert profile["future_execution"]["runner_labels"] == [
    "self-hosted", "linux", "x64", "agency"
]
assert profile["future_execution"]["runner_name"] == "agency-browser-runner-01"
assert profile["future_execution"]["sudoers_install"] == "NONE"
assert profile["future_execution"]["deploy_user_privilege_widening"] == "NONE"
assert profile["evidence"]["mode"] == "METADATA_ONLY"
assert profile["evidence"]["runtime_env_read"] == "NONE"
assert profile["evidence"]["db_password_read"] == "NONE"
assert profile["boundaries"]["data_activation_authority"] == "DISABLED"

assert manifest["installation"]["helper"]["path"] == str(mod.HELPER_PATH)
assert manifest["installation"]["helper"]["mode"] == "0755"
assert manifest["installation"]["helper"]["owner"] == "root"
assert manifest["installation"]["helper"]["group"] == "root"
assert manifest["installation"]["helper"]["sha256"] == hashlib.sha256(
    HELPER.read_bytes()
).hexdigest()

assert 'runs-on: [self-hosted, linux, x64, agency]' in workflow_source
assert 'PREPROD_PROVISIONING_SSH_PRIVATE_KEY' in workflow_source
assert "agency-browser-runner-01" in workflow_source
assert "manage-known-host.sh PROVISION" in workflow_source
assert "verify-preprod-pinned-trust.sh" in workflow_source
assert "StrictHostKeyChecking=no" not in route_source
assert "ssh-keyscan" not in route_source
assert "/etc/sudoers.d" not in route_source
assert "agency-preprod-runtime-db-account.sudoers" not in route_source
assert "DB_PASSWORD" not in route_source
assert "runtime.env" not in route_source
assert "/usr/bin/mariadb" not in route_source
for statement in (
    "CREATE USER",
    "ALTER USER",
    "DROP USER",
    "GRANT ALL",
    "REVOKE ALL",
):
    assert statement not in route_source
assert '"PRECHECK"' in remote_source
assert '"APPLY"' not in remote_source
assert '"VERIFY"' not in remote_source
assert " APPLY " not in runner_source
assert " VERIFY " not in runner_source
assert "ACTION=" not in runner_source
assert "shell=True" not in remote_source
assert "os.system(" not in remote_source
assert "eval(" not in route_source
assert "exec(" not in route_source
assert "StrictHostKeyChecking=yes" in runner_source
assert "UserKnownHostsFile=" in runner_source
assert "test ! -e '$FIXED_HELPER' && test ! -L '$FIXED_HELPER'" in runner_source
assert "os.link(tmp_path, helper_path)" in remote_source
assert "Fixed helper path pre-exists; no overwrite is allowed." in remote_source
assert "remove_owned_helper(helper_path, identity)" in remote_source
assert "if len(sys.argv) != 1:" in remote_source

fields = tuple(manifest["observation"]["fields"])
assert fields == mod.FIELDS

exact_raw = "\n".join(
    (
        "TARGET_DATABASE_PRESENT=YES",
        "ACCOUNT_127_PRESENT=YES",
        "ACCOUNT_LOCALHOST_PRESENT=NO",
        "EXPECTED_DB_GRANT=EXACT",
        "RUNTIME_ACCOUNT_STATE=EXACT",
    )
) + "\n"
assert mod.parse_metadata(exact_raw, 0)["RUNTIME_ACCOUNT_STATE"] == "EXACT"

failed_raw = "\n".join(
    (
        "TARGET_DATABASE_PRESENT=UNKNOWN_FAIL_CLOSED",
        "ACCOUNT_127_PRESENT=UNKNOWN_FAIL_CLOSED",
        "ACCOUNT_LOCALHOST_PRESENT=UNKNOWN_FAIL_CLOSED",
        "EXPECTED_DB_GRANT=UNKNOWN_FAIL_CLOSED",
        "RUNTIME_ACCOUNT_STATE=UNSAFE",
    )
) + "\n"
assert mod.parse_metadata(failed_raw, 1)["RUNTIME_ACCOUNT_STATE"] == "UNSAFE"

try:
    mod.parse_metadata(exact_raw + "RAW=forbidden\n", 0)
except mod.ContractError:
    pass
else:
    raise AssertionError("unexpected metadata line was accepted")


def make_manifest(path: Path, helper_path: Path, digest: str) -> None:
    data = json.loads(json.dumps(manifest))
    data["installation"]["helper"]["sha256"] = digest
    path.write_text(json.dumps(data), encoding="utf-8")
    os.chmod(path, 0o600)


def make_helper(path: Path, exit_code: int = 0, failed: bool = False) -> None:
    if failed:
        lines = failed_raw
    else:
        lines = exact_raw
    path.write_text(
        "#!/usr/bin/env python3\n"
        "import sys\n"
        "assert len(sys.argv) == 2 and sys.argv[1] == 'PRECHECK'\n"
        f"sys.stdout.write({lines!r})\n"
        f"raise SystemExit({exit_code})\n",
        encoding="utf-8",
    )
    os.chmod(path, 0o700)


uid = os.geteuid()
gid = os.getegid()
with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    capability = root / "capability.json"
    destination = root / "installed-helper"
    make_helper(source)
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    make_manifest(capability, destination, digest)

    observed_mode = None

    def inspect_runner(path: Path) -> subprocess.CompletedProcess[str]:
        global observed_mode
        meta = os.lstat(path)
        observed_mode = stat.S_IMODE(meta.st_mode)
        assert meta.st_uid == uid and meta.st_gid == gid
        return subprocess.run(
            [str(path), "PRECHECK"],
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
            check=False,
        )

    rc, metadata = mod.execute_precheck(
        helper_path=destination,
        capability_path=capability,
        source_helper=source,
        owner_uid=uid,
        owner_gid=gid,
        source_uid=uid,
        source_gid=gid,
        runner=inspect_runner,
        use_signal_guard=False,
    )
    assert rc == 0
    assert metadata["RUNTIME_ACCOUNT_STATE"] == "EXACT"
    assert observed_mode == 0o755
    assert not os.path.lexists(destination)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    capability = root / "capability.json"
    destination = root / "installed-helper"
    make_helper(source, exit_code=1, failed=True)
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    make_manifest(capability, destination, digest)
    rc, metadata = mod.execute_precheck(
        helper_path=destination,
        capability_path=capability,
        source_helper=source,
        owner_uid=uid,
        owner_gid=gid,
        source_uid=uid,
        source_gid=gid,
        use_signal_guard=False,
    )
    assert rc == 1
    assert metadata["RUNTIME_ACCOUNT_STATE"] == "UNSAFE"
    assert not os.path.lexists(destination)

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    capability = root / "capability.json"
    destination = root / "installed-helper"
    make_helper(source)
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    make_manifest(capability, destination, digest)
    destination.write_text("preexisting-do-not-touch", encoding="utf-8")
    try:
        mod.execute_precheck(
            helper_path=destination,
            capability_path=capability,
            source_helper=source,
            owner_uid=uid,
            owner_gid=gid,
            source_uid=uid,
            source_gid=gid,
            use_signal_guard=False,
        )
    except mod.ContractError:
        pass
    else:
        raise AssertionError("pre-existing helper was accepted")
    assert destination.read_text(encoding="utf-8") == "preexisting-do-not-touch"

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    capability = root / "capability.json"
    destination = root / "installed-helper"
    make_helper(source)
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    make_manifest(capability, destination, digest)
    destination.symlink_to(root / "missing-target")
    try:
        mod.execute_precheck(
            helper_path=destination,
            capability_path=capability,
            source_helper=source,
            owner_uid=uid,
            owner_gid=gid,
            source_uid=uid,
            source_gid=gid,
            use_signal_guard=False,
        )
    except mod.ContractError:
        pass
    else:
        raise AssertionError("pre-existing symlink was accepted")
    assert destination.is_symlink()

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    capability = root / "capability.json"
    destination = root / "installed-helper"
    make_helper(source)
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    make_manifest(capability, destination, digest)

    def explode(_path: Path):
        raise OSError("synthetic helper failure")

    try:
        mod.execute_precheck(
            helper_path=destination,
            capability_path=capability,
            source_helper=source,
            owner_uid=uid,
            owner_gid=gid,
            source_uid=uid,
            source_gid=gid,
            runner=explode,
            use_signal_guard=False,
        )
    except OSError:
        pass
    else:
        raise AssertionError("synthetic runner failure was accepted")
    assert not os.path.lexists(destination)

assert "read_runtime_password()" in helper_source
precheck_branch = helper_source.split('if action in {"PRECHECK", "VERIFY"}:', 1)[1].split(
    'if action == "APPLY":', 1
)[0]
assert "read_runtime_password" not in precheck_branch

print("TRANSIENT_ROOT_PRECHECK_ONLY=PASS")
print("HELPER_MANIFEST_BINDING=PASS")
print("PREEXISTING_HELPER=FAIL_CLOSED_NO_OVERWRITE")
print("TRANSIENT_HELPER_MODE=0755")
print("TRANSIENT_HELPER_CLEANUP_SUCCESS=PASS")
print("TRANSIENT_HELPER_CLEANUP_FAILURE_PATH=PASS")
print("SUDOERS_INSTALL=NONE")
print("APPLY_REACHABLE=NO")
print("VERIFY_REACHABLE=NO")
print("DB_PASSWORD_READ=NONE")
print("RUNTIME_ENV_READ=NONE")
print("MARIADB_MUTATION_PATH=NONE")
print("EVIDENCE=METADATA_ONLY")
