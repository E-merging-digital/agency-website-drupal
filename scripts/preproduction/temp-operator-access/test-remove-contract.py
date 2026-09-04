#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import os
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
REMOTE = HERE / "remote-remove-key.py"
SPEC = importlib.util.spec_from_file_location("remove912", REMOTE)
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
key = mod.TEMP_PUBLIC_KEY.encode("ascii")


def make_valid_tree(home: Path, initial: bytes) -> tuple[Path, Path]:
    os.chmod(home, 0o700)
    ssh = home / ".ssh"
    ssh.mkdir(mode=0o700)
    os.chmod(ssh, 0o700)
    auth = ssh / "authorized_keys"
    auth.write_bytes(initial)
    os.chmod(auth, 0o600)
    remote = home / ".agency-912-test"
    remote.mkdir(mode=0o700)
    os.chmod(remote, 0o700)
    return auth, remote


def stat_mode(path: Path) -> int:
    return path.stat().st_mode & 0o777


def expect_failure(home: Path, remote: Path, owner_uid: int = uid, owner_gid: int = gid) -> None:
    try:
        mod.remove_exact_key(home, owner_uid, owner_gid, remote)
    except (mod.ContractError, FileNotFoundError, FileExistsError, PermissionError, OSError):
        return
    raise AssertionError("unsafe state accepted")


with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    unrelated_a = b"ssh-ed25519 AAAATEST unrelated-one\n# preserved comment\n"
    unrelated_b = b"ssh-rsa AAAATEST2 unrelated-two\n"
    auth, remote = make_valid_tree(home, unrelated_a + key + b"\n" + unrelated_b)
    inode_before = auth.stat().st_ino
    before, after = mod.remove_exact_key(home, uid, gid, remote)
    assert (before, after) == ("YES", "NO")
    assert auth.read_bytes() == unrelated_a + unrelated_b
    assert auth.stat().st_ino != inode_before
    assert stat_mode(auth) == 0o600

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    original = b"ssh-ed25519 AAAATEST unrelated-one\n# untouched\n"
    auth, remote = make_valid_tree(home, original)
    inode_before = auth.stat().st_ino
    before, after = mod.remove_exact_key(home, uid, gid, remote)
    assert (before, after) == ("NO", "NO")
    assert auth.read_bytes() == original
    assert auth.stat().st_ino == inode_before
    assert not (remote / mod.REPLACEMENT_NAME).exists()

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    original = b"first\n" + key + b"\nsecond\n" + key + b"\nthird\n"
    auth, remote = make_valid_tree(home, original)
    before, after = mod.remove_exact_key(home, uid, gid, remote)
    assert (before, after) == ("YES", "NO")
    assert auth.read_bytes() == b"first\nsecond\nthird\n"

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"first\n" + key)
    before, after = mod.remove_exact_key(home, uid, gid, remote)
    assert (before, after) == ("YES", "NO")
    assert auth.read_bytes() == b"first\n"

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"x\n")
    real = home / "real"
    real.write_text("x\n")
    os.chmod(real, 0o600)
    auth.unlink()
    auth.symlink_to(real)
    expect_failure(home, remote)

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"x\n")
    auth.unlink()
    auth.mkdir(mode=0o700)
    expect_failure(home, remote)

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"x\n")
    os.chmod(auth, 0o644)
    expect_failure(home, remote)

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"x\n")
    expect_failure(home, remote, owner_uid=uid + 1)

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"bad\r\n")
    expect_failure(home, remote)

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"bad\x00state\n")
    expect_failure(home, remote)

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    auth, remote = make_valid_tree(home, b"x\n")
    hardlink = home / ".ssh" / "authorized_keys.alias"
    os.link(auth, hardlink)
    expect_failure(home, remote)

with tempfile.TemporaryDirectory() as tmp:
    home = Path(tmp)
    os.chmod(home, 0o700)
    real = home / "realssh"
    real.mkdir(mode=0o700)
    os.chmod(real, 0o700)
    auth = real / "authorized_keys"
    auth.write_bytes(b"x\n")
    os.chmod(auth, 0o600)
    ssh = home / ".ssh"
    ssh.symlink_to(real, target_is_directory=True)
    remote = home / ".agency-912-test"
    remote.mkdir(mode=0o700)
    expect_failure(home, remote)

source = REMOTE.read_text()
assert "len(sys.argv) != 1" in source
assert "--key" not in source and "PUBLIC_KEY=" not in source
assert "argparse" not in source
assert "os.replace(" in source
assert "O_EXCL" in source and "O_NOFOLLOW" in source and "O_DIRECTORY" in source
assert "truncate(" not in source and "O_TRUNC" not in source
assert "sudo" not in source and "root@" not in source
assert "print(before_bytes" not in source and "print(after_bytes" not in source
assert "TEMP_PUBLIC_KEY =" in source and "TEMP_KEY_FINGERPRINT =" in source

print("EXACT_TARGET_ONLY=PASS")
print("UNRELATED_KEYS_PRESERVED=PASS")
print("TARGET_PRESENT_TO_ABSENT=PASS")
print("TARGET_ALREADY_ABSENT=SAFE_IDEMPOTENT")
print("DUPLICATE_EXACT_TARGETS=BOUNDED")
print("ARBITRARY_KEY_INPUT=IMPOSSIBLE")
print("ARBITRARY_FINGERPRINT_INPUT=IMPOSSIBLE")
print("ARBITRARY_USER_INPUT=IMPOSSIBLE")
print("ARBITRARY_PATH_INPUT=IMPOSSIBLE")
print("ARBITRARY_COMMAND_INPUT=IMPOSSIBLE")
print("AUTHORIZED_KEYS_SYMLINK=FAIL_CLOSED")
print("UNEXPECTED_FILE_TYPE=FAIL_CLOSED")
print("UNSAFE_OWNER=FAIL_CLOSED")
print("UNSAFE_MODE=FAIL_CLOSED")
print("MALFORMED_STATE=FAIL_CLOSED")
print("ATOMIC_REPLACEMENT=PASS")
print("SECRET_LOG_LEAKAGE=NONE")
