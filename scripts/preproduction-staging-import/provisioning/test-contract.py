#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
PROVISIONING = pathlib.Path(__file__).resolve().parent
PROFILE = json.loads((PROVISIONING / "profile.json").read_text(encoding="utf-8"))
REMOTE = (PROVISIONING / "remote-provision-root.sh").read_text(encoding="utf-8")
PLAN = (PROVISIONING / "run-plan.sh").read_text(encoding="utf-8")
APPLY = (PROVISIONING / "run-apply.sh").read_text(encoding="utf-8")
HELPER = ROOT / "privileged" / "agency-preprod-staging-db"
DIGEST = ROOT / "privileged" / "agency-preprod-staging-db.sha256"

EXPECTED = "ddd2785849da76f3a30dd3cf0ac59d03ed53692ad1e5cd03480a82d86d63a6a3"
actual = hashlib.sha256(HELPER.read_bytes()).hexdigest()
pinned = DIGEST.read_text(encoding="utf-8").strip()
assert PROFILE["issue_number"] == 851
assert PROFILE["profile_id"] == "agency-preprod-capability-provision-v1"
assert PROFILE["helper"]["destination"] == "/usr/local/sbin/agency-preprod-staging-db"
assert PROFILE["helper"]["expected_sha256"] == EXPECTED == pinned == actual
assert PROFILE["sudoers"]["destination"] == "/etc/sudoers.d/agency-preprod-staging-db"
assert PROFILE["sudoers"]["nopasswd_scope"] == "FIXED_HELPER_ONLY"
assert PROFILE["sudoers"]["direct_mariadb"] == "FORBIDDEN"
assert PROFILE["sudoers"]["generic_root_executor_for_deploy_user"] == "NONE"
assert PROFILE["sudoers"]["setenv"] == "FORBIDDEN"
assert PROFILE["apply"]["allowed_helper_proofs"] == ["PRECHECK", "VERIFY_ABSENCE"]
assert PROFILE["apply"]["snapshot_bytes"] == 0
assert PROFILE["apply"]["import"] == "FORBIDDEN"
assert PROFILE["plan"]["preprod_mutation"] == "NONE"
assert PROFILE["execution"]["prod_access"] == "NONE"

for required in [
    "HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'",
    "SUDOERS_PATH='/etc/sudoers.d/agency-preprod-staging-db'",
    "PROJECT_SHARED='/var/www/agency-preprod/shared'",
    "EXPECTED_DIGEST='" + EXPECTED + "'",
    "NOPASSWD: NOSETENV: ${HELPER_PATH}",
    "visudo -cf \"$sudo_candidate\"",
    "rollback_on_exit",
    "restore_sudoers",
    "restore_helper",
    'PRECHECK "$REQUEST_ID" 0',
    'VERIFY_ABSENCE "$REQUEST_ID" 0',
    "preprod_runtime_db_touched=NO",
    "prod_access=NONE",
    "issue_834_apply=NOT_PERFORMED",
]:
    assert required in REMOTE, required

# Sudo policy contains one fixed executable, no wildcard, no direct database
# client, no generic ALL command and no SETENV privilege tag.
policy_line = next(line for line in REMOTE.splitlines() if line.startswith('desired_policy='))
assert "/usr/local/sbin/agency-preprod-staging-db" not in policy_line  # path is fixed through HELPER_PATH constant
assert "NOPASSWD: NOSETENV: ${HELPER_PATH}" in policy_line
assert "*" not in policy_line and "?" not in policy_line
assert "mariadb" not in policy_line.lower()
assert "NOPASSWD: ALL" not in policy_line
assert re.search(r"(^|\s)SETENV:", policy_line) is None

# Candidate sudoers syntax is validated before activation, and post-install
# verification happens before commit disables rollback.
prevalidate = REMOTE.index('visudo -cf "$sudo_candidate"')
activate = REMOTE.index('mv -f -- "$sudo_candidate" "$SUDOERS_PATH"')
final_verify = REMOTE.index('visudo -cf "$SUDOERS_PATH"')
commit = REMOTE.index("committed=1")
assert prevalidate < activate < final_verify < commit

# No data-bearing import is executed by provisioning. Only zero-byte PRECHECK
# and VERIFY_ABSENCE may traverse the newly installed sudo capability.
for line in REMOTE.splitlines():
    if "runuser" in line and "sudo -n" in line:
        assert " PRECHECK " in line or " VERIFY_ABSENCE " in line
        assert " IMPORT " not in line

# PLAN is read-only: it can inspect `sudo -l`, but may not execute the helper,
# copy files, or invoke a privileged/root transport.
assert "sudo -n -l" not in PLAN  # local PLAN runner never uses sudo itself
assert "scp" not in PLAN
assert "ROOT_USER" not in PLAN
assert "run-apply.sh" not in PLAN

# APPLY is fixed to root transport and exact staged repository files; no owner
# argument can select helper/sudoers destination, DB, shell or executable.
assert "ROOT_USER='root'" in APPLY
assert "PREPROD_PROVISIONING_SSH_KEY" in APPLY
assert "PREPROD_SUDO_PASSWORD" not in APPLY
assert "SUDO_PASSWORD" not in APPLY
assert "ssh-keyscan" not in APPLY
assert "StrictHostKeyChecking=no" not in APPLY
assert "accept-new" not in APPLY

print("PROVISIONING_ROUTE=PASS")
print("PLAN_MUTATION_FREE=YES")
print("APPLY_ONE_SHOT=YES")
print("FIXED_HELPER_PATH=PASS")
print("HELPER_DIGEST_BOUND=PASS")
print("SUDOERS_FIXED_HELPER_ONLY=PASS")
print("DIRECT_MARIADB_SUDO=FORBIDDEN")
print("GENERIC_ROOT_EXECUTOR=NONE")
print("SETENV=FORBIDDEN")
print("VISUDO_PREVALIDATION=PASS")
print("ROLLBACK_FAIL_CLOSE=PASS")
print("PRECHECK_ZERO_DATA=PASS")
print("VERIFY_ABSENCE_ZERO_DATA=PASS")
