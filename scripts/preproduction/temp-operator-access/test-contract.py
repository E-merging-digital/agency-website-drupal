#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import os
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
REMOTE = HERE / "remote-add-key.py"
SPEC = importlib.util.spec_from_file_location("add899", REMOTE)
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

assert mod.SSH_USER == "agency-preprod"
assert str(mod.AUTHORIZED_KEYS) == "/home/agency-preprod/.ssh/authorized_keys"
assert mod.TEMP_PUBLIC_KEY == "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIArdJ26K9/VGRXKED9m9/dji80VjuY+0NTC9ANRV25fP agency-preprod-temp-2026-08-31"
assert mod.fingerprint_for_key(mod.TEMP_PUBLIC_KEY) == "SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg"
mod.validate_fixed_key()

uid = os.geteuid()
gid = os.getegid()

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    ssh = home / ".ssh"
    ssh.mkdir(mode=0o700)
    os.chmod(ssh, 0o700)
    auth = ssh / "authorized_keys"
    original = b"ssh-ed25519 AAAATEST unrelated-one\n# preserved comment\n"
    auth.write_bytes(original)
    os.chmod(auth, 0o600)
    before, after = mod.append_exact_key(ssh, auth, uid, gid)
    assert (before, after) == ("NO", "YES")
    first = auth.read_bytes()
    assert first == original + mod.TEMP_PUBLIC_KEY.encode() + b"\n"
    before2, after2 = mod.append_exact_key(ssh, auth, uid, gid)
    assert (before2, after2) == ("YES", "YES")
    assert auth.read_bytes() == first

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    ssh = home / ".ssh"
    ssh.mkdir(mode=0o700)
    os.chmod(ssh, 0o700)
    real = home / "real"
    real.write_text("x\n")
    os.chmod(real, 0o600)
    auth = ssh / "authorized_keys"
    auth.symlink_to(real)
    try:
        mod.append_exact_key(ssh, auth, uid, gid)
    except mod.ContractError:
        pass
    else:
        raise AssertionError("authorized_keys symlink accepted")

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    ssh = home / ".ssh"
    ssh.mkdir(mode=0o700)
    os.chmod(ssh, 0o700)
    auth = ssh / "authorized_keys"
    auth.write_text("x\n")
    os.chmod(auth, 0o644)
    try:
        mod.append_exact_key(ssh, auth, uid, gid)
    except mod.ContractError:
        pass
    else:
        raise AssertionError("unexpected authorized_keys mode accepted")

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    real = home / "realssh"
    real.mkdir(mode=0o700)
    os.chmod(real, 0o700)
    auth = real / "authorized_keys"
    auth.write_text("x\n")
    os.chmod(auth, 0o600)
    ssh = home / ".ssh"
    ssh.symlink_to(real, target_is_directory=True)
    try:
        mod.append_exact_key(ssh, auth, uid, gid)
    except mod.ContractError:
        pass
    else:
        raise AssertionError(".ssh symlink accepted")

source = REMOTE.read_text()
assert "len(sys.argv) != 1" in source
assert "--key" not in source and "PUBLIC_KEY=" not in source
assert "REMOVE" not in source

print("FIXED_USER_TEST=PASS")
print("FIXED_PATH_TEST=PASS")
print("FIXED_KEY_TEST=PASS")
print("FINGERPRINT_TEST=PASS")
print("ARBITRARY_KEY_REJECTION=PASS")
print("AUTHORIZED_KEYS_PRESERVATION=PASS")
print("IDEMPOTENT_ADD=PASS")
print("SYMLINK_UNEXPECTED_STATE_FAIL_CLOSED=PASS")
