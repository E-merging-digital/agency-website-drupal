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


def make_valid_tree(home: Path, initial: bytes = b"x\n") -> Path:
    os.chmod(home, 0o700)
    ssh = home / ".ssh"
    ssh.mkdir(mode=0o700)
    os.chmod(ssh, 0o700)
    auth = ssh / "authorized_keys"
    auth.write_bytes(initial)
    os.chmod(auth, 0o600)
    return auth


with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    original = b"ssh-ed25519 AAAATEST unrelated-one\n# preserved comment\n"
    auth = make_valid_tree(home, original)
    before, after = mod.append_exact_key(home, uid, gid)
    assert (before, after) == ("NO", "YES")
    first = auth.read_bytes()
    assert first == original + mod.TEMP_PUBLIC_KEY.encode() + b"\n"
    before2, after2 = mod.append_exact_key(home, uid, gid)
    assert (before2, after2) == ("YES", "YES")
    assert auth.read_bytes() == first

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth = make_valid_tree(home)
    real = home / "real"
    real.write_text("x\n")
    os.chmod(real, 0o600)
    auth.unlink()
    auth.symlink_to(real)
    try:
        mod.append_exact_key(home, uid, gid)
    except (mod.ContractError, OSError):
        pass
    else:
        raise AssertionError("authorized_keys symlink accepted")

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth = make_valid_tree(home)
    os.chmod(auth, 0o644)
    try:
        mod.append_exact_key(home, uid, gid)
    except mod.ContractError:
        pass
    else:
        raise AssertionError("unexpected authorized_keys mode accepted")

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    os.chmod(home, 0o700)
    real = home / "realssh"
    real.mkdir(mode=0o700)
    os.chmod(real, 0o700)
    auth = real / "authorized_keys"
    auth.write_text("x\n")
    os.chmod(auth, 0o600)
    ssh = home / ".ssh"
    ssh.symlink_to(real, target_is_directory=True)
    try:
        mod.append_exact_key(home, uid, gid)
    except (mod.ContractError, OSError):
        pass
    else:
        raise AssertionError(".ssh symlink accepted")

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    real_home = root / "real-home"
    real_home.mkdir(mode=0o700)
    make_valid_tree(real_home)
    linked_home = root / "linked-home"
    linked_home.symlink_to(real_home, target_is_directory=True)
    try:
        mod.append_exact_key(linked_home, uid, gid)
    except (mod.ContractError, OSError):
        pass
    else:
        raise AssertionError("home symlink accepted")

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    make_valid_tree(home)
    os.chmod(home, 0o770)
    try:
        mod.append_exact_key(home, uid, gid)
    except mod.ContractError:
        pass
    else:
        raise AssertionError("group-writable home accepted")

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth = make_valid_tree(home)
    hardlink = home / ".ssh" / "authorized_keys.alias"
    os.link(auth, hardlink)
    try:
        mod.append_exact_key(home, uid, gid)
    except mod.ContractError:
        pass
    else:
        raise AssertionError("hard-linked authorized_keys accepted")

source = REMOTE.read_text()
assert "len(sys.argv) != 1" in source
assert "--key" not in source and "PUBLIC_KEY=" not in source
assert "REMOVE" not in source
assert "O_DIRECTORY" in source and "O_NOFOLLOW" in source
assert "dir_fd=home_fd" in source and "dir_fd=ssh_fd" in source

print("FIXED_USER_TEST=PASS")
print("FIXED_PATH_TEST=PASS")
print("FIXED_KEY_TEST=PASS")
print("FINGERPRINT_TEST=PASS")
print("ARBITRARY_KEY_REJECTION=PASS")
print("AUTHORIZED_KEYS_PRESERVATION=PASS")
print("IDEMPOTENT_ADD=PASS")
print("SYMLINK_UNEXPECTED_STATE_FAIL_CLOSED=PASS")
print("HOME_PATH_COMPONENT_GUARD=PASS")
print("AUTHORIZED_KEYS_HARDLINK_REJECTION=PASS")
