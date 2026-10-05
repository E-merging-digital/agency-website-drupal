#!/usr/bin/python3 -I
"""Deterministic logical runtime-state identity for Agency PREPROD.

This module never accepts a caller-selected database. The runtime database is
fixed to agency_preprod. The canonicalizer is also imported by CI to prove that
two dumps of an unchanged MariaDB 11.8 fixture are byte-identical after
normalization and that backup->mutation->restore returns the exact digest.
"""
from __future__ import annotations

import hashlib
import os
import stat
import subprocess
from pathlib import Path

RUNTIME_DB = "agency_preprod"
MARIADB_DUMP_BIN = "/usr/bin/mariadb-dump"
MARIADB_BIN = "/usr/bin/mariadb"
CLEAN_ENV = {"PATH": "/usr/bin:/bin", "HOME": "/root", "LC_ALL": "C"}

# The options intentionally remove volatile comments/date material and impose
# primary-key row order. Extended inserts are disabled so row serialization is
# stable and reviewable. No routines/events/triggers are in the supported
# activation subset.
DUMP_OPTIONS = (
    "--protocol=socket",
    "--single-transaction",
    "--quick",
    "--skip-lock-tables",
    "--no-tablespaces",
    "--skip-comments",
    "--compact",
    "--order-by-primary",
    "--hex-blob",
    "--skip-extended-insert",
    "--skip-add-locks",
    "--skip-disable-keys",
    "--skip-triggers",
    "--routines=0",
    "--events=0",
    "--databases",
    RUNTIME_DB,
)


class StateDigestError(RuntimeError):
    pass


def canonicalize_dump_bytes(raw: bytes) -> bytes:
    """Normalize only transport-level line representation, never SQL values."""
    text = raw.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    # --skip-comments/--compact remove volatile dump comments. Refuse any
    # residual MariaDB dump-completion timestamp rather than silently stripping
    # unknown volatile content.
    if b"Dump completed on" in text:
        raise StateDigestError("Volatile dump timestamp survived canonical options.")
    lines = [line.rstrip() for line in text.split(b"\n")]
    while lines and lines[-1] == b"":
        lines.pop()
    return b"\n".join(lines) + b"\n"


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_dump() -> bytes:
    if not os.path.isfile(MARIADB_DUMP_BIN) or not os.access(MARIADB_DUMP_BIN, os.X_OK):
        raise StateDigestError("Fixed mariadb-dump executable unavailable.")
    result = subprocess.run(
        [MARIADB_DUMP_BIN, *DUMP_OPTIONS],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        env=CLEAN_ENV,
        check=False,
    )
    if result.returncode != 0:
        raise StateDigestError("Deterministic runtime dump failed.")
    return canonicalize_dump_bytes(result.stdout)


def runtime_state_sha256() -> str:
    return sha256_bytes(canonical_dump())


def write_verified_backup(path: str) -> tuple[str, int]:
    target = Path(path)
    parent = target.parent
    pstat = os.lstat(parent)
    if stat.S_ISLNK(pstat.st_mode) or not stat.S_ISDIR(pstat.st_mode):
        raise StateDigestError("Backup directory type invalid.")
    if pstat.st_uid != 0 or pstat.st_gid != 0 or stat.S_IMODE(pstat.st_mode) != 0o700:
        raise StateDigestError("Backup directory ownership/mode invalid.")
    payload = canonical_dump()
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(target, flags, 0o600)
    try:
        os.write(fd, payload)
        os.fsync(fd)
    finally:
        os.close(fd)
    metadata = os.lstat(target)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        raise StateDigestError("Backup file type invalid.")
    if metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) != 0o600:
        raise StateDigestError("Backup file ownership/mode invalid.")
    digest = sha256_bytes(payload)
    if sha256_bytes(target.read_bytes()) != digest:
        raise StateDigestError("Backup digest verification failed.")
    return digest, len(payload)


def restore_verified_backup(path: str, expected_sha256: str) -> str:
    target = Path(path)
    metadata = os.lstat(target)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        raise StateDigestError("Backup restore source type invalid.")
    if metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) != 0o600:
        raise StateDigestError("Backup restore source ownership/mode invalid.")
    payload = target.read_bytes()
    if sha256_bytes(payload) != expected_sha256:
        raise StateDigestError("Backup restore source digest mismatch.")
    result = subprocess.run(
        [MARIADB_BIN, "--protocol=socket", RUNTIME_DB],
        input=payload,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=CLEAN_ENV,
        check=False,
    )
    if result.returncode != 0:
        raise StateDigestError("Backup restore failed.")
    restored = runtime_state_sha256()
    if restored != expected_sha256:
        raise StateDigestError("Restored runtime state digest mismatch.")
    return restored
