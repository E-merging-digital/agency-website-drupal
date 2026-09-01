#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOC = ROOT / 'docs/operations/agency-environment-data-lifecycle.md'
REGISTRY = ROOT / 'docs/operations/execution-capabilities.md'
REFRESH = ROOT / 'docs/operations/preproduction-data-refresh.md'
SUCCESSOR = ROOT / 'docs/operations/preproduction-refresh-governed-successor.md'
DISPATCHER = ROOT / '.github/workflows/agency-command-dispatch.yml'
WORKFLOW = ROOT / '.github/workflows/preprod-914-governed-successor.yml'
POLICY = ROOT / 'scripts/preproduction-refresh/sanitization-policy.json'
PROFILE = ROOT / 'scripts/preproduction-refresh/governed-successor/profile.json'
CONTROL = ROOT / 'scripts/preproduction-refresh/governed-successor/run-server-to-server-apply.sh'
PREP = ROOT / 'scripts/preproduction-refresh/governed-successor/remote-server-to-server-worker.py'
ACTIVATION = ROOT / 'scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh'
PROVIDER = ROOT / '.ddev/providers/agency.yaml'

ACTIVE_REUSABLE_WORKFLOWS = (
    '.github/workflows/promote-production.yml',
    '.github/workflows/production-scheduler-change.yml',
    '.github/workflows/trusted-editorial-publication.yml',
    '.github/workflows/trusted-editorial-feature-image.yml',
    '.github/workflows/preprod-914-governed-successor.yml',
)

REQUIRED_HEADINGS = tuple(f'## {i}. ' for i in range(1, 17))


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def has_top_level_issue_comment_trigger(text: str) -> bool:
    lines = text.splitlines()
    in_on = False
    for line in lines:
        if not in_on:
            if line == 'on:':
                in_on = True
                continue
            if re.fullmatch(r'on:\s*issue_comment\s*', line):
                return True
            continue
        if line and not line.startswith((' ', '\t')):
            return False
        if re.fullmatch(r'  issue_comment:\s*', line):
            return True
    return False


def job_runs_on(workflow: str, job: str, expected: str) -> bool:
    pattern = rf'(?ms)^  {re.escape(job)}:\n.*?^    runs-on: {re.escape(expected)}\s*$'
    return re.search(pattern, workflow) is not None


def main() -> int:
    required_paths = (
        DOC, REGISTRY, REFRESH, SUCCESSOR, DISPATCHER, WORKFLOW, POLICY, PROFILE,
        CONTROL, PREP, ACTIVATION, PROVIDER,
    )
    for path in required_paths:
        require(path.is_file(), f'required current path missing: {path.relative_to(ROOT)}')

    doc = DOC.read_text(encoding='utf-8')
    registry = REGISTRY.read_text(encoding='utf-8')
    refresh = REFRESH.read_text(encoding='utf-8')
    successor = SUCCESSOR.read_text(encoding='utf-8')
    dispatcher = DISPATCHER.read_text(encoding='utf-8')
    workflow = WORKFLOW.read_text(encoding='utf-8')
    control = CONTROL.read_text(encoding='utf-8')
    prep = PREP.read_text(encoding='utf-8')
    activation = ACTIVATION.read_text(encoding='utf-8')
    provider = PROVIDER.read_text(encoding='utf-8')
    policy = json.loads(POLICY.read_text(encoding='utf-8'))
    profile = json.loads(PROFILE.read_text(encoding='utf-8'))
    current_docs = '\n'.join((doc, registry, refresh, successor))

    for prefix in REQUIRED_HEADINGS:
        require(prefix in doc, f'missing canonical heading prefix: {prefix}')

    for invariant in (
        'GitHub + repository + execution evidence = source of truth',
        'handoff != authority',
        'implementation authority != execution authority',
        'PLAN != APPLY',
        'CONSUMED / NEVER REUSE',
        'recoverable technical failure != HUMAN_REQUIRED',
        'operator-surface capability != project-executor capability',
        'CAPABILITY EXISTS != EXECUTOR CURRENTLY ONLINE',
        'DATA_ACTIVATION_AUTHORITY = DISABLED',
    ):
        require(invariant in doc, f'missing authority invariant: {invariant}')

    listeners = []
    for path in sorted((ROOT / '.github/workflows').glob('*.yml')):
        if has_top_level_issue_comment_trigger(path.read_text(encoding='utf-8')):
            listeners.append(path.relative_to(ROOT).as_posix())
    require(listeners == ['.github/workflows/agency-command-dispatch.yml'],
            f'unexpected issue_comment listeners: {listeners}')
    require('Route syntax only; authorization remains downstream' in dispatcher,
            'dispatcher incorrectly owns authorization')
    for path in ACTIVE_REUSABLE_WORKFLOWS:
        text = (ROOT / path).read_text(encoding='utf-8')
        require('workflow_call:' in text, f'active route not reusable: {path}')
        require(not has_top_level_issue_comment_trigger(text), f'active route owns listener: {path}')
        require(path in dispatcher, f'dispatcher missing active route: {path}')
    require('#922 is **COMPLETED**' in doc, '#922 current dispatcher state missing')

    require(job_runs_on(workflow, 'validate-authority', 'ubuntu-24.04'), 'authority runner changed')
    require(job_runs_on(workflow, 'plan', 'ubuntu-24.04'), 'PLAN is not hosted ubuntu-24.04')
    require(job_runs_on(workflow, 'apply', 'ubuntu-24.04'), 'temporary APPLY control plane is not hosted ubuntu-24.04')
    require('${{ runner.environment }}' in workflow and 'github-hosted' in workflow,
            'hosted JIT assertion missing')
    require('PREPROD_PROVISIONING_SSH_PRIVATE_KEY' in workflow, 'temporary PREPROD root identity mapping missing')
    require('run-server-to-server-apply.sh' in workflow, 'temporary server-to-server control script not selected')

    for text, label in ((doc, 'canonical'), (registry, 'registry'), (refresh, 'refresh'), (successor, 'successor')):
        require('PLAN_RESULT = PASS' in text or 'PLAN_RESULT=PASS' in text,
                f'#937 PLAN PASS missing from {label}')
        require('CONTROLLED_SERVER_TO_SERVER' in text,
                f'temporary server-to-server path missing from {label}')
        require('CURRENTLY UNAVAILABLE' in text or 'currently unavailable' in text,
                f'self-hosted alternative availability missing from {label}')
        require('RAW PROD' in text and 'GITHUB-HOSTED' in text,
                f'raw GitHub-hosted prohibition missing from {label}')

    require('APPLY_EXECUTION_PATH = CONTROLLED_SERVER_TO_SERVER / TEMPORARY CURRENT' in refresh,
            'refresh contract missing temporary current APPLY path')
    require('TRUSTED_SELF_HOSTED_RUNNER = AUTHORIZED ALTERNATIVE / CURRENTLY UNAVAILABLE' in refresh,
            'refresh contract missing authorized self-hosted alternative')
    require('FIRST_REAL_APPLY=NOT_AUTHORIZED' in refresh, 'real APPLY boundary missing')
    require('REAL_END_TO_END_REFRESH = NOT_YET_PROVEN' in refresh, 'real end-to-end pending state missing')

    require(policy['policy_version'] == 'agency-preprod-refresh-v1', 'sanitization policy identity changed')
    require(policy['execution_boundary']['github_hosted']['raw_prod_data_allowed'] is False,
            'GitHub-hosted raw data policy weakened')
    paths = {entry['type']: entry for entry in policy['execution_boundary']['raw_prod_data']['allowed_paths']}
    require('TRUSTED_AGENCY_RUNNER' in paths, 'trusted runner alternative removed from policy')
    require(paths['CONTROLLED_SERVER_TO_SERVER']['requirement'] ==
            'RAW_DATA_NEVER_TRANSITS_OR_MATERIALIZES_ON_GITHUB_HOSTED_INFRASTRUCTURE',
            'controlled server-to-server boundary weakened')
    require(profile['decision'] == 'EXTEND_EXISTING' and profile['temporary_execution_issue'] == 938,
            '#914 profile not aligned to temporary #938 extension')

    for required in (
        'RAW_PROD_ON_GITHUB_HOSTED=NONE',
        'RAW_PROD_ROUTE=PROD_TO_PREPROD_DIRECT',
        'root@$PREPROD_SSH_HOST',
        'StrictHostKeyChecking=yes',
        'worker_started=1',
    ):
        require(required in control, f'control-plane invariant missing: {required}')
    require('prod_ssh=(' not in control, 'control plane gained direct PROD SSH path')
    require('"$PROD_SSH_USER@$PROD_SSH_HOST"' not in control, 'control plane connects to PROD')
    require('.sql' not in control, 'control plane contains SQL materialization path')
    require('actions/upload-artifact' not in control, 'control plane exposes artifact path')

    for required in (
        'helper.cleanup_scope(scope)',
        'helper.require_absent(scope)',
        'RAW_STAGING_CLEANUP_UNPROVEN',
        'cleanup_root_stage',
        'shutil.rmtree(stage)',
        'not os.path.lexists(stage)',
        'PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN',
        'HUMAN_RECOVERY_REQUIRED',
        'emergency_result(sys.argv)',
    ):
        require(required in prep, f'pre-activation cleanup invariant missing: {required}')
    raw_cleanup = prep.index('raw_cleanup_proven = cleanup_raw_scope(helper, scope)')
    raw_gate = prep.index('if preparation_failed or not raw_cleanup_proven:')
    root_gate = prep.index('if not cleanup_root_stage(stage) or os.path.lexists(stage):')
    handoff = prep.index('os.execv(')
    require(raw_cleanup < raw_gate < root_gate < handoff,
            'activation can precede raw/root-stage cleanup proof')

    backup = activation.index('sql:dump --no-interaction --result-file="$BACKUP"')
    maint = activation.index('maint:set 1')
    drop = activation.index('sql:drop -y', maint)
    require(backup < maint < drop, 'PREPROD backup no longer precedes destructive activation')
    require('sql:cli < "$BACKUP"' in activation, 'exact backup rollback removed')
    require('HUMAN_RECOVERY_REQUIRED' in activation and 'maintenance' in activation.lower(),
            'activation fail-closed rollback boundary missing')

    for privacy in (
        'RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN',
        'RAW SQL AS GITHUB ARTIFACT = FORBIDDEN',
        'PII IN EVIDENCE/LOGS = FORBIDDEN',
        'PRIVATE FILES = EXCLUDED',
    ):
        require(privacy in doc, f'privacy invariant missing: {privacy}')

    require('DDEV_PUSH = NONE' in doc and 'DDEV_PUSH = NONE' in registry,
            'DDEV push prohibition missing')
    require('db_push_command' not in provider and 'files_push_command' not in provider,
            'DDEV provider exposes upstream push')
    require('#872' in doc and '`DESIGN_ONLY`' in doc, 'editorial future/current boundary missing')
    require('REAL_PREPROD_SEED_GENERATION = PENDING #816' in doc,
            'development seed real-generation pending state missing')

    for stale in (
        'APPLY remains `[self-hosted, linux, x64, agency]`',
        'APPLY remains strictly assigned to `[self-hosted, linux, x64, agency]`',
        '#930 is the **CURRENT RECOVERY / IN PROGRESS**',
        '#930 is **CURRENT RECOVERY / IN PROGRESS**',
        'PLAN_RESULT = FAIL_CLOSED',
        'FAILED_READINESS_PREDICATE = NOT_YET_PROVEN',
    ):
        require(stale not in current_docs, f'stale current-state wording remains: {stale}')

    for forbidden in ('RECOVER_ABORT', 'RECOVER_CURRENT'):
        require(forbidden in refresh or forbidden in successor,
                f'obsolete recovery model is not explicitly rejected: {forbidden}')
    require('transaction registry' not in control.lower(), 'new transaction registry introduced')
    require('#915' not in control and '#915' not in prep, '#915 operational dependency revived')

    for name, text in (('canonical', doc), ('registry', registry), ('refresh', refresh), ('successor', successor)):
        require(not re.search(r'\b3\d{10}\b', text), f'ephemeral workflow run ID embedded in {name}')
        for secret in ('PREPROD_PROVISIONING_SSH_PRIVATE_KEY=', 'DB_PASSWORD=', 'SSH_PRIVATE_KEY='):
            require(secret not in text, f'secret-shaped assignment in {name}: {secret}')

    print('CANONICAL_LIFECYCLE_DOC=PRESENT')
    print('SINGLE_COMMAND_DISPATCHER=DOCUMENTED_CURRENT')
    print('CURRENT_914_PLAN_RUNNER=GITHUB_HOSTED_UBUNTU_24_04')
    print('CURRENT_914_PLAN_RESULT=PASS')
    print('CURRENT_914_APPLY_PATH=CONTROLLED_SERVER_TO_SERVER_TEMPORARY')
    print('TRUSTED_SELF_HOSTED_RUNNER=AUTHORIZED_ALTERNATIVE_CURRENTLY_UNAVAILABLE')
    print('RAW_PROD_ON_GITHUB_HOSTED=NONE')
    print('RAW_STAGING_CLEANUP=PROVEN_BEFORE_ACTIVATION')
    print('PROD_IDENTITY_STAGE_CLEANUP=PROVEN_BEFORE_ACTIVATION')
    print('CLEANUP_UNPROVEN_OUTCOME=HUMAN_RECOVERY_REQUIRED')
    print('EXISTING_REMOTE_APPLY_WORKER=REUSED')
    print('DDEV_PUSH=NONE')
    print('DOC_CONTRACT=SUCCESS')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except AssertionError as exc:
        print(f'DOC_CONTRACT=FAIL: {exc}', file=sys.stderr)
        raise SystemExit(1)
