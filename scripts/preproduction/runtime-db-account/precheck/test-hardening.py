#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import os
import stat
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = Path(__file__).resolve().parents[4]
REMOTE = HERE / "remote-precheck-root.py"
RUNNER = HERE / "run-precheck.sh"
WORKFLOW = REPO / ".github" / "workflows" / "preprod-895-runtime-db-precheck.yml"
VALIDATION = REPO / ".github" / "workflows" / "preprod-895-runtime-db-precheck-validation.yml"

workflow = WORKFLOW.read_text(encoding="utf-8")
validation = VALIDATION.read_text(encoding="utf-8")
runner = RUNNER.read_text(encoding="utf-8")
remote = REMOTE.read_text(encoding="utf-8")

# GitHub-expression source binding must be tested as literal repository text,
# never interpolated by the validation workflow's own run block.
dollar = "$"
steps_live = dollar + "{{ steps.live.outputs.main_sha }}"
needs_main = dollar + "{{ needs.validate-authority.outputs.main_sha }}"

assert f"ref: {steps_live}" in workflow
assert f"ref: {needs_main}" in workflow
assert f"test \"$live_main\" = '{needs_main}'" in workflow
assert 'test "$(git rev-parse HEAD)" = "$live_main"' in workflow
assert 'assert "ref: " in workflow' not in validation
assert steps_live not in validation
assert needs_main not in validation

checkout_pos = workflow.index(f"ref: {needs_main}")
revalidate_pos = workflow.index("Revalidate live main before privileged surface")
setup_pos = workflow.index("Configure transient root identity and pinned PREPROD trust")
execute_pos = workflow.index("Execute fixed transient PRECHECK route")
assert checkout_pos < revalidate_pos < setup_pos < execute_pos

# Key/trust workspace is request-owned and freshly allocated; cleanup accepts
# only paths created under the fixed RUNNER_TEMP/run-id prefixes.
assert 'key="$(mktemp "$RUNNER_TEMP/agency-895-root-${GITHUB_RUN_ID}.XXXXXX.key")"' in workflow
assert 'root_home="$(mktemp -d "$RUNNER_TEMP/agency-895-root-home-${GITHUB_RUN_ID}.XXXXXX")"' in workflow
assert 'key="$RUNNER_TEMP/agency-895-root-${GITHUB_RUN_ID}.key"' not in workflow
assert 'root_home="$RUNNER_TEMP/agency-895-root-home-${GITHUB_RUN_ID}"' not in workflow
assert 'key="${AGENCY_895_ROOT_KEY:-}"' in workflow
assert 'root_home="${AGENCY_895_ROOT_HOME:-}"' in workflow
assert '[[ "$key" == "$RUNNER_TEMP/agency-895-root-${GITHUB_RUN_ID}."*".key" ]]' in workflow
assert '[[ "$root_home" == "$RUNNER_TEMP/agency-895-root-home-${GITHUB_RUN_ID}."* ]]' in workflow
assert 'rm -f -- "$key"' in workflow
assert 'rm -rf -- "$root_home"' in workflow
assert 'export PREPROD_PROVISIONING_SSH_KEY="$AGENCY_895_ROOT_KEY"' in workflow
assert 'export PREPROD_KNOWN_HOSTS_FILE="$AGENCY_895_ROOT_HOME/.ssh/known_hosts"' in workflow

assert '[[ "$PREPROD_PROVISIONING_SSH_KEY" == "$RUNNER_TEMP/agency-895-root-${GITHUB_RUN_ID}."*".key" ]]' in runner
assert '[[ "$root_home" == "$RUNNER_TEMP/agency-895-root-home-${GITHUB_RUN_ID}."* ]]' in runner
assert 'StrictHostKeyChecking=yes' in runner
assert 'UserKnownHostsFile="$PREPROD_KNOWN_HOSTS_FILE"' in runner

# Structural action boundary remains PRECHECK-only.
assert '[str(helper_path), "PRECHECK"]' in remote
assert '"APPLY"' not in remote
assert '"VERIFY"' not in remote
assert 'DB_PASSWORD' not in workflow + runner + remote
assert 'runtime.env' not in workflow + runner + remote

SPEC = importlib.util.spec_from_file_location("remote_895_hardening", REMOTE)
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

uid = os.geteuid()
gid = os.getegid()


def make_source(path: Path) -> str:
    path.write_text("#!/usr/bin/env python3\nraise SystemExit(0)\n", encoding="utf-8")
    os.chmod(path, 0o700)
    return hashlib.sha256(path.read_bytes()).hexdigest()


# Synthetic interruption exactly after the no-overwrite hard link but before
# the normal helper-path inode capture. Cleanup must recover ownership from the
# still-present staging inode and remove only that same linked inode.
with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    destination = root / "installed-helper"
    digest = make_source(source)
    original_lstat = mod.os.lstat
    injected = False

    def interrupt_after_link(path):
        nonlocal_marker = None
        del nonlocal_marker
        global injected
        current = Path(path)
        if current == destination and os.path.lexists(destination) and not injected:
            injected = True
            raise mod.ContractError("synthetic post-link interruption")
        return original_lstat(path)

    mod.os.lstat = interrupt_after_link
    try:
        try:
            mod.atomic_install(source, destination, digest, uid, gid)
        except mod.ContractError:
            pass
        else:
            raise AssertionError("post-link interruption was not propagated")
    finally:
        mod.os.lstat = original_lstat
    assert injected
    assert not os.path.lexists(destination)

# Pre-link failure cannot create or delete a destination helper.
with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    destination = root / "installed-helper"
    digest = make_source(source)
    original_link = mod.os.link

    def fail_link(_source, _destination):
        raise FileExistsError("synthetic no-overwrite collision")

    mod.os.link = fail_link
    try:
        try:
            mod.atomic_install(source, destination, digest, uid, gid)
        except mod.ContractError:
            pass
        else:
            raise AssertionError("synthetic link collision was accepted")
    finally:
        mod.os.link = original_link
    assert not os.path.lexists(destination)

# If the request-owned inode is replaced before terminal cleanup, cleanup must
# refuse to delete the unknown replacement.
with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = root / "source-helper"
    destination = root / "installed-helper"
    digest = make_source(source)
    identity = mod.atomic_install(source, destination, digest, uid, gid)
    os.unlink(destination)
    destination.write_text("unknown-replacement", encoding="utf-8")
    os.chmod(destination, 0o600)
    try:
        mod.remove_owned_helper(destination, identity)
    except mod.ContractError:
        pass
    else:
        raise AssertionError("unknown replacement was deleted")
    assert destination.read_text(encoding="utf-8") == "unknown-replacement"

print("VALIDATION_EXPRESSION_INTERPOLATION=FIXED")
print("EXACT_AUTHORITY_CHECKOUT_BINDING=PROVEN")
print("EXACT_PRIVILEGED_CHECKOUT_BINDING=PROVEN")
print("LIVE_MAIN_REVALIDATION_BEFORE_ROOT_KEY=PROVEN")
print("REQUEST_OWNED_KEY_TRUST=PROVEN")
print("HELPER_SIGNAL_WINDOW=CLOSED_PROVEN")
print("UNKNOWN_HELPER_DELETE=REFUSED")
