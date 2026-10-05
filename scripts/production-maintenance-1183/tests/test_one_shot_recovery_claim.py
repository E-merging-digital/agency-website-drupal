#!/usr/bin/env python3
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

HELPER = Path(sys.argv[1]).resolve()
MAIN = 'a' * 40
DIGEST = '07a672088469959e01863450acd8b5078cd5efc2041dda5545e4bb986635744b'
COMMAND = (
    '/agency-prod-os-maintenance-1183 recover-post-reboot '
    'incident_run=35602886717 '
    'plan_run=35600328878 '
    f'plan_digest={DIGEST}'
)


def authority(comment_id: int, revision: int) -> dict:
    body = (
        f'PROJECT_LEAD_RECOVERY_AUTHORITY_1300_R{revision} — test\n\n'
        f'LIVE_MAIN =\n{MAIN}\n\n'
        f'AUTHORIZED HUMAN COMMAND =\n{COMMAND}\n'
    )
    return {
        'id': comment_id,
        'user': {'login': 'E-merging-digital'},
        'author_association': 'OWNER',
        'body': body,
    }


def write_comments(path: Path, values: list[dict]) -> None:
    path.write_text(json.dumps([values]), encoding='utf-8')


def run_claim(
    env: dict[str, str],
    comment_id: int,
    body: str = COMMAND,
    workflow_sha: str = MAIN,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ['bash', str(HELPER), str(comment_id), body, workflow_sha],
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )


with tempfile.TemporaryDirectory(prefix='agency-1300-claim-') as tmp:
    root = Path(tmp)
    fake_bin = root / 'bin'
    claims = root / 'claims'
    fake_bin.mkdir()
    claims.mkdir()
    recovery_issue = root / 'recovery-issue.json'
    parent_issue = root / 'parent-issue.json'
    comments = root / 'comments.json'
    recovery_issue.write_text(
        json.dumps({
            'state': 'open',
            'labels': [{'name': 'P0'}, {'name': 'status:in-progress'}],
        }),
        encoding='utf-8',
    )
    parent_issue.write_text(
        json.dumps({
            'state': 'open',
            'labels': [{'name': 'P1'}, {'name': 'status:blocked'}],
        }),
        encoding='utf-8',
    )

    gh = fake_bin / 'gh'
    gh.write_text(
        '''#!/usr/bin/env bash
set -euo pipefail
test "$1" = api
shift
method=GET
endpoint=''
ref=''
sha=''
want_jq=0
while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --method) method="$2"; shift 2 ;;
    --paginate|--slurp) shift ;;
    --jq) want_jq=1; shift 2 ;;
    -f)
      case "$2" in
        ref=*) ref="${2#ref=}" ;;
        sha=*) sha="${2#sha=}" ;;
      esac
      shift 2 ;;
    *) endpoint="$1"; shift ;;
  esac
done
case "$endpoint" in
  repos/*/issues/1300) cat "$FAKE_RECOVERY_ISSUE" ;;
  repos/*/issues/1183) cat "$FAKE_PARENT_ISSUE" ;;
  repos/*/git/ref/heads/main)
    if [[ "$want_jq" -eq 1 ]]; then
      printf '%s\n' "$FAKE_MAIN_SHA"
    else
      printf '{"object":{"sha":"%s"}}\n' "$FAKE_MAIN_SHA"
    fi ;;
  repos/*/issues/1300/comments?per_page=100) cat "$FAKE_COMMENTS_JSON" ;;
  repos/*/git/refs)
    test "$method" = POST
    key="$(printf '%s' "$ref" | sha256sum | cut -d' ' -f1)"
    if ! mkdir "$FAKE_CLAIMS_DIR/$key" 2>/dev/null; then
      exit 1
    fi
    printf '{"ref":"%s","object":{"sha":"%s"}}\n' "$ref" "$sha" ;;
  *) printf 'unexpected endpoint: %s\n' "$endpoint" >&2; exit 99 ;;
esac
''',
        encoding='utf-8',
    )
    gh.chmod(0o700)
    env = os.environ.copy()
    env.update({
        'PATH': f'{fake_bin}:/usr/bin:/bin',
        'GITHUB_REPOSITORY': 'E-merging-digital/agency-website-drupal',
        'FAKE_RECOVERY_ISSUE': str(recovery_issue),
        'FAKE_PARENT_ISSUE': str(parent_issue),
        'FAKE_COMMENTS_JSON': str(comments),
        'FAKE_CLAIMS_DIR': str(claims),
        'FAKE_MAIN_SHA': MAIN,
    })

    write_comments(comments, [authority(100, 1)])
    first = run_claim(env, 101)
    assert first.returncode == 0, first
    assert 'claim_ref=refs/tags/agency-1300-recovery-claim-' in first.stdout
    assert 'incident_run=35602886717' in first.stdout
    print('FIRST_RECOVERY_CLAIM=PASS')

    duplicate = run_claim(env, 102)
    assert duplicate.returncode == 75, duplicate
    assert 'RECOVERY_ONE_SHOT_CLAIM=REJECTED' in duplicate.stderr
    print('DUPLICATE_RECOVERY_COMMAND=REJECTED')

    wrong_incident = COMMAND.replace('incident_run=35602886717', 'incident_run=1')
    invalid = run_claim(env, 103, wrong_incident)
    assert invalid.returncode == 64, invalid
    print('INCIDENT_IDENTITY_EXACT=PASS')

    wrong_main = run_claim(env, 104, workflow_sha='b' * 40)
    assert wrong_main.returncode != 0, wrong_main
    print('EXACT_CURRENT_MAIN_REQUIRED=PASS')

    write_comments(comments, [authority(100, 1), authority(200, 2)])
    future = run_claim(env, 201)
    assert future.returncode == 0, future
    assert 'authority_comment_id=200' in future.stdout
    print('NEW_RECOVERY_AUTHORITY_NEW_IDENTITY=PASS')

print('ONE_SHOT_RECOVERY_CLAIM_HARNESS=PASS')
