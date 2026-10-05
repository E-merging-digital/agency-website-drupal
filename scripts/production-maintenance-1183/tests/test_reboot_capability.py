import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

BASE = Path('scripts/production-maintenance-1183/reboot')
HELPER = (BASE / 'agency-prod-os-maintenance-1183-reboot').read_text()
SUDOERS = (BASE / 'agency-prod-os-maintenance-1183-reboot.sudoers.template').read_text()
RENDER = BASE / 'render-sudoers.sh'
VERIFY = (BASE / 'verify-installed-root.sh').read_text()
TARGET = '6.8.0-139-generic'


class RebootCapabilityTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix='agency-1221-'))
        self.etc = self.tmp / 'etc'
        self.modules = self.tmp / 'lib/modules'
        self.run = self.tmp / 'var/run'
        self.bin = self.tmp / 'bin'
        for directory in (self.etc, self.modules, self.run, self.bin):
            directory.mkdir(parents=True, exist_ok=True)
        (self.etc / 'os-release').write_text('VERSION_ID="24.04"\n')
        (self.modules / TARGET).mkdir()
        (self.run / 'reboot-required').write_text('required\n')
        self.current_kernel = self.tmp / 'current-kernel'
        self.current_kernel.write_text('6.8.0-137-generic\n')
        self.systemctl_log = self.tmp / 'systemctl.log'
        self.env_log = self.tmp / 'env.log'

        uname = self.bin / 'uname'
        uname.write_text(
            '#!/bin/bash\ncat ' + repr(str(self.current_kernel)) + '\n'
        )
        systemctl = self.bin / 'systemctl'
        systemctl.write_text(
            '#!/bin/bash\n'
            'printf "%s\\n" "$*" > ' + repr(str(self.systemctl_log)) + '\n'
            'printf "%s\\n" "${ATTACKER_CONTROLLED-unset}" > ' + repr(str(self.env_log)) + '\n'
        )
        uname.chmod(0o700)
        systemctl.chmod(0o700)
        script = HELPER
        script = script.replace('/etc/os-release', str(self.etc / 'os-release'))
        script = script.replace('/lib/modules/', str(self.modules) + '/')
        script = script.replace('/var/run/reboot-required', str(self.run / 'reboot-required'))
        script = script.replace('/usr/bin/uname', str(uname))
        script = script.replace('/usr/bin/systemctl', str(systemctl))
        script = script.replace('[[ "$EUID" -eq 0 ]] || fail', 'true')
        self.helper = self.tmp / 'helper'
        self.helper.write_text(script)
        self.helper.chmod(0o700)

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def run_helper(self, *args):
        env = os.environ.copy()
        env['ATTACKER_CONTROLLED'] = 'sentinel'
        return subprocess.run(
            ['bash', str(self.helper), *args],
            capture_output=True,
            text=True,
            env=env,
        )

    def assert_failed_without_reboot(self, result):
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, 'STATUS=FAIL\n')
        self.assertFalse(self.systemctl_log.exists())

    def test_success_invokes_exact_reboot_with_cleared_environment(self):
        result = self.run_helper()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.systemctl_log.read_text(), 'reboot\n')
        self.assertEqual(self.env_log.read_text(), 'unset\n')

    def test_arguments_are_forbidden(self):
        self.assert_failed_without_reboot(self.run_helper('nginx'))

    def test_wrong_os_is_forbidden(self):
        (self.etc / 'os-release').write_text('VERSION_ID="26.04"\n')
        self.assert_failed_without_reboot(self.run_helper())

    def test_missing_target_kernel_is_forbidden(self):
        shutil.rmtree(self.modules / TARGET)
        self.assert_failed_without_reboot(self.run_helper())

    def test_target_already_running_is_forbidden(self):
        self.current_kernel.write_text(TARGET + '\n')
        self.assert_failed_without_reboot(self.run_helper())

    def test_missing_or_symlinked_reboot_marker_is_forbidden(self):
        marker = self.run / 'reboot-required'
        marker.unlink()
        self.assert_failed_without_reboot(self.run_helper())
        marker.symlink_to(self.tmp / 'attacker-marker')
        (self.tmp / 'attacker-marker').write_text('required\n')
        self.assert_failed_without_reboot(self.run_helper())

    def test_helper_surface_is_fixed_purpose(self):
        self.assertIn("TARGET_KERNEL='6.8.0-139-generic'", HELPER)
        self.assertIn('exec -c /usr/bin/systemctl reboot', HELPER)
        self.assertNotIn('${1:-}', HELPER)
        self.assertNotIn('/bin/sh', HELPER)
        self.assertNotIn('/bin/bash -c', HELPER)
        self.assertNotIn('/usr/bin/env', HELPER)
        self.assertNotIn('apt-get', HELPER)

    def test_sudoers_is_exact_helper_only(self):
        expected = '__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-os-maintenance-1183-reboot\n'
        self.assertEqual(SUDOERS, expected)
        self.assertNotIn('NOPASSWD: ALL', SUDOERS)
        self.assertNotIn(' SETENV:', SUDOERS)
        self.assertNotIn('/usr/bin/systemctl', SUDOERS)
        self.assertNotIn('/bin/sh', SUDOERS)
        self.assertNotIn('/bin/bash', SUDOERS)
        self.assertNotIn('/usr/bin/env', SUDOERS)
        self.assertNotIn('python', SUDOERS)
        self.assertNotIn('apt-get', SUDOERS)

    def test_renderer_derives_user_without_committing_deploy(self):
        result = subprocess.run(
            ['bash', str(RENDER), 'agency-prod-user'],
            capture_output=True,
            text=True,
            check=True,
        )
        self.assertEqual(
            result.stdout,
            'agency-prod-user ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-os-maintenance-1183-reboot\n',
        )
        self.assertNotIn('deploy', SUDOERS)
        self.assertIn("root:root:755", VERIFY)
        self.assertIn("root:root:440", VERIFY)


if __name__ == '__main__':
    unittest.main()
