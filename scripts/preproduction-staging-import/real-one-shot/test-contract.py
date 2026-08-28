#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
BASE = Path(__file__).resolve().parent
PROFILE = json.loads((BASE / 'profile.json').read_text(encoding='utf-8'))
PLAN = (BASE / 'run-plan.sh').read_text(encoding='utf-8')
APPLY = (BASE / 'run-apply.sh').read_text(encoding='utf-8')
PROD_PLAN = (BASE / 'remote-prod-plan-readonly.sh').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github/workflows/preprod-866-real-one-shot.yml').read_text(encoding='utf-8')
STAGE_REMOTE = (ROOT / 'scripts/preproduction-staging-import/remote-preprod-stage.sh').read_text(encoding='utf-8')
PROD_STREAM = (ROOT / 'scripts/production-readonly-snapshot/remote-stream.sh').read_text(encoding='utf-8')
HELPER = (ROOT / 'scripts/preproduction-staging-import/privileged/agency-preprod-staging-db').read_text(encoding='utf-8')

assert PROFILE['issue_number'] == 866
assert PROFILE['parent_issue'] == 816
assert PROFILE['profile_id'] == 'agency-preprod-real-one-shot-sanitize-v1'
assert PROFILE['request_namespace']['plan_prefix'] == 'plan-866-'
assert PROFILE['request_namespace']['apply_prefix'] == 'apply-866-'
assert PROFILE['request_namespace']['plan_apply_distinct'] is True
assert PROFILE['prod']['role'] == 'READ_ONLY_SNAPSHOT_SOURCE_ONLY'
assert PROFILE['prod']['write'] == 'FORBIDDEN'
assert PROFILE['capability']['apply_action'] == 'IMPORT_SANITIZE_PROVE'
assert PROFILE['capability']['detached_import'] == 'FORBIDDEN'
assert PROFILE['capability']['helper_sha256'] == 'a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'
assert PROFILE['capability']['sanitizer_sha256'] == 'fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f'
assert PROFILE['capability']['policy_sha256'] == 'cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb'
assert PROFILE['capability']['policy_version'] == 'agency-preprod-refresh-v1'
assert PROFILE['preprod']['runtime_db_switch'] == 'FORBIDDEN'
assert PROFILE['preprod']['activation'] == 'FORBIDDEN'
assert PROFILE['transfer']['raw_temp_mode'] == '0600'
assert PROFILE['transfer']['raw_github_artifact'] == 'FORBIDDEN'
assert PROFILE['transfer']['raw_log_output'] == 'FORBIDDEN'
assert PROFILE['transfer']['cleanup_mandatory'] is True

for token in [
    '[[ "$SOURCE_PROD_RELEASE_SHA" == \'AUTO\'',
    'prod_db_content_read=NONE',
    'prod_snapshot=NOT_PERFORMED',
    'prod_data_transfer=NONE',
    'preprod_mutation=NONE',
    'runtime_db_switch=NONE',
    'activation=NOT_PERFORMED',
    'apply_path=IMPORT_SANITIZE_PROVE',
    'detached_import=FORBIDDEN',
    'staging_db_present=NO',
    'staging_account_present=NO',
    'raw_prod_data_in_github=NONE',
]:
    assert token in PLAN, token

assert 'production-readonly-snapshot/remote-stream.sh' not in PLAN
assert 'sql:dump' not in PLAN
assert ' IMPORT ' not in PLAN
assert 'IMPORT_SANITIZE_PROVE' not in PROD_PLAN
assert 'vendor/bin/drush' not in PROD_PLAN
assert 'mariadb' not in PROD_PLAN.lower()
assert 'prod_release_sha=$actual' in PROD_PLAN

for token in [
    "PROD_REMOTE='scripts/production-readonly-snapshot/remote-stream.sh'",
    "PREPROD_REMOTE='scripts/preproduction-staging-import/remote-preprod-stage.sh'",
    "IMPORT_SANITIZE_PROVE '$REQUEST_ID' '$snapshot_bytes'",
    'one_shot_import_sanitize_prove_cleanup=PASS',
    'sanitization_policy=agency-preprod-refresh-v1',
    'staging_db_present_after_cleanup=NO',
    'staging_account_present_after_cleanup=NO',
    'preprod_runtime_db_touched=NO',
    'raw_snapshot_after=ABSENT',
    'activation=NOT_PERFORMED',
]:
    assert token in APPLY, token

assert " '$HELPER_PATH' IMPORT '" not in APPLY
assert 'sanitize-staging' not in APPLY
assert 'agency-preprod-staging-sanitizer.py' not in APPLY
assert '.apply.runtime_db_switch == "FORBIDDEN"' in APPLY
assert '.apply.activation == "FORBIDDEN"' in APPLY
assert 'public_files=NONE' in APPLY
assert 'private_files=NONE' in APPLY

# Reuse is composition-only: the historical snapshot stream and bounded staging
# wrapper remain the exact implementation surfaces, while the helper owns the
# atomic sanitization lifecycle.
assert 'sql:dump' in PROD_STREAM
assert 'IMPORT_SANITIZE_PROVE' in STAGE_REMOTE
assert 'sudo -n -- "$PRIVILEGED_HELPER"' in STAGE_REMOTE
assert 'action_import_sanitize_prove' in HELPER
assert 'run_import_with_finalizer' in HELPER
assert 'cleanup_scope(scope)' in HELPER

for forbidden in [
    'NOPASSWD: ALL',
    'sudo mariadb',
    'sudo -n mariadb',
    'sudo bash',
    'sudo sh',
    'sudo python',
]:
    assert forbidden not in PLAN
    assert forbidden not in APPLY

assert 'github.event.issue.number == 866' in WORKFLOW
assert '/agency-preprod-real-one-shot' in WORKFLOW
assert "requested_prod == 'AUTO'" in WORKFLOW
assert '^plan-866-' in WORKFLOW
assert '^apply-866-' in WORKFLOW
assert 'ref: ${{ steps.authority.outputs.main_sha }}' in WORKFLOW
assert 'runs-on: [self-hosted, linux, x64, agency]' in WORKFLOW
assert 'Project-Lead-only future real #866 APPLY' in WORKFLOW
assert 'run-apply.sh' in WORKFLOW
assert 'run-plan.sh' in WORKFLOW

print('ISSUE_866_ROUTE_CONTRACT=PASS')
print('REUSED_834_SNAPSHOT_TRANSFER=PASS')
print('REUSED_859_861_ATOMIC_HELPER=PASS')
print('NEW_GENERIC_EXECUTION_MECHANISM=NONE')
print('PLAN_MUTATION_FREE_CONTRACT=PASS')
print('APPLY_PATH=IMPORT_SANITIZE_PROVE')
print('DETACHED_IMPORT=FORBIDDEN')
print('RUNTIME_DB_SWITCH=FORBIDDEN')
print('ACTIVATION=FORBIDDEN')
print('RAW_PROD_DATA_IN_GITHUB=NONE')
