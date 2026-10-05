#!/usr/bin/python3 -I
"""Converge the fixed PREPROD refresh-jobs root before secret staging."""
from __future__ import annotations

import os
import pwd
import stat
import sys
from pathlib import Path

DEPLOY_USER = 'agency-preprod'
SHARED = Path('/var/www/agency-preprod/shared')
REFRESH_JOBS = SHARED / 'refresh-jobs'
EXPECTED_MODE = 0o700


class ContractError(RuntimeError):
    """Raised when the fixed host contract cannot be proven safely."""


def require_safe_parent(path: Path, uid: int) -> None:
    metadata = os.lstat(path)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        raise ContractError('PREPROD shared root is unsafe.')
    if metadata.st_uid != uid or stat.S_IMODE(metadata.st_mode) & 0o002:
        raise ContractError('PREPROD shared root identity is unsafe.')


def require_exact_jobs_root(path: Path, uid: int, gid: int) -> None:
    metadata = os.lstat(path)
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        raise ContractError('Refresh jobs root is not a safe directory.')
    if metadata.st_uid != uid or metadata.st_gid != gid:
        raise ContractError('Refresh jobs root ownership mismatch.')
    if stat.S_IMODE(metadata.st_mode) != EXPECTED_MODE:
        raise ContractError('Refresh jobs root mode mismatch.')


def converge_fixed_root() -> None:
    account = pwd.getpwnam(DEPLOY_USER)
    require_safe_parent(SHARED, account.pw_uid)
    try:
        require_exact_jobs_root(REFRESH_JOBS, account.pw_uid, account.pw_gid)
    except FileNotFoundError:
        try:
            os.mkdir(REFRESH_JOBS, EXPECTED_MODE)
        except FileExistsError as exc:
            raise ContractError('Refresh jobs root changed during creation.') from exc
        os.chown(REFRESH_JOBS, account.pw_uid, account.pw_gid)
        os.chmod(REFRESH_JOBS, EXPECTED_MODE)
        require_exact_jobs_root(REFRESH_JOBS, account.pw_uid, account.pw_gid)


def self_test() -> None:
    fake_dir = os.stat_result((stat.S_IFDIR | 0o700, 0, 0, 1, 1000, 1000, 0, 0, 0, 0))
    fake_symlink = os.stat_result((stat.S_IFLNK | 0o777, 0, 0, 1, 1000, 1000, 0, 0, 0, 0))
    assert stat.S_ISDIR(fake_dir.st_mode)
    assert stat.S_IMODE(fake_dir.st_mode) == EXPECTED_MODE
    assert stat.S_ISLNK(fake_symlink.st_mode)
    assert REFRESH_JOBS == Path('/var/www/agency-preprod/shared/refresh-jobs')
    print('SELF_TEST=PASS')


def main(argv: list[str]) -> int:
    if argv == ['--self-test']:
        self_test()
        return 0
    if argv:
        raise ContractError('Fixed prerequisite accepts no arguments.')
    if os.geteuid() != 0:
        raise ContractError('Fixed prerequisite requires root.')
    converge_fixed_root()
    print('REFRESH_JOBS_ROOT=READY')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main(sys.argv[1:]))
    except (ContractError, KeyError, OSError) as exc:
        print(f'REFRESH_JOBS_ROOT=FAIL_CLOSED:{type(exc).__name__}', file=sys.stderr)
        raise SystemExit(78)