#!/usr/bin/env python3
"""Focused #951 reproduction for the fixed extensionless #866 helper loader."""
from __future__ import annotations

import importlib.util
import os
import shutil
import sys
import tempfile
from pathlib import Path

WORKER = Path(
    'scripts/preproduction-refresh/governed-successor/'
    'remote-server-to-server-worker.py'
)
HELPER_SOURCE = Path(
    'scripts/preproduction-staging-import/privileged/'
    'agency-preprod-staging-db'
)


def load_worker():
    spec = importlib.util.spec_from_file_location(
        'agency_951_server_to_server_worker',
        WORKER,
    )
    if spec is None or spec.loader is None:
        raise AssertionError('Worker fixture cannot be loaded.')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def main() -> int:
    worker = load_worker()
    if not HELPER_SOURCE.is_file():
        raise AssertionError('Repository #866 helper source is absent.')

    with tempfile.TemporaryDirectory(prefix='agency-951-') as directory:
        installed = Path(directory) / 'agency-preprod-staging-db'
        shutil.copyfile(HELPER_SOURCE, installed)
        os.chmod(installed, 0o755)

        legacy = importlib.util.spec_from_file_location(
            'agency_preprod_staging_db_legacy',
            installed,
        )
        if legacy is not None:
            raise AssertionError(
                'Legacy suffix-inferred loader unexpectedly accepts extensionless helper.'
            )

        worker.HELPER_PATH = installed
        worker.EXPECTED_HELPER_SHA256 = worker.sha256_file(installed)
        worker.require_root_regular = lambda _path, _mode: None
        helper = worker.load_installed_helper()

        required = (
            'derive_scope',
            'prepare_import_scope',
            'write_restricted_client_file',
            'load_bundle',
            'cleanup_scope',
            'require_absent',
        )
        for name in required:
            if not callable(getattr(helper, name, None)):
                raise AssertionError(f'Proven #866 helper primitive missing: {name}')
        if helper.MARIADB_BIN != '/usr/bin/mariadb':
            raise AssertionError('Loaded helper changed the fixed MariaDB primitive.')
        if helper.MAX_SNAPSHOT_BYTES != 1_099_511_627_776:
            raise AssertionError('Loaded helper changed the bounded snapshot contract.')

    print('ROOT_CAUSE_PROOF=EXTENSIONLESS_SPEC_FROM_FILE_LOCATION_RETURNS_NONE')
    print('FIX_PROOF=SOURCE_FILE_LOADER_LOADS_PROVEN_866_HELPER')
    print('DB_EXECUTION=NONE')
    print('PROD_ACCESS=NONE')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
