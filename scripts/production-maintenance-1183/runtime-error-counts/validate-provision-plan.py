#!/usr/bin/env python3
import hashlib
import json
import re
import sys

if len(sys.argv) != 4:
    raise SystemExit(64)
plan_path, expected_digest, expected_user_hash = sys.argv[1:]
if not re.fullmatch(r'[0-9a-f]{64}', expected_digest):
    raise SystemExit(64)
if not re.fullmatch(r'[0-9a-f]{64}', expected_user_hash):
    raise SystemExit(64)
with open(plan_path, encoding='utf-8') as handle:
    plan = json.load(handle)
required = {
    'STATUS': 'PASS',
    'ISSUE': 1202,
    'TARGET': 'PROD',
    'MODE': 'PLAN',
    'RAW_SUDO_POLICY_EXPOSURE': 'NONE',
    'NONCONFORMANT_OVERWRITE': 'FORBIDDEN',
    'REAL_PROD_MUTATION': 'NONE',
    'CANNOT_BE_APPROVED': 'NO',
}
for key, value in required.items():
    if plan.get(key) != value:
        raise SystemExit(65)
if plan.get('SERVER_USER_SHA256') != expected_user_hash:
    raise SystemExit(65)
if plan.get('PLAN_DIGEST') != expected_digest:
    raise SystemExit(65)
exact = {
    'HELPER_DESTINATION': '/usr/local/sbin/agency-prod-runtime-error-counts',
    'SUDOERS_DESTINATION': '/etc/sudoers.d/agency-prod-runtime-error-counts',
    'HELPER_EXPECTED_OWNER_GROUP_MODE': 'root:root:0755',
    'SUDOERS_EXPECTED_OWNER_GROUP_MODE': 'root:root:0440',
}
for key, value in exact.items():
    if plan.get(key) != value:
        raise SystemExit(65)
for key in ('MAIN_SHA',):
    if not re.fullmatch(r'[0-9a-f]{40}', str(plan.get(key, ''))):
        raise SystemExit(65)
for key in ('HELPER_SOURCE_SHA256', 'RENDERED_SUDOERS_SHA256'):
    if not re.fullmatch(r'[0-9a-f]{64}', str(plan.get(key, ''))):
        raise SystemExit(65)
if not re.fullmatch(r'plan-1202-[1-9][0-9]*-1', str(plan.get('PLAN_ID', ''))):
    raise SystemExit(65)
valid_states = {'ABSENT', 'ALREADY_CONFORMANT', 'NONCONFORMANT', 'UNKNOWN'}
for key in ('HELPER_STATE', 'SUDOERS_STATE', 'INSTALL_STATE'):
    if plan.get(key) not in valid_states:
        raise SystemExit(65)
valid_privileges = {'AVAILABLE', 'UNAVAILABLE', 'UNKNOWN'}
for key in (
    'PRIVILEGED_HELPER_INSTALL',
    'PRIVILEGED_SUDOERS_INSTALL',
    'PRIVILEGED_VISUDO_VALIDATION',
):
    if plan.get(key) not in valid_privileges:
        raise SystemExit(65)
if plan.get('INSTALL_DECISION') not in {
    'INSTALL_REQUIRED',
    'ALREADY_CONFORMANT',
}:
    raise SystemExit(65)
identity_keys = (
    'ISSUE', 'TARGET', 'MODE', 'MAIN_SHA', 'PLAN_ID',
    'SERVER_USER_SHA256', 'HELPER_SOURCE_SHA256',
    'RENDERED_SUDOERS_SHA256', 'HELPER_DESTINATION',
    'SUDOERS_DESTINATION', 'HELPER_EXPECTED_OWNER_GROUP_MODE',
    'SUDOERS_EXPECTED_OWNER_GROUP_MODE', 'HELPER_STATE',
    'SUDOERS_STATE', 'INSTALL_STATE', 'INSTALL_DECISION',
    'PRIVILEGED_HELPER_INSTALL', 'PRIVILEGED_SUDOERS_INSTALL',
    'PRIVILEGED_VISUDO_VALIDATION',
)
identity = {key: plan.get(key) for key in identity_keys}
encoded = json.dumps(identity, sort_keys=True, separators=(',', ':')).encode()
if hashlib.sha256(encoded).hexdigest() != expected_digest:
    raise SystemExit(65)
for key in (
    'MAIN_SHA',
    'PLAN_ID',
    'HELPER_SOURCE_SHA256',
    'RENDERED_SUDOERS_SHA256',
    'HELPER_STATE',
    'SUDOERS_STATE',
    'INSTALL_STATE',
    'INSTALL_DECISION',
):
    print(plan[key])
