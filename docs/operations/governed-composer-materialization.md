# Governed Composer materialization

Status: **TRUSTED EXECUTION CONTRACT**  
Owner issue: #531  
Bounded extension: #998

## Purpose

Agency can resolve a reviewed Composer dependency change on its managed DDEV
runner without giving that self-hosted runner repository write authority and
without asking a human to copy a generated `composer.lock` artifact back into a
pull request.

This route exists for dependency-only materialization. It is not a generic
remote shell and it is not an API for arbitrary Composer commands, packages,
constraints or selectors.

## Security model

```text
authorized control request
-> GitHub-hosted gateway
-> exact same-repository PR / HEAD validation
-> repository-owned profile validation
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
parses the artifact as JSON, verifies profile/request/SHA, reviewed selectors or
package metadata, resolved versions and hash metadata, checks that the
working-tree delta is exactly `composer.lock`, revalidates the live PR HEAD, and
performs a normal fast-forward push.

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
`main`, and `profile` must be present in the explicit gateway allowlist.

No package name, Composer command, constraint, selector or shell fragment can be
supplied by the request.

## Trusted profiles

Profiles live in:

```text
scripts/runner/composer-materialization-profiles.sh
```

The historical `package` mode remains supported. A package profile owns the
package, expected constraint, accepted resolved-version pattern and owner issue.

Reviewed package profiles include:

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

drupAL-maintenance-ai-1.5-rc1
package            = drupal/ai
constraint         = 1.5.0-rc1
product issue      = #562
```

The configuration-language profile exists only to materialize the dependency
candidate evaluated by #609/#628. It does not configure, enable or enforce
Configuration Language Lock. Runtime evaluation remains a separate trusted route
documented in `docs/operations/config-language-lock-evaluation.md`.

## Bounded lock-refresh mode

Issue #998 extends the same primitive with one additional mode for maintenance
work where `composer.json` already admits the reviewed stable versions.

The first and only lock-refresh profile introduced by #998 is:

```text
dependency-maintenance-962
mode        = lock-refresh
owner issue = #962
selectors   =
  drupal/core-recommended
  drupal/core-composer-scaffold
  drupal/core-project-message
  drupal/core-recipe-unpack
  drupal/core-dev
  phpstan/phpstan
  composer/composer
```

The selector list is repository-owned and fixed. The profile also owns stable
version rules for every selector:

```text
drupAL/core-*   -> 11.4.x stable
phpstan/phpstan -> 2.2.x stable
composer/composer -> 2.10.x stable
```

This mode does **not** authorize new major, alpha, beta, RC or dev lines.

For `lock-refresh`, the target PR may have no file delta before resolution. This
is intended for a target branch represented by an empty commit when a PR is
needed before the generated lock exists. The gateway and trusted workflow both
require the target `composer.json` to be byte-identical to the current PR base.

The resolver executes only the repository-owned selectors:

```text
composer update <fixed reviewed selectors>
  --with-all-dependencies
  --no-install
  --no-interaction
  --no-progress
  --no-scripts
```

An unscoped global `composer update` is not permitted.

The historical package mode still requires exactly the reviewed
`composer.json` semantic delta before resolution.

## Resolver behavior

The trusted self-hosted job starts an isolated DDEV project and runs the fixed
profile.

It fails closed unless:

- the profile mode is known;
- lock-refresh selectors are non-empty and match the repository-owned result
  rules exactly;
- selectors use bounded Composer package-name syntax;
- no untracked file remains after removing the temporary DDEV override;
- no staged file exists;
- `composer.lock` is the only modified tracked file;
- every result-bound package appears exactly once across normal/dev lock
  packages;
- every resolved version matches its repository-owned stable-version rule.

The artifact remains exactly:

```text
composer.lock
result.json
```

For lock-refresh, `result.json` binds at least:

```text
status
request_id
profile
mode
target_head_sha
owner_issue
reviewed_selectors
resolved_versions
composer_lock_sha256
```

Package mode retains its package/resolved-version metadata for compatibility.

## Publishing behavior

The GitHub-hosted publishing job downloads the artifact and independently
revalidates it before copying the lockfile into an exact detached checkout of
the original PR HEAD.

Immediately before push it queries the live PR again. If the PR advanced since
the request, publication fails rather than rebasing, force-pushing or applying
the lockfile to a different target.

The publisher stages and commits exactly `composer.lock`. It is the only job
with repository write permission.

## Observability contract

Issue #534 adds fail-closed observability without changing the self-hosted
permission model.

After a request has passed schema, PR, exact-HEAD and profile checks, the
GitHub-hosted gateway snapshots the existing trusted workflow run IDs,
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

The observable gateway does not receive product secrets and does not make the
self-hosted job write-capable. A failure remains a failure.

## Explicit non-capabilities

This route does **not** provide:

- arbitrary Composer commands;
- arbitrary package names or constraints;
- arbitrary selectors;
- arbitrary shell execution;
- unscoped global Composer update;
- generic branch mutation;
- self-hosted GitHub write credentials;
- provider/API secrets;
- product configuration generation;
- automatic module enablement;
- automatic Configuration Language Lock enforcement;
- automatic merge authority.

Adding another package or maintenance selector set still requires a reviewed
repository change. Do not extend the request schema with executable Composer
inputs.
