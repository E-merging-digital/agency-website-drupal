#!/usr/bin/env bash
# Publish one exact, CI-validated code candidate through the existing release/* CD.
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo 'Usage: publish-selected-candidate.sh <main|feature/name> <exact-40-char-sha> <release/name>' >&2
  exit 2
fi

source_branch="$1"
expected_sha="$2"
release_branch="$3"
repo='E-merging-digital/agency-website-drupal'

[[ "$source_branch" == main || "$source_branch" =~ ^feature/[A-Za-z0-9._/-]+$ ]] || {
  echo 'Only main or an independent feature/* source is accepted.' >&2; exit 1;
}
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'Exact SHA is required.' >&2; exit 1; }
[[ "$release_branch" =~ ^release/[a-z0-9][a-z0-9._-]{2,60}$ ]] || {
  echo 'A new, bounded release/name is required.' >&2; exit 1;
}

origin_url="$(git remote get-url origin)"
case "$origin_url" in
  "https://github.com/$repo"|"https://github.com/$repo.git"|"git@github.com:$repo.git") ;;
  *) echo 'Unexpected origin repository; refusing release publication.' >&2; exit 1 ;;
esac
command -v gh >/dev/null && command -v jq >/dev/null || {
  echo 'GitHub CLI (authenticated) and jq are required.' >&2; exit 1;
}

# Read exact remote identities; no local checkout, merge, cherry-pick or rewrite.
remote_main="$(git ls-remote --heads origin refs/heads/main | awk '{print $1}')"
remote_source="$(git ls-remote --heads origin "refs/heads/$source_branch" | awk '{print $1}')"
[[ "$remote_main" =~ ^[0-9a-f]{40}$ && "$remote_source" == "$expected_sha" ]] || {
  echo 'Remote main/source identity is unavailable or has changed.' >&2; exit 1;
}
git fetch --no-tags --quiet origin "$remote_main" "$expected_sha"
git merge-base --is-ancestor "$remote_main" "$expected_sha" || {
  echo 'Candidate omits current main; selective history needs separate review.' >&2; exit 1;
}

# Both gates must have succeeded on this exact source commit (PR for feature/*).
event='pull_request'
[[ "$source_branch" == main ]] && event='push'
runs="$(gh api "repos/$repo/actions/runs?head_sha=$expected_sha&per_page=100")"
for required in 'CI' 'Committed secret scan'; do
  jq -e --arg branch "$source_branch" --arg sha "$expected_sha" \
    --arg event "$event" --arg name "$required" '
      [.workflow_runs[] | select(
        .name == $name and .head_sha == $sha and .head_branch == $branch
        and .event == $event
      )] | if length == 0 then false
           else (max_by(.run_number).status == "completed"
                 and max_by(.run_number).conclusion == "success") end
    ' <<<"$runs" >/dev/null || {
      echo "Missing successful $required on exact candidate SHA." >&2; exit 1;
    }
done

# Recheck the source and main immediately before the single remote mutation.
[[ "$(git ls-remote --heads origin refs/heads/main | awk '{print $1}')" == "$remote_main" \
  && "$(git ls-remote --heads origin "refs/heads/$source_branch" | awk '{print $1}')" == "$expected_sha" ]] || {
  echo 'Remote identity changed during validation.' >&2; exit 1;
}

# GitHub POST is create-only: an existing release ref fails instead of moving it.
gh api -X POST "repos/$repo/git/refs" \
  -f "ref=refs/heads/$release_branch" -f "sha=$expected_sha" >/dev/null
printf 'RELEASE_BRANCH=%s\nCANDIDATE_SHA=%s\nCD=EXISTING_BUILD_THEN_PREPROD\n' \
  "$release_branch" "$expected_sha"
