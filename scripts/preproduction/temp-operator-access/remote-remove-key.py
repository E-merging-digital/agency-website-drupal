#!/usr/bin/env python3
"""Fixed-purpose non-root REMOVE of the #912 temporary operator public key."""

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
SSH_DIR_NAME = ".ssh"
AUTHORIZED_KEYS_NAME = "authorized_keys"
SSH_DIR = HOME / SSH_DIR_NAME
AUTHORIZED_KEYS = SSH_DIR / AUTHORIZED_KEYS_NAME
TEMP_PUBLIC_KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIArdJ26K9/VGRXKED9m9/dji80VjuY+0NTC9ANRV25fP agency-preprod-temp-2026-08-31"
TEMP_KEY_FINGERPRINT = "SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg"
MAX_AUTHORIZED_KEYS_BYTES = 1024 * 1024
REMOTE_DIR = Path(__file__).resolve().parent
REPLACEMENT_NAME = "authorized_keys.next"


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


def write_all(fd: int, payload: bytes) -> None:
    written = 0
    while written < len(payload):
        count = os.write(fd, payload[written:])
        require(count > 0, "replacement write failed")
        written += count


def validate_home(path: Path, owner_uid: int, owner_gid: int) -> tuple[int, int]:
    meta = os.lstat(path)
    require(stat.S_ISDIR(meta.st_mode) and not stat.S_ISLNK(meta.st_mode), "home type invalid")
    require((meta.st_uid, meta.st_gid) == (owner_uid, owner_gid), "home ownership invalid")
    require(stat.S_IMODE(meta.st_mode) & 0o022 == 0, "home is writable by group/other")
    return meta.st_dev, meta.st_ino


def require_path_identity(path: Path, fd: int, message: str) -> None:
    path_meta = os.lstat(path)
    fd_meta = os.fstat(fd)
    require((path_meta.st_dev, path_meta.st_ino) == (fd_meta.st_dev, fd_meta.st_ino), message)


def split_preserving_unrelated(content: bytes) -> tuple[list[bytes], int]:
    require(b"\x00" not in content, "authorized_keys contains NUL")
    require(b"\r" not in content, "authorized_keys contains CR")
    require(all(byte in (0x09, 0x0A) or byte >= 0x20 for byte in content), "authorized_keys contains control byte")
    key_bytes = TEMP_PUBLIC_KEY.encode("ascii")
    segments = content.splitlines(keepends=True)
    if content and not segments:
        raise ContractError("authorized_keys state invalid")
    kept: list[bytes] = []
    occurrences = 0
    for segment in segments:
        line = segment[:-1] if segment.endswith(b"\n") else segment
        if line == key_bytes:
            occurrences += 1
        else:
            kept.append(segment)
    return kept, occurrences


def remove_exact_key(home: Path, owner_uid: int, owner_gid: int, remote_dir: Path) -> tuple[str, str]:
    expected_home_identity = validate_home(home, owner_uid, owner_gid)
    directory_flags = (
        os.O_RDONLY
        | getattr(os, "O_DIRECTORY", 0)
        | getattr(os, "O_NOFOLLOW", 0)
        | getattr(os, "O_CLOEXEC", 0)
    )

    remote_meta = os.lstat(remote_dir)
    require(stat.S_ISDIR(remote_meta.st_mode) and not stat.S_ISLNK(remote_meta.st_mode), "remote temp dir type invalid")
    require((remote_meta.st_uid, remote_meta.st_gid) == (owner_uid, owner_gid), "remote temp dir ownership invalid")
    require(stat.S_IMODE(remote_meta.st_mode) == 0o700, "remote temp dir mode invalid")

    home_fd = os.open(home, directory_flags)
    remote_fd = os.open(remote_dir, directory_flags)
    try:
        os.set_inheritable(home_fd, False)
        os.set_inheritable(remote_fd, False)
        home_open = os.fstat(home_fd)
        remote_open = os.fstat(remote_fd)
        require((home_open.st_dev, home_open.st_ino) == expected_home_identity, "home identity changed")
        require(stat.S_ISDIR(home_open.st_mode), "opened home type invalid")
        require((home_open.st_uid, home_open.st_gid) == (owner_uid, owner_gid), "opened home ownership invalid")
        require(stat.S_IMODE(home_open.st_mode) & 0o022 == 0, "opened home is writable by group/other")
        require((remote_open.st_dev, remote_open.st_ino) == (remote_meta.st_dev, remote_meta.st_ino), "remote temp dir identity changed")
        require((remote_open.st_uid, remote_open.st_gid) == (owner_uid, owner_gid), "opened remote temp dir ownership invalid")
        require(stat.S_IMODE(remote_open.st_mode) == 0o700, "opened remote temp dir mode invalid")

        ssh_meta = os.stat(SSH_DIR_NAME, dir_fd=home_fd, follow_symlinks=False)
        require(stat.S_ISDIR(ssh_meta.st_mode) and not stat.S_ISLNK(ssh_meta.st_mode), ".ssh type invalid")
        require((ssh_meta.st_uid, ssh_meta.st_gid) == (owner_uid, owner_gid), ".ssh ownership invalid")
        require(stat.S_IMODE(ssh_meta.st_mode) == 0o700, ".ssh mode invalid")

        ssh_fd = os.open(SSH_DIR_NAME, directory_flags, dir_fd=home_fd)
        try:
            os.set_inheritable(ssh_fd, False)
            ssh_open = os.fstat(ssh_fd)
            require((ssh_open.st_dev, ssh_open.st_ino) == (ssh_meta.st_dev, ssh_meta.st_ino), ".ssh identity changed")
            require((ssh_open.st_uid, ssh_open.st_gid) == (owner_uid, owner_gid), "opened .ssh ownership invalid")
            require(stat.S_IMODE(ssh_open.st_mode) == 0o700, "opened .ssh mode invalid")

            key_meta = os.stat(AUTHORIZED_KEYS_NAME, dir_fd=ssh_fd, follow_symlinks=False)
            require(stat.S_ISREG(key_meta.st_mode) and not stat.S_ISLNK(key_meta.st_mode), "authorized_keys type invalid")
            require((key_meta.st_uid, key_meta.st_gid) == (owner_uid, owner_gid), "authorized_keys ownership invalid")
            require(stat.S_IMODE(key_meta.st_mode) == 0o600, "authorized_keys mode invalid")
            require(key_meta.st_nlink == 1, "authorized_keys hard-link count invalid")
            require(remote_open.st_dev == key_meta.st_dev, "atomic replacement filesystem mismatch")

            flags = os.O_RDWR | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
            fd = os.open(AUTHORIZED_KEYS_NAME, flags, dir_fd=ssh_fd)
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
                current = os.stat(AUTHORIZED_KEYS_NAME, dir_fd=ssh_fd, follow_symlinks=False)
                require((current.st_dev, current.st_ino) == (locked.st_dev, locked.st_ino), "authorized_keys path changed while locking")
                require(locked.st_nlink == 1, "locked authorized_keys hard-link count invalid")

                before_bytes = read_fd_bounded(fd)
                kept_segments, occurrences = split_preserving_unrelated(before_bytes)
                present_before = "YES" if occurrences else "NO"

                if occurrences == 0:
                    unchanged = read_fd_bounded(fd)
                    require(unchanged == before_bytes, "idempotent REMOVE observed unexpected change")
                    final_path = os.stat(AUTHORIZED_KEYS_NAME, dir_fd=ssh_fd, follow_symlinks=False)
                    require((final_path.st_dev, final_path.st_ino) == (locked.st_dev, locked.st_ino), "authorized_keys path changed during idempotent REMOVE")
                    require(stat.S_IMODE(final_path.st_mode) == 0o600, "final authorized_keys mode invalid")
                    require((final_path.st_uid, final_path.st_gid) == (owner_uid, owner_gid), "final authorized_keys ownership invalid")
                    require_path_identity(home, home_fd, "home path changed during REMOVE")
                    return "NO", "NO"

                replacement = b"".join(kept_segments)
                replacement_flags = (
                    os.O_WRONLY
                    | os.O_CREAT
                    | os.O_EXCL
                    | getattr(os, "O_NOFOLLOW", 0)
                    | getattr(os, "O_CLOEXEC", 0)
                )
                temp_fd = -1
                replaced = False
                try:
                    temp_fd = os.open(REPLACEMENT_NAME, replacement_flags, 0o600, dir_fd=remote_fd)
                    os.set_inheritable(temp_fd, False)
                    os.fchmod(temp_fd, 0o600)
                    temp_meta = os.fstat(temp_fd)
                    require(stat.S_ISREG(temp_meta.st_mode), "replacement type invalid")
                    require((temp_meta.st_uid, temp_meta.st_gid) == (owner_uid, owner_gid), "replacement ownership invalid")
                    require(stat.S_IMODE(temp_meta.st_mode) == 0o600, "replacement mode invalid")
                    require(temp_meta.st_nlink == 1, "replacement hard-link count invalid")
                    write_all(temp_fd, replacement)
                    os.fsync(temp_fd)
                    os.close(temp_fd)
                    temp_fd = -1

                    before_replace = os.stat(AUTHORIZED_KEYS_NAME, dir_fd=ssh_fd, follow_symlinks=False)
                    require((before_replace.st_dev, before_replace.st_ino) == (locked.st_dev, locked.st_ino), "authorized_keys changed before replacement")
                    os.replace(REPLACEMENT_NAME, AUTHORIZED_KEYS_NAME, src_dir_fd=remote_fd, dst_dir_fd=ssh_fd)
                    replaced = True
                    os.fsync(ssh_fd)

                    final_fd = os.open(
                        AUTHORIZED_KEYS_NAME,
                        os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0),
                        dir_fd=ssh_fd,
                    )
                    try:
                        os.set_inheritable(final_fd, False)
                        final_meta = os.fstat(final_fd)
                        require(stat.S_ISREG(final_meta.st_mode), "final authorized_keys type invalid")
                        require((final_meta.st_uid, final_meta.st_gid) == (owner_uid, owner_gid), "final authorized_keys ownership invalid")
                        require(stat.S_IMODE(final_meta.st_mode) == 0o600, "final authorized_keys mode invalid")
                        require(final_meta.st_nlink == 1, "final authorized_keys hard-link count invalid")
                        after_bytes = read_fd_bounded(final_fd)
                    finally:
                        os.close(final_fd)

                    require(after_bytes == replacement, "unrelated authorized_keys bytes changed")
                    _, after_occurrences = split_preserving_unrelated(after_bytes)
                    require(after_occurrences == 0, "temporary key remains after REMOVE")
                    final_path = os.stat(AUTHORIZED_KEYS_NAME, dir_fd=ssh_fd, follow_symlinks=False)
                    require((final_path.st_uid, final_path.st_gid) == (owner_uid, owner_gid), "final path ownership invalid")
                    require(stat.S_IMODE(final_path.st_mode) == 0o600, "final path mode invalid")
                    final_ssh = os.stat(SSH_DIR_NAME, dir_fd=home_fd, follow_symlinks=False)
                    require((final_ssh.st_dev, final_ssh.st_ino) == (ssh_open.st_dev, ssh_open.st_ino), ".ssh path changed during REMOVE")
                    require_path_identity(home, home_fd, "home path changed during REMOVE")
                    return present_before, "NO"
                finally:
                    if temp_fd >= 0:
                        os.close(temp_fd)
                    if not replaced:
                        try:
                            os.unlink(REPLACEMENT_NAME, dir_fd=remote_fd)
                        except FileNotFoundError:
                            pass
            finally:
                os.close(fd)
        finally:
            os.close(ssh_fd)
    finally:
        os.close(remote_fd)
        os.close(home_fd)


def main() -> int:
    if len(sys.argv) != 1:
        print("RESULT=FAIL_CLOSED")
        return 64
    try:
        validate_fixed_key()
        pw = pwd.getpwuid(os.geteuid())
        require(pw.pw_name == SSH_USER, "runtime user invalid")
        require(pw.pw_dir == str(HOME), "runtime home invalid")
        before, after = remove_exact_key(HOME, pw.pw_uid, pw.pw_gid, REMOTE_DIR)
    except (ContractError, FileNotFoundError, FileExistsError, PermissionError, OSError):
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
