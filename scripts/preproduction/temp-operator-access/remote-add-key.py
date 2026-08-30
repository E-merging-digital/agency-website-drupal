#!/usr/bin/env python3
"""Fixed-purpose non-root ADD of the #899 temporary operator public key."""

from __future__ import annotations

import base64
import fcntl
import hashlib
import os
import pwd
import stat
import sys
from pathlib import Path

SSH_USER = "agency-preprod"
HOME = Path("/home/agency-preprod")
SSH_DIR = HOME / ".ssh"
AUTHORIZED_KEYS = SSH_DIR / "authorized_keys"
TEMP_PUBLIC_KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIArdJ26K9/VGRXKED9m9/dji80VjuY+0NTC9ANRV25fP agency-preprod-temp-2026-08-31"
TEMP_KEY_FINGERPRINT = "SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg"
MAX_AUTHORIZED_KEYS_BYTES = 1024 * 1024


class ContractError(RuntimeError):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ContractError(message)


def fingerprint_for_key(line: str) -> str:
    fields = line.split(" ")
    require(len(fields) == 3, "public key shape invalid")
    require(fields[0] == "ssh-ed25519", "public key type invalid")
    require(fields[2] == "agency-preprod-temp-2026-08-31", "public key comment invalid")
    try:
        wire = base64.b64decode(fields[1], validate=True)
    except Exception as exc:
        raise ContractError("public key encoding invalid") from exc
    digest = base64.b64encode(hashlib.sha256(wire).digest()).decode("ascii").rstrip("=")
    return f"SHA256:{digest}"


def validate_fixed_key() -> None:
    require(fingerprint_for_key(TEMP_PUBLIC_KEY) == TEMP_KEY_FINGERPRINT, "fixed fingerprint mismatch")


def read_fd_bounded(fd: int) -> bytes:
    os.lseek(fd, 0, os.SEEK_SET)
    chunks: list[bytes] = []
    total = 0
    while True:
        chunk = os.read(fd, min(65536, MAX_AUTHORIZED_KEYS_BYTES + 1 - total))
        if not chunk:
            break
        chunks.append(chunk)
        total += len(chunk)
        require(total <= MAX_AUTHORIZED_KEYS_BYTES, "authorized_keys exceeds bound")
    return b"".join(chunks)


def append_exact_key(
    ssh_dir: Path,
    authorized_keys: Path,
    owner_uid: int,
    owner_gid: int,
) -> tuple[str, str]:
    ssh_meta = os.lstat(ssh_dir)
    require(stat.S_ISDIR(ssh_meta.st_mode) and not stat.S_ISLNK(ssh_meta.st_mode), ".ssh type invalid")
    require((ssh_meta.st_uid, ssh_meta.st_gid) == (owner_uid, owner_gid), ".ssh ownership invalid")
    require(stat.S_IMODE(ssh_meta.st_mode) == 0o700, ".ssh mode invalid")

    key_meta = os.lstat(authorized_keys)
    require(stat.S_ISREG(key_meta.st_mode) and not stat.S_ISLNK(key_meta.st_mode), "authorized_keys type invalid")
    require((key_meta.st_uid, key_meta.st_gid) == (owner_uid, owner_gid), "authorized_keys ownership invalid")
    require(stat.S_IMODE(key_meta.st_mode) == 0o600, "authorized_keys mode invalid")
    require(key_meta.st_nlink == 1, "authorized_keys hard-link count invalid")

    flags = os.O_RDWR | os.O_APPEND | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    fd = os.open(authorized_keys, flags)
    try:
        os.set_inheritable(fd, False)
        opened = os.fstat(fd)
        require(stat.S_ISREG(opened.st_mode), "opened authorized_keys type invalid")
        require((opened.st_dev, opened.st_ino) == (key_meta.st_dev, key_meta.st_ino), "authorized_keys identity changed")
        require((opened.st_uid, opened.st_gid) == (owner_uid, owner_gid), "opened authorized_keys ownership invalid")
        require(stat.S_IMODE(opened.st_mode) == 0o600, "opened authorized_keys mode invalid")
        require(opened.st_nlink == 1, "opened authorized_keys hard-link count invalid")
        fcntl.flock(fd, fcntl.LOCK_EX)

        locked = os.fstat(fd)
        require((locked.st_dev, locked.st_ino) == (opened.st_dev, opened.st_ino), "authorized_keys changed while locking")
        before_bytes = read_fd_bounded(fd)
        key_bytes = TEMP_PUBLIC_KEY.encode("ascii")
        occurrences = sum(1 for line in before_bytes.splitlines() if line == key_bytes)
        require(occurrences <= 1, "temporary key appears more than once")

        present_before = "YES" if occurrences == 1 else "NO"
        if occurrences == 0:
            require(not before_bytes or before_bytes.endswith(b"\n"), "authorized_keys lacks terminal newline")
            payload = key_bytes + b"\n"
            written = 0
            while written < len(payload):
                count = os.write(fd, payload[written:])
                require(count > 0, "authorized_keys append failed")
                written += count
            os.fsync(fd)

        after_bytes = read_fd_bounded(fd)
        if occurrences == 0:
            require(after_bytes == before_bytes + key_bytes + b"\n", "unrelated authorized_keys bytes changed")
        else:
            require(after_bytes == before_bytes, "idempotent ADD changed authorized_keys")

        after_occurrences = sum(1 for line in after_bytes.splitlines() if line == key_bytes)
        require(after_occurrences == 1, "temporary key is not present exactly once after ADD")
        return present_before, "YES"
    finally:
        os.close(fd)


def main() -> int:
    if len(sys.argv) != 1:
        print("RESULT=FAIL_CLOSED")
        return 64
    try:
        validate_fixed_key()
        pw = pwd.getpwuid(os.geteuid())
        require(pw.pw_name == SSH_USER, "runtime user invalid")
        require(pw.pw_dir == str(HOME), "runtime home invalid")
        before, after = append_exact_key(SSH_DIR, AUTHORIZED_KEYS, pw.pw_uid, pw.pw_gid)
    except (ContractError, FileNotFoundError, PermissionError, OSError):
        print("RESULT=FAIL_CLOSED")
        return 1

    print(f"SSH_USER={SSH_USER}")
    print(f"TEMP_KEY_FINGERPRINT={TEMP_KEY_FINGERPRINT}")
    print(f"KEY_PRESENT_BEFORE={before}")
    print(f"KEY_PRESENT_AFTER={after}")
    print("RESULT=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
