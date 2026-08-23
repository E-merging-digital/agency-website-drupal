# Governed Composer materialization

Status: **TRUSTED EXECUTION CONTRACT**  
Owner issue: #531

## Purpose

Agency can resolve a reviewed Composer dependency change on its managed DDEV
runner without giving that self-hosted runner repository write authority and
without asking a human to copy a generated `composer.lock` artifact back into a
pull request.

This route exists for dependency-only materialization. It is not a generic
remote shell and it is not an API for arbitrary `composer require` commands.

## Security model

```text
authorized control request
-> GitHub-hosted gateway
-> exact same-repository PR / HEAD validation
-> semantic composer.json delta validation
-> trusted workflow from main
-> self-hosted DDEV resolution with contents:read
-> composer.lock + result.json artifact
-> GitHub-hosted artifact revalidation
-> final live-HEAD validation
-> fast-forward composer.lock commit to the PR branch
```

The self-hosted job checks out both trusted workflow code and the exact target
with `persist-credentials: false`. It never receives `contents: write`.

The only write-capable job runs on GitHub-hosted infrastructure. It does not run
scripts from the target branch. It accepts only the generated `composer.lock`,
parses the artifact as JSON, verifies package/version/profile/request/SHA and
hash metadata, checks that the working-tree delta is exactly `composer.lock`,
revalidates the live PR HEAD, and performs a normal fast-forward push.

## Control surface

Control branch:

```text
agency/composer-materialization-dispatch-control
```

Request path:

```text
.agency/composer-materialization-request.json
```

Schema:

```json
{
  "request_id": "issue-530-ai-agents-v1",
  "pr_number": 532,
  "head_sha": "0123456789abcdef0123456789abcdef01234567",
  "profile": "canvas-ai-agents-530"
}
```

The gateway accepts exactly these four keys. `request_id` and `head_sha` have
strict formats, `pr_number` must identify an open same-repository PR targeting
`main`, and `profile` must be present in the trusted repository-owned profile
registry **and** in the explicit gateway allowlist.

Before dispatch, the PR must differ from its base by exactly `composer.json`.
The gateway then compares parsed JSON and proves that the target differs from
base only by the change declared by the selected profile. This prevents a
request from smuggling Composer scripts, plugin configuration or unrelated
dependency changes into the self-hosted execution.

## Trusted profiles

Profiles live in:

```text
scripts/runner/composer-materialization-profiles.sh
```

A profile owns all executable inputs: package, expected constraint, accepted
resolved-version pattern and product issue. Callers provide only the profile
identifier.

Reviewed profiles:

```text
canvas-ai-agents-530
package            = drupal/ai_agents
constraint         = ^1.3
resolved version   = stable 1.3.x
product issue      = #530

config-language-lock-628
package            = drupal/config_language_lock
constraint         = ^1.0
resolved version   = stable 1.0.x
product issue      = #628
```

The second profile exists only to materialize the dependency candidate evaluated
by #609/#628. It does not configure, enable or enforce Configuration Language
Lock. Runtime evaluation remains a separate trusted route documented in
`docs/operations/config-language-lock-evaluation.md`.

Adding another dependency requires a reviewed repository change adding another
explicit profile **and** gateway semantic validation for that profile. Never
extend the request schema with package names, Composer arguments or shell
commands.

## Resolver behavior

The trusted self-hosted job starts an isolated DDEV project and runs the fixed
profile as a targeted Composer update with dependency resolution and
`--no-scripts`.

It fails closed unless:

- no untracked file remains after removing the temporary DDEV override;
- no staged file exists;
- `composer.lock` is the only modified tracked file;
- the expected package appears exactly once in the lockfile;
- the resolved version matches the profile's stable-version pattern.

The artifact contains only:

```text
composer.lock
result.json
```

`result.json` records the request ID, profile, exact input HEAD, package,
resolved version and SHA-256 of the lockfile. It contains no credential.

## Publishing behavior

The GitHub-hosted publishing job downloads the artifact and independently
revalidates it before copying the lockfile into an exact detached checkout of
the original PR HEAD.

Immediately before push it queries the live PR again. If the PR advanced since
the request, publication fails rather than rebasing, force-pushing or applying
the lockfile to a different target.

A successful run comments on the target PR with the input SHA, new SHA,
resolved package version, lockfile SHA-256 and workflow run ID.

## Observability contract

Issue #534 adds fail-closed observability without changing the self-hosted
permission model.

After a request has passed schema, PR, exact-HEAD and semantic Composer checks,
the GitHub-hosted gateway snapshots the existing trusted workflow run IDs,
dispatches the trusted workflow and discovers the newly created run by set
difference. The target commit then receives:

```text
context    = agency/composer-materialization
state      = pending
target_url = exact trusted workflow run
```

The gateway remains on GitHub-hosted infrastructure while it observes that exact
run. When the trusted run completes it publishes `success` or `failure` on the
input HEAD. On success, if the publisher created the expected direct-child
lockfile commit, the same `success` status is also published on that new HEAD.

The target PR receives a final diagnostic comment containing the request ID,
trusted run ID, input HEAD and observed output HEAD. This makes failures before
`publish-lock` diagnosable from the cockpit instead of leaving a request with no
observable result.

The observable gateway does not receive product secrets and does not make the
self-hosted job write-capable. A failure remains a failure; the gateway exits
non-zero after publishing the diagnostic state.

## Explicit non-capabilities

This route does **not** provide:

- arbitrary Composer commands;
- arbitrary package names or constraints;
- arbitrary shell execution;
- generic branch mutation;
- self-hosted GitHub write credentials;
- provider/API secrets;
- product configuration generation;
- automatic module enablement;
- automatic Configuration Language Lock enforcement;
- automatic merge authority.

Those remain separate capabilities and require their own governed owner when
needed.
