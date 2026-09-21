#!/usr/bin/env bash
set -euo pipefail

comment_id="${1:-}"
comment_body="${2:-}"
workflow_sha="${3:-}"

[[ "$comment_id" =~ ^[1-9][0-9]*$ ]]
[[ "$workflow_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "${GITHUB_REPOSITORY:-}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]

semantic_command="$comment_body"
if [[ "$semantic_command" == *$'\r\n' ]]; then
  semantic_command="${semantic_command%$'\r\n'}"
elif [[ "$semantic_command" == *$'\n' ]]; then
  semantic_command="${semantic_command%$'\n'}"
fi
if [[ "$semantic_command" == *$'\n'* || "$semantic_command" == *$'\r'* ]]; then
  printf '%s\n' 'Unsupported #1183 APPLY command syntax.' >&2
  exit 64
fi

if [[ ! "$semantic_command" =~ ^/agency-prod-os-maintenance-1183\ apply\ plan_run=([1-9][0-9]*)\ plan_digest=([0-9a-f]{64})\ snapshot_ref=([A-Za-z0-9._:@/-]{8,160})\ window_ref=([A-Za-z0-9._:@/+:-]{8,160})$ ]]; then
  printf '%s\n' 'Unsupported #1183 APPLY command syntax.' >&2
  exit 64
fi

plan_run="${BASH_REMATCH[1]}"
plan_digest="${BASH_REMATCH[2]}"
snapshot_ref="${BASH_REMATCH[3]}"
window_ref="${BASH_REMATCH[4]}"

issue_json="$(gh api "repos/$GITHUB_REPOSITORY/issues/1183")"
test "$(jq -r '.state' <<<"$issue_json")" = 'open'
jq -e '[.labels[].name] | index("P1") != null' <<<"$issue_json" >/dev/null
jq -e '[.labels[].name] | index("status:in-progress") != null' <<<"$issue_json" >/dev/null

main_sha="$(gh api "repos/$GITHUB_REPOSITORY/git/ref/heads/main" --jq '.object.sha')"
[[ "$main_sha" =~ ^[0-9a-f]{40}$ ]]
test "$workflow_sha" = "$main_sha"

comments_file="$(mktemp)"
claim_response="$(mktemp)"
trap 'rm -f "$comments_file" "$claim_response"' EXIT

gh api --paginate --slurp \
  "repos/$GITHUB_REPOSITORY/issues/1183/comments?per_page=100" \
  > "$comments_file"

authority_json="$(
  jq -c \
    --arg command "$semantic_command" \
    --arg main "$main_sha" \
    --argjson command_id "$comment_id" '
      [
        .[][]
        | select((.id | type) == "number" and .id < $command_id)
        | select(.user.login == "E-merging-digital")
        | select(.author_association == "OWNER")
        | select((.body // "") | startswith("PROJECT_LEAD_APPLY_AUTHORITY_1183_R"))
        | select((.body // "") | contains("LIVE_MAIN =\n" + $main))
        | select((.body // "") | contains("AUTHORIZED HUMAN COMMAND =\n" + $command))
      ]
      | sort_by(.id)
      | last // empty
    ' "$comments_file"
)"
test -n "$authority_json"
authority_comment_id="$(jq -er '.id' <<<"$authority_json")"
[[ "$authority_comment_id" =~ ^[1-9][0-9]*$ ]]

claim_identity="$(
  printf '%s\0%s\0%s\0%s\0%s\0%s' \
    "$authority_comment_id" "$main_sha" "$plan_run" "$plan_digest" \
    "$snapshot_ref" "$window_ref" \
    | sha256sum | cut -d' ' -f1
)"
[[ "$claim_identity" =~ ^[0-9a-f]{64}$ ]]
claim_ref="refs/tags/agency-1183-apply-claim-$claim_identity"

set +e
gh api --method POST "repos/$GITHUB_REPOSITORY/git/refs" \
  -f "ref=$claim_ref" \
  -f "sha=$main_sha" \
  > "$claim_response" 2>/dev/null
claim_rc=$?
set -e

if [[ "$claim_rc" -ne 0 ]]; then
  printf '%s\n' 'APPLY_ONE_SHOT_CLAIM=REJECTED' >&2
  exit 75
fi

jq -e \
  --arg ref "$claim_ref" \
  --arg sha "$main_sha" '
    .ref == $ref
    and .object.sha == $sha
  ' "$claim_response" >/dev/null

printf 'authority_comment_id=%s\n' "$authority_comment_id"
printf 'claim_ref=%s\n' "$claim_ref"
printf 'claim_identity=%s\n' "$claim_identity"
