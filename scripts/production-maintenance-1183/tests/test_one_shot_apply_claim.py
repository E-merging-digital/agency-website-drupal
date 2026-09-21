#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import sys
import tempfile

HELPER = pathlib.Path(sys.argv[1]).resolve()
MAIN = "a" * 40
COMMAND = (
    "/agency-prod-os-maintenance-1183 apply "
    "plan_run=123456 "
    + "plan_digest=" + ("b" * 64) + " "
    "snapshot_ref=agency-prod-1183-test-snapshot "
    "window_ref=2026-09-21T12:10+02:00/2026-09-21T13:10+02:00"
)


def authority(comment_id: int, revision: int) -> dict:
    body = (
        f"PROJECT_LEAD_APPLY_AUTHORITY_1183_R{revision} — test\n\n"
        f"LIVE_MAIN =\n{MAIN}\n\n"
        "This authority permits exactly ONE direct OWNER APPLY comment.\n\n"
        f"AUTHORIZED HUMAN COMMAND =\n{COMMAND}\n"
    )
    return {
        "id": comment_id,
        "user": {"login": "E-merging-digital"},
        "author_association": "OWNER",
        "body": body,
    }
def write_comments(path: pathlib.Path, values: list[dict]) -> None:
    path.write_text(json.dumps([values]), encoding="utf-8")


def run_claim(env: dict[str, str], comment_id: int, body: str = COMMAND) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(HELPER), str(comment_id), body, MAIN],
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )


with tempfile.TemporaryDirectory(prefix="agency-1296-claim-") as tmp:
    root = pathlib.Path(tmp)
    fake_bin = root / "bin"
    claims = root / "claims"
    fake_bin.mkdir()
    claims.mkdir()
    issue = root / "issue.json"
    comments = root / "comments.json"
    issue.write_text(
        json.dumps({"state": "open", "labels": [{"name": "P1"}, {"name": "status:in-progress"}]}),
        encoding="utf-8",
    )
    gh = fake_bin / "gh"
    gh.write_text(
        """#!/usr/bin/env bash
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
    --method)
      method="$2"; shift 2 ;;
    --paginate|--slurp)
      shift ;;
    --jq)
      want_jq=1; shift 2 ;;
    -f)
      case "$2" in
        ref=*) ref="${2#ref=}" ;;
        sha=*) sha="${2#sha=}" ;;
      esac
      shift 2 ;;
    *)
      endpoint="$1"; shift ;;
  esac
done
case "$endpoint" in
  repos/*/issues/1183)
    cat "$FAKE_ISSUE_JSON" ;;
  repos/*/git/ref/heads/main)
    if [[ "$want_jq" -eq 1 ]]; then
      printf '%s\\n' "$FAKE_MAIN_SHA"
    else
      printf '{\"object\":{\"sha\":\"%s\"}}\\n' "$FAKE_MAIN_SHA"
    fi ;;
  repos/*/issues/1183/comments?per_page=100)
    cat "$FAKE_COMMENTS_JSON" ;;
  repos/*/git/refs)
    test "$method" = POST
    key="$(printf '%s' "$ref" | sha256sum | cut -d' ' -f1)"
    if ! mkdir "$FAKE_CLAIMS_DIR/$key" 2>/dev/null; then
      exit 1
    fi
    printf '{\"ref\":\"%s\",\"object\":{\"sha\":\"%s\"}}\\n' "$ref" "$sha" ;;
  *)
    printf 'unexpected endpoint: %s\\n' "$endpoint" >&2
    exit 99 ;;
esac
""",
        encoding="utf-8",
    )
    gh.chmod(0o700)
    env = os.environ.copy()
    env.update(
        {
            "PATH": f"{fake_bin}:/usr/bin:/bin",
            "GITHUB_REPOSITORY": "E-merging-digital/agency-website-drupal",
            "FAKE_ISSUE_JSON": str(issue),
            "FAKE_COMMENTS_JSON": str(comments),
            "FAKE_CLAIMS_DIR": str(claims),
            "FAKE_MAIN_SHA": MAIN,
        }
    )

    write_comments(comments, [authority(100, 17)])
    first = run_claim(env, 101)
    assert first.returncode == 0, first.stderr
    assert "authority_comment_id=100" in first.stdout
    print("FIRST_ELIGIBLE_APPLY=PASS")

    duplicate = run_claim(env, 102)
    assert duplicate.returncode == 75, duplicate.stderr
    assert "APPLY_ONE_SHOT_CLAIM=REJECTED" in duplicate.stderr
    print("SEQUENTIAL_DUPLICATE=REJECTED")

    write_comments(comments, [authority(100, 17), authority(200, 18)])
    future = run_claim(env, 201)
    assert future.returncode == 0, future.stderr
    assert "authority_comment_id=200" in future.stdout
    print("NEW_AUTHORITY_IDENTITY=PASS")
    write_comments(
        comments,
        [authority(100, 17), authority(200, 18), authority(300, 19)],
    )
    processes = [
        subprocess.Popen(
            ["bash", str(HELPER), str(comment_id), COMMAND, MAIN],
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        for comment_id in (301, 302)
    ]
    concurrent = []
    for process in processes:
        stdout, stderr = process.communicate(timeout=10)
        concurrent.append((process.returncode, stdout, stderr))
    assert sorted(item[0] for item in concurrent) == [0, 75], concurrent
    print("CONCURRENT_DUPLICATE=ONE_WINNER_ONE_REJECTED")

    plan = run_claim(env, 400, "/agency-prod-os-maintenance-1183 plan")
    assert plan.returncode == 64, plan
    print("PLAN_MODE_NOT_CLAIMABLE=PASS")

print("ONE_SHOT_APPLY_CLAIM_HARNESS=PASS")
