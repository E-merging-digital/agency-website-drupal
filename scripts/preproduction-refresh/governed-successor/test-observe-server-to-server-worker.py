#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import os
import tempfile
from pathlib import Path

MODULE_PATH = Path(__file__).with_name('observe-server-to-server-worker.py')
SPEC = importlib.util.spec_from_file_location('observer', MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
observer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(observer)

REQUEST = 'apply-948-final-real-s2s-r1'
MAIN = '2e4fa695f57fb8605379cb27837a2d03b6e78ecd'
GOOD = (
    'schema_version=1\n'
    f'request_id={REQUEST}\n'
    f'main_sha={MAIN}\n'
    'outcome=ROLLED_BACK\n'
    'detail=ROLLBACK_HEALTH_FAILED\n'
)


def write(path: Path, content: str, mode: int = 0o600) -> None:
    path.write_text(content, encoding='ascii')
    os.chmod(path, mode)


def parse(path: Path) -> tuple[str, str, str]:
    return observer.parse_result(path, REQUEST, MAIN, os.getuid(), os.getgid())


def main() -> None:
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        result = root / 'result.env'

        write(result, GOOD)
        assert parse(result) == ('PRESENT', 'ROLLED_BACK', 'ROLLBACK_HEALTH_FAILED')

        write(result, GOOD.replace('ROLLBACK_HEALTH_FAILED', 'unsafe-value'))
        assert parse(result) == ('UNSAFE', 'NONE', 'NONE')

        write(result, GOOD.replace(REQUEST, 'apply-949-other-request-r1'))
        assert parse(result) == ('UNSAFE', 'NONE', 'NONE')

        write(result, GOOD.replace(MAIN, 'a' * 40))
        assert parse(result) == ('UNSAFE', 'NONE', 'NONE')

        write(result, GOOD, 0o644)
        assert parse(result) == ('UNSAFE', 'NONE', 'NONE')

        write(result, 'A' * (observer.MAX_RESULT_BYTES + 1))
        assert parse(result) == ('UNSAFE', 'NONE', 'NONE')

        result.unlink()
        target = root / 'target.env'
        write(target, GOOD)
        result.symlink_to(target)
        assert parse(result) == ('UNSAFE', 'NONE', 'NONE')

    print('DETAIL_EXPOSURE=BOUNDED_ALLOWLISTED_ONLY')
    print('INVALID_DETAIL=FAIL_CLOSED')
    print('REQUEST_MAIN_BINDING=PRESERVED')
    print('RESULT_FILE_SAFETY=PRESERVED')


if __name__ == '__main__':
    main()
