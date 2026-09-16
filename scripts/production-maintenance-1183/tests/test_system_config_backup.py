"""Deterministic #1215 tests: local fixtures only, never remote scripts/real sudo."""
import contextlib
import io
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
HELPER = (BASE / 'system-config-backup/agency-prod-system-config-backup').read_text()
PLAN = (BASE / 'remote-plan.sh').read_text()
APPLY = (BASE / 'remote-apply.sh').read_text()
EVALUATOR = PLAN.split("python3 - <<'PY'\n")[1].rsplit('\nPY', 1)[0]
FUNCTION = EVALUATOR[EVALUATOR.index('def probe_exact_sudo('):EVALUATOR.index('upgradable = []')]
COMMANDS = {
    'SYSTEM_CONFIG_BACKUP': ['/usr/local/sbin/agency-prod-system-config-backup'],
    'APT_UPDATE': ['/usr/bin/apt-get', 'update'],
    'APT_SIMULATE': ['/usr/bin/apt-get', '--simulate', 'install', '--only-upgrade', 'nginx=1.2:3~4+5-6'],
    'APT_INSTALL': ['/usr/bin/apt-get', 'install', '-y', '--only-upgrade', 'nginx=1.2:3~4+5-6'],
    'NGINX_TEST': ['/usr/sbin/nginx', '-t'],
    'PHP_FPM_TEST': ['/usr/sbin/php-fpm8.4', '-t'],
    'NGINX_ACTIVE': ['/usr/bin/systemctl', 'is-active', '--quiet', 'nginx'],
    'PHP_FPM_ACTIVE': ['/usr/bin/systemctl', 'is-active', '--quiet', 'php8.4-fpm'],
    'MARIADB_ACTIVE': ['/usr/bin/systemctl', 'is-active', '--quiet', 'mariadb'],
    'REBOOT': ['/usr/bin/systemctl', 'reboot'],
    'RUNTIME_ERROR_HELPER': ['/usr/local/sbin/agency-prod-runtime-error-counts'],
}


def policy(args, options='!authenticate, !setenv'):
    command = ' '.join(args)
    return f'Sudoers entry:\n    RunAsUsers: root\n    Options: {options}\n    Commands:\n        {command}\n    Matched: {command}\n'


class BackupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.backups = self.root / 'var/www/agency/shared/backups'
        self.backups.mkdir(parents=True)
        for source in ['nginx', 'php/8.4', 'mysql']:
            directory = self.root / 'etc' / source
            directory.mkdir(parents=True)
            (directory / 'config').write_text('PRIVATE_CONFIGURATION_SENTINEL')
        # Only the disposable copy is redirected to fixture directories/tools.
        script = HELPER.replace('/var', str(self.root) + '/var').replace('/etc', str(self.root) + '/etc')
        script = script.replace('/usr/bin/tar -C / ', f'/usr/bin/tar -C {self.root} ')
        script = script.replace('[[ "$EUID" -eq 0 ]]', 'true')
        script = script.replace('/usr/bin/chown root:root', '/usr/bin/true')
        script = script.replace('/usr/bin/date -u +%Y%m%dT%H%M%SZ', 'printf 20260916T120000Z')
        self.helper = self.root / 'helper'
        self.helper.write_text(script)

    def run_helper(self, *args):
        return subprocess.run(['bash', str(self.helper), *args], capture_output=True, text=True)

    def test_success_fixed_sources_private_archive_and_no_overwrite(self):
        # Normal internal config symlinks are archived as links, never followed.
        (self.root / 'etc/nginx/link').symlink_to('/private/outside')
        with patch.dict(os.environ, {'TAR_OPTIONS': '--files-from=/private/arbitrary-source', 'GZIP': '--invalid'}):
            result = self.run_helper()
        self.assertEqual(result.returncode, 0, result.stderr)
        lines = result.stdout.splitlines()
        self.assertEqual(len(lines), 4)
        self.assertEqual(lines[0], 'STATUS=PASS')
        archive = self.backups / 'pre-os-maintenance-1183-20260916T120000Z-system-config.tar.gz'
        self.assertEqual(lines[1], 'SYSTEM_CONFIG_BACKUP=' + str(archive))
        self.assertEqual(lines[2], 'SYSTEM_CONFIG_BACKUP_SHA256=' + hashlib.sha256(archive.read_bytes()).hexdigest())
        self.assertEqual(lines[3], 'SYSTEM_CONFIG_BACKUP_SIZE=' + str(archive.stat().st_size))
        self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
        with tarfile.open(archive) as tar:
            self.assertEqual(set(tar.getnames()), {'etc/nginx', 'etc/nginx/config', 'etc/nginx/link', 'etc/php/8.4', 'etc/php/8.4/config', 'etc/mysql', 'etc/mysql/config'})
            self.assertTrue(tar.getmember('etc/nginx/link').issym())
        before = archive.read_bytes()
        self.assert_failed(self.run_helper())
        self.assertEqual(before, archive.read_bytes())
        self.assertEqual(list(self.backups.glob('.system-config*')), [])
        self.assertNotIn('PRIVATE_CONFIGURATION_SENTINEL', result.stdout + result.stderr)

    def assert_failed(self, result):
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, 'STATUS=FAIL\n')
        self.assertEqual(result.stderr, '')

    def test_arguments_rejected(self):
        for args in [('etc/passwd',), ('/tmp/arbitrary.tar.gz',), ('--source', '/etc/shadow'), ('--destination', '/tmp/a')]:
            self.assert_failed(self.run_helper(*args))
        self.assertEqual(list(self.backups.iterdir()), [])

    def test_symlink_missing_and_non_directory_fail_closed(self):
        for target in [self.backups, self.root / 'etc/nginx', self.root / 'etc/php/8.4', self.root / 'etc/mysql']:
            saved = target.with_name(target.name + '-saved')
            target.rename(saved)
            self.assert_failed(self.run_helper())
            target.symlink_to(saved)
            self.assert_failed(self.run_helper())
            target.unlink()
            target.write_text('PRIVATE_CONFIGURATION_SENTINEL')
            self.assert_failed(self.run_helper())
            target.unlink()
            saved.rename(target)

    def test_apply_receipt_parser_rejects_malformed_and_symlink_archives(self):
        archive = self.backups / 'pre-os-maintenance-1183-20260916T120000Z-system-config.tar.gz'
        archive.write_bytes(b'archive')
        archive.chmod(0o600)
        block = APPLY.split('# BEGIN #1215 BACKUP RECEIPT\n')[1].split('# END #1215 BACKUP RECEIPT')[0]
        block = block.replace('/var/www/agency/shared/backups', str(self.backups))
        block = block.replace('$config_backup_size:0:0:600', f'$config_backup_size:{os.getuid()}:{os.getgid()}:600')
        runner = ('set -Eeuo pipefail\n'
                  'sudo() { [[ "$*" == "-n -- fixture-helper" ]] || return 99; printf "%s" "$RECEIPT"; return "$RC"; }\n' + block)
        good = f'STATUS=PASS\nSYSTEM_CONFIG_BACKUP={archive}\nSYSTEM_CONFIG_BACKUP_SHA256={"a" * 64}\nSYSTEM_CONFIG_BACKUP_SIZE=7\n'
        def run(receipt, rc=0):
            return subprocess.run(['bash', '-c', runner], text=True, capture_output=True,
                                  env={**os.environ, 'work_root': str(self.root), 'SYSTEM_CONFIG_BACKUP_HELPER': 'fixture-helper', 'RECEIPT': receipt, 'RC': str(rc)})
        self.assertEqual(run(good).returncode, 0)
        for bad in [good + 'PRIVATE\n', good.rstrip('\n'), good + '\n', good.replace('SIZE=7', 'SIZE=0'), good.replace('SIZE=7', 'SIZE=8'), good.replace('SIZE=7', 'SIZE=' + '9' * 20), good.replace('a' * 64, 'bad-hash'), good.replace(str(archive), '/tmp/arbitrary'), 'STATUS=FAIL\n']:
            result = run(bad)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(result.stdout + result.stderr, '')
        self.assertNotEqual(run(good, 1).returncode, 0)
        saved = archive.with_suffix('.saved')
        archive.rename(saved)
        archive.symlink_to(saved)
        self.assertNotEqual(run(good).returncode, 0)

    def test_production_contract_and_sudoers(self):
        for text in ['set -Eeuo pipefail', 'umask 077', "PATH='/usr/sbin:/usr/bin:/sbin:/bin'", 'LC_ALL=C', "BACKUP_ROOT='/var/www/agency/shared/backups'", 'etc/nginx etc/php/8.4 etc/mysql', '/usr/bin/chown root:root', '/usr/bin/chmod 0600', '/usr/bin/ln -T']:
            self.assertIn(text, HELPER)
        directory = BASE / 'system-config-backup'
        expected = '__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-system-config-backup\n'
        self.assertEqual((directory / 'agency-prod-system-config-backup.sudoers.template').read_text(), expected)
        result = subprocess.run(['bash', str(directory / 'render-sudoers.sh'), 'agency-user'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stdout, expected.replace('__SERVER_USER__', 'agency-user'))
        for arg in ['user ALL', 'user\nroot', '*']:
            self.assertNotEqual(subprocess.run(['bash', str(directory / 'render-sudoers.sh'), arg], capture_output=True).returncode, 0)
        self.assertNotRegex(expected, r'NOPASSWD: ALL|\bSETENV:|\*|/tar|/bash|/env|/python')


class PrivilegeTest(unittest.TestCase):
    def test_exact_private_parser(self):
        namespace = {'os': os, 're': re, 'subprocess': subprocess}
        exec(FUNCTION, namespace)
        for args in COMMANDS.values():
            for stdout, stderr, rc, state in [
                (policy(args), '', 0, 'AVAILABLE'),
                (policy(args, 'authenticate'), '', 0, 'UNAVAILABLE'),
                (policy(args, '!authenticate, setenv'), '', 0, 'UNKNOWN'),
                (policy(args) * 2, '', 0, 'UNKNOWN'),
                (policy(['/bin/sh']), '', 0, 'UNKNOWN'),
                ('PRIVATE_POLICY', 'PRIVATE_ERROR', 1, 'UNKNOWN'),
                ('', "sudo: a password is required\n", 1, 'UNKNOWN'),
                ('', f"sudo: Sorry, user fixture is not allowed to execute '{' '.join(args)}' as root on fixture.\n", 1, 'UNAVAILABLE'),
            ]:
                with patch.object(subprocess, 'run', return_value=subprocess.CompletedProcess([], rc, stdout, stderr)) as run:
                    actual, reason = namespace['probe_exact_sudo'](args)
                self.assertEqual(actual, state)
                self.assertRegex(reason, r'^[A-Z_]+$')
                self.assertNotIn('PRIVATE', actual + reason)
                self.assertEqual(run.call_args.args[0], ['sudo', '-k', '-n', '-ll', '--', *args])
                self.assertTrue(run.call_args.kwargs['capture_output'])

    def test_every_required_privilege_and_no_package_exception(self):
        block = EVALUATOR.split('# BEGIN #1215 EXACT PRIVILEGE AUDIT\n')[1].split('# END #1215 EXACT PRIVILEGE AUDIT')[0]
        for packages in [[], [{'name': 'nginx', 'to': '1.2:3~4+5-6'}]]:
            for denied in COMMANDS:
                for state in ['AVAILABLE', 'UNAVAILABLE', 'UNKNOWN']:
                    calls = []
                    def probe(args):
                        calls.append(args)
                        return (state, 'POLICY_EMPTY' if state == 'UNKNOWN' else 'NONE') if args == COMMANDS[denied] else ('AVAILABLE', 'NONE')
                    namespace = {'upgrades': packages, 're': re, 'checks': {}, 'probe_exact_sudo': probe}
                    exec(block, namespace)
                    required = bool(packages) or not denied.startswith('APT_')
                    self.assertEqual(all(namespace['checks'].values()), state == 'AVAILABLE' or not required, (denied, state, packages))
                    if not packages:
                        for name in ['APT_UPDATE', 'APT_SIMULATE', 'APT_INSTALL']:
                            self.assertEqual(namespace['privileges']['PRIVILEGED_' + name], 'UNKNOWN')
                            self.assertEqual(namespace['privilege_reasons']['WHY_UNKNOWN_' + name], 'NOT_REQUIRED')
                        self.assertFalse(any(args[0] == '/usr/bin/apt-get' for args in calls))
                    for name in COMMANDS:
                        self.assertIn('.PRIVILEGED_' + name + ' == "AVAILABLE"', APPLY)
        self.assertIn('mutation_identity.update(privileges)', EVALUATOR)
        digest = EVALUATOR.split('mutation_identity_keys = (')[1]
        self.assertNotIn('privilege_reasons', digest)
        self.assertNotIn('WHY_UNKNOWN_', digest)

    def test_full_plan_fail_closed_and_digest(self):
        # Reuse the existing #1183 healthy environment fixture verbatim.
        repository = BASE.parents[1]
        fixture = (repository / 'web/modules/custom/agency_project_tests/tests/src/Unit/ProdOsMaintenance1183WorkflowTest.php').read_text()
        environment = dict(re.findall(r"'([A-Z_]+)' => '([^']*)'", fixture))
        environment.update(MAIN_SHA='a' * 40, DISK_AVAILABLE_KB='13696200')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            environment['WORK_ROOT'] = directory
            (root / 'upgradable.raw').write_text('Listing...\nnginx/noble-updates 1.2:3~4+5-6 amd64 [upgradable from: 1.0]\n')
            for name in ['upgrade-sim.raw', 'upgrade-sim-phased.raw']:
                (root / name).write_text('Inst nginx [1.0] (1.2:3~4+5-6 Ubuntu [amd64])\n')
            def evaluate(denied=None, state='AVAILABLE', error=''):
                def sudo(args, **kwargs):
                    command = args[5:]
                    if denied and command == COMMANDS[denied]:
                        output = policy(command, 'authenticate') if state == 'UNAVAILABLE' else ''
                        return subprocess.CompletedProcess(args, 0, output, error)
                    return subprocess.CompletedProcess(args, 0, policy(command), '')
                out, err = io.StringIO(), io.StringIO()
                with patch.dict(os.environ, environment), patch.object(subprocess, 'run', side_effect=sudo), contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                    try:
                        exec(EVALUATOR, {})
                        status = 0
                    except SystemExit as exc:
                        status = exc.code
                return status, json.loads(out.getvalue())
            status, receipt = evaluate()
            self.assertEqual(status, 0)
            self.assertEqual(receipt['STATUS'], 'PASS')
            self.assertRegex(receipt['PLAN_DIGEST'], r'^[0-9a-f]{64}$')
            for name in COMMANDS:
                for state in ['UNAVAILABLE', 'UNKNOWN']:
                    status, receipt = evaluate(name, state)
                    self.assertEqual(status, 2, (name, state))
                    self.assertEqual(receipt['STATUS'], 'FAIL')
                    self.assertEqual(receipt['CANNOT_BE_APPROVED'], 'YES')
                    self.assertIsNone(receipt['PLAN_DIGEST'])
                    self.assertIn('privileged_' + name.lower(), receipt['FAILED_CHECKS'])
                    self.assertEqual(receipt['PRIVILEGED_' + name], state)
            for name in ['upgradable.raw', 'upgrade-sim.raw', 'upgrade-sim-phased.raw']:
                (root / name).write_text('')
            status, receipt = evaluate()
            self.assertEqual(status, 0)
            for name in ['APT_UPDATE', 'APT_SIMULATE', 'APT_INSTALL']:
                self.assertEqual(receipt['WHY_UNKNOWN_' + name], 'NOT_REQUIRED')

    def test_backup_ordering_absolute_commands_and_consumed_authority(self):
        positions = [APPLY.index(s) for s in ['vendor/bin/drush sql:dump', 'sudo -n -- "$SYSTEM_CONFIG_BACKUP_HELPER"', 'state:set system.maintenance_mode 1', 'sudo -n -- /usr/bin/apt-get update']]
        self.assertEqual(positions, sorted(positions))
        for source in [APPLY, (BASE / 'remote-post-reboot.sh').read_text()]:
            self.assertNotRegex(source, r'sudo -n (?:tar|test|apt-get|nginx|php-fpm8.4|systemctl)\b')
        for file in BASE.rglob('*'):
            if file.is_file() and 'tests' not in file.parts:
                self.assertNotIn('35147468119', file.read_text())
        self.assertIn('SYSTEM_CONFIG_BACKUP_SHA256:$config_backup_sha256', APPLY)
        self.assertIn('SYSTEM_CONFIG_BACKUP_SIZE:($config_backup_size|tonumber)', APPLY)


if __name__ == '__main__':
    unittest.main()
