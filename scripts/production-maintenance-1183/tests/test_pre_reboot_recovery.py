"""Deterministic #1228 pre-reboot failure recovery tests; no PROD access."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

BASE = Path(__file__).resolve().parents[1]
APPLY = (BASE / 'remote-apply.sh').read_text()
WORKFLOW = (BASE.parents[1] / '.github/workflows/prod-os-maintenance-1183.yml').read_text()
BLOCK = APPLY.split('# BEGIN #1228 PRE-REBOOT FAILURE RECOVERY\n')[1].split('# END #1228 PRE-REBOOT FAILURE RECOVERY')[0]

STAGES = [
    'CACHE_REBUILD_AFTER_MAINTENANCE_ON',
    'MAINTENANCE_MODE_VERIFY_ON',
    'NGINX_ACTIVE_CHECK',
    'PHP_FPM_ACTIVE_CHECK',
    'MARIADB_ACTIVE_CHECK',
    'MAX_ALLOWED_PACKET_CHECK',
    'RUNNING_KERNEL_INVARIANT',
    'TARGET_KERNEL_INVARIANT',
    'REBOOT_REQUIRED_INVARIANT',
    'PRE_REBOOT_RECEIPT',
]


class PreRebootRecoveryTest(unittest.TestCase):
    def run_recovery(self, stage, *, state_rc=0, cr_rc=0, verify_rc=0, maintenance='0', boundary='NO'):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            receipt = root / 'pre-reboot.json'
            calls = root / 'calls.log'
            script = root / 'harness.sh'
            script.write_text(f'''#!/usr/bin/env bash
set -Eeuo pipefail
CURRENT_ROOT=/fixture
{BLOCK}
main_sha={'a' * 40!r}
plan_id='plan-1183-fixture-r1'
EXPECTED_DIGEST={'b' * 64!r}
SNAPSHOT_REF='snapshot-fixture-1183'
WINDOW_REF='2026-09-17T22:05+02:00/2026-09-17T23:05+02:00'
db_backup='/var/www/agency/shared/backups/db.sql.gz'
config_backup='/var/www/agency/shared/backups/system-config.tar.gz'
config_backup_sha256={'c' * 64!r}
config_backup_size='123'
receipt_file={str(receipt)!r}
maintenance_entered='YES'
reboot_boundary_crossed={boundary!r}
current_stage={stage!r}
drush_current() {{
  printf '%s\\n' "$*" >> {str(calls)!r}
  case "$1 ${{2:-}}" in
    'state:set system.maintenance_mode') return {state_rc} ;;
    'cr ') return {cr_rc} ;;
    'state:get system.maintenance_mode')
      if [[ {verify_rc} -eq 0 ]]; then printf '%s\\n' {maintenance!r}; fi
      return {verify_rc}
      ;;
    *) return 99 ;;
  esac
}}
handle_pre_reboot_failure 42
''')
            result = subprocess.run(['bash', str(script)], capture_output=True, text=True)
            payload = json.loads(receipt.read_text())
            call_lines = calls.read_text().splitlines() if calls.exists() else []
            return result, payload, call_lines

    def test_every_material_pre_reboot_stage_recovers_and_preserves_original_failure(self):
        for stage in STAGES:
            with self.subTest(stage=stage):
                result, payload, calls = self.run_recovery(stage)
                self.assertEqual(result.returncode, 42, result.stderr)
                self.assertEqual(payload['STATUS'], 'FAIL')
                self.assertEqual(payload['FAILURE_PHASE'], 'PRE_REBOOT')
                self.assertEqual(payload['FAILURE_STAGE'], stage)
                self.assertEqual(payload['ORIGINAL_EXIT_STATUS'], 42)
                self.assertEqual(payload['MAINTENANCE_RECOVERY_ATTEMPTED'], 'YES')
                self.assertEqual(payload['MAINTENANCE_RECOVERY_RESULT'], 'PASS')
                self.assertEqual(payload['MAINTENANCE_MODE_FINAL'], 'OFF')
                self.assertEqual(payload['REBOOT_HELPER_INVOKED'], 'NO')
                self.assertEqual(payload['REBOOT_BOUNDARY_CROSSED'], 'NO')
                self.assertEqual(payload['PACKAGE_APPLY'], 'NONE')
                self.assertEqual(payload['REAL_PACKAGE_MUTATION'], 'NONE')
                self.assertEqual(payload['SNAPSHOT_RESTORE'], 'NONE')
                self.assertEqual(payload['BACKUPS_PRESERVED'], 'YES')
                self.assertEqual(calls, [
                    'state:set system.maintenance_mode 0 --input-format=integer',
                    'cr',
                    'state:get system.maintenance_mode',
                ])
                self.assertTrue(result.stdout.strip().startswith('{'))

    def test_cleanup_failure_never_overwrites_original_failure_identity(self):
        result, payload, calls = self.run_recovery('MAX_ALLOWED_PACKET_CHECK', state_rc=9)
        self.assertEqual(result.returncode, 42)
        self.assertEqual(payload['FAILURE_STAGE'], 'MAX_ALLOWED_PACKET_CHECK')
        self.assertEqual(payload['ORIGINAL_EXIT_STATUS'], 42)
        self.assertEqual(payload['MAINTENANCE_RECOVERY_RESULT'], 'FAIL')
        self.assertEqual(payload['MAINTENANCE_MODE_FINAL'], 'OFF')
        self.assertEqual(len(calls), 3)

    def test_reboot_boundary_disables_maintenance_cleanup(self):
        result, payload, calls = self.run_recovery('REBOOT_HELPER_INVOCATION', boundary='YES')
        self.assertEqual(result.returncode, 42)
        self.assertEqual(calls, [])
        self.assertEqual(payload['MAINTENANCE_RECOVERY_ATTEMPTED'], 'NO')
        self.assertEqual(payload['MAINTENANCE_RECOVERY_RESULT'], 'NOT_REQUIRED')
        self.assertEqual(payload['MAINTENANCE_MODE_FINAL'], 'UNKNOWN')
        boundary = APPLY.index("reboot_boundary_crossed='YES'")
        trap_off = APPLY.index('trap - ERR', boundary)
        helper = APPLY.index('sudo -n -- "$REBOOT_HELPER"', trap_off)
        self.assertLess(boundary, trap_off)
        self.assertLess(trap_off, helper)

    def test_stage_wiring_and_reboot_only_contract(self):
        for stage in STAGES:
            self.assertIn(f"current_stage='{stage}'", APPLY)
        self.assertIn("maintenance_entered='YES'", APPLY)
        self.assertIn("current_stage='MAINTENANCE_MODE_VERIFY_ON'", APPLY)
        self.assertIn("current_stage='MAX_ALLOWED_PACKET_CHECK'", APPLY)
        self.assertIn("current_stage='RUNNING_KERNEL_INVARIANT'", APPLY)
        self.assertGreaterEqual(APPLY.count('set +E'), 3)
        self.assertGreaterEqual(APPLY.count('set -E'), 3)
        self.assertIn("trap 'handle_pre_reboot_failure \"$?\"' ERR", APPLY)
        self.assertIn('state:set system.maintenance_mode 0', APPLY)
        self.assertIn('drush_current cr', APPLY)
        self.assertIn('MAINTENANCE_RECOVERY_RESULT', APPLY)
        self.assertIn('STATUS:"PASS"', APPLY)
        self.assertNotIn('sudo -n -- /usr/bin/apt-get', APPLY)
        self.assertNotIn('sudo -n -- /usr/bin/systemctl reboot', APPLY)
        self.assertNotIn('sudo -n -- /usr/bin/systemctl is-active', APPLY)
        self.assertIn("REBOOT_HELPER='/usr/local/sbin/agency-prod-os-maintenance-1183-reboot'", APPLY)
        self.assertIn('PACKAGE_APPLY:"NONE"', APPLY)
        self.assertIn('REAL_PACKAGE_MUTATION:"NONE"', APPLY)

    def test_workflow_validates_failure_receipt_and_keeps_operation_failed(self):
        for text in [
            "pre_status=\"$(jq -er '.STATUS' \"$pre\")\"",
            '.FAILURE_PHASE == "PRE_REBOOT"',
            '.REBOOT_HELPER_INVOKED == "NO"',
            '.MAINTENANCE_RECOVERY_ATTEMPTED == "YES"',
            '.ORIGINAL_EXIT_STATUS | type == "number" and . > 0',
            'test "$apply_rc" -ne 0',
            'exit 1',
            'if: ${{ always() }}',
        ]:
            self.assertIn(text, WORKFLOW)


if __name__ == '__main__':
    unittest.main()
