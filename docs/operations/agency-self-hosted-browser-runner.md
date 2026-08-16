# Agency self-hosted browser validation runner

Status: **OPERATIONAL**  
Executor foundation: #400  
Canonical config hardening: #427  
Repository: `E-merging-digital/agency-website-drupal`

The authoritative inventory of machine capabilities is:

```text
docs/operations/execution-capabilities.md
```

Every agent must reload that registry before concluding that a runner, browser,
Playwright MCP or Chrome DevTools MCP capability is absent.

## 1. Architecture

Agency uses a dedicated repository-scoped runner on the managed host
`preflight-runner-01`:

```text
preflight-runner-01
|
+-- Preflight runner
|   account: preflight-runner
|   labels: self-hosted, linux, x64, preflight, ddev
|
+-- Agency Browser runner
    account: agency-runner
    name: agency-browser-runner-01
    labels: self-hosted, linux, x64, agency, ddev, browser
```

The Agency runner has its own Unix account, runner installation, workdir and
service. It does not reuse the Preflight runner process.

Observed runtime:

```text
runner               = agency-browser-runner-01
host                 = preflight-runner-01
account              = agency-runner
GitHub Actions runner= 2.336.0
Docker               = 29.7.2
DDEV                 = 1.25.3
MariaDB              = 11.8
PHP                  = 8.4
Node browser jobs    = 24 via actions/setup-node
Chromium             = Playwright-managed
```

## 2. Security model

Agency is public. Untrusted pull-request code must never execute directly on the
self-hosted runner.

The runner is therefore reached only through trusted dispatch:

```text
agent / authorized operator
-> control branch request
-> GitHub-hosted validation gateway
-> exact same-repository target validation
-> trusted workflow from main
-> agency-browser-runner-01
```

The trusted workflow has no direct `pull_request` or `push` trigger. Fork PRs
are refused. A PR that changes `.github/`, `.ddev/`, `.agency/` or
`scripts/runner/` must first merge those infrastructure changes to trusted
`main` before they can be exercised on the self-hosted runner.

## 3. Governed dispatch

Control surface:

```text
branch = agency/browser-validation-dispatch-control
file   = .agency/browser-validation-request.json
```

A request contains:

```json
{
  "request_id": "bounded-auditable-id",
  "pr_number": 399,
  "head_sha": "40-character-exact-sha"
}
```

`pr_number=0` validates the exact live `main` SHA. For a PR, the gateway checks
that it is OPEN, same-repository, based on `main`, and that the requested SHA is
the live PR HEAD.

## 4. Canonical fresh rebuild

No local database dump is part of the proof. Every run uses an isolated DDEV
project and reconstructs Agency from repository-owned state.

The canonical sequence is:

```text
exact checkout
-> Node 24
-> npm ci
-> Playwright Chromium
-> DDEV isolated project
-> composer install
-> drush site:install --existing-config
-> drush config:import -y
-> drush cache:rebuild
-> config:status MUST report no differences
-> Content Sync validate
-> Content Sync dry-run
-> Content Sync apply
-> drush cache:rebuild
-> config:status MUST still report no differences
-> Playwright desktop + mobile
-> evidence upload
-> DDEV/workspace cleanup
```

The explicit final `config:import` is required because installation profiles and
module lifecycle hooks can mutate active configuration after Drupal's initial
install-time import. The runner must prove the repository's final canonical
configuration, not merely a bootable site.

`config:status` is fail-closed. Any `Different`, `Only in DB` or `Only in sync
dir` state prevents the browser proof from being accepted.

Content Sync remains downstream of configuration convergence and must not create
configuration drift.

## 5. Proven Browser Validation capability

The Browser Validation capability itself is fully proven and merged.

Final #399 proof on 2026-08-15:

```text
exact PR HEAD       = d4e8e34fd8936871b01702ec1102ad104871068a
standard CI         = SUCCESS
self-hosted run     = 31883354237
browser result      = PASS
functional          = PASS
DOM                 = PASS
visual desktop      = PASS
visual mobile       = PASS
console errors      = 0
page errors         = 0
unexpected 4xx      = 0
5xx                 = 0
failed requests     = 0
```

PR #399 was merged as:

```text
b2d6272ef0b04fc62bf6378b6a71bbb6aa69e0f6
```

The agent downloaded the final artifact and visually inspected the desktop and
mobile screenshots before merge. Desktop visibly rendered the six public main
navigation links and the active Blog state; mobile rendered cleanly and the
scenario exercised the drawer/navigation path.

Therefore Browser Validation is not merely provisioned: it has a complete fresh
Agency PASS proof.

## 6. Evidence published by the runner

The workflow uploads:

```text
artifacts/browser-validation/
playwright-report/
test-results/
```

The primary machine-readable verdict is:

```text
artifacts/browser-validation/result.json
```

Evidence can include:

- desktop/mobile JSON evidence;
- success screenshots;
- failure screenshots;
- Playwright traces;
- error/accessibility context;
- console and network findings;
- Playwright HTML report.

The agent can fetch these artifacts from GitHub and visually inspect the rendered
UI itself. This artifact route is already operational from the current
ChatGPT/GitHub control surface.

## 7. Playwright MCP and Chrome DevTools MCP

Both interactive MCP capabilities are available on the Agency runner:

```text
Playwright MCP      = AVAILABLE
Chrome DevTools MCP = AVAILABLE
```

They are **project-executor capabilities**.

Fundamental invariant:

```text
operator-surface capability
!=
project-executor capability
```

A ChatGPT session without a directly exposed MCP transport must therefore report:

```text
MCP AVAILABLE ON RUNNER
DIRECT COCKPIT MCP ROUTE NOT EXPOSED IN THIS SESSION
```

It must never infer that the MCP capability itself is absent.

Playwright MCP is suited to interactive navigation, DOM/accessibility inspection
and iterative browser diagnosis. Chrome DevTools MCP complements it when deeper
console/network/CSS/JavaScript/runtime inspection is useful.

Neither MCP replaces the deterministic Playwright Test proof.

## 8. Private-only MCP rule

MCP must remain local/private/governed on the managed runner. Do not expose it on
`0.0.0.0`, through a public forwarded port or with an uncontrolled persistent
browser profile.

If a direct control-plane transport is materialized, it must remain private and
governed.

## 9. Authentication strategy

The public Blog proof requires no secret.

Future authenticated UI scenarios should use ephemeral credentials and
Playwright `storageState` under:

```text
tests/browser/.auth/
```

That directory remains ignored. Passwords, cookies, auth state and sensitive
profiles must never be committed or uploaded as normal artifacts.

## 10. Provisioning and recovery

Repository-owned bootstrap script:

```text
scripts/runner/bootstrap-agency-browser-runner.sh
```

Runner installation:

```text
/opt/actions-runner-agency
```

The live runner is already provisioned. Registration tokens are ephemeral and
must never be committed. The provisioning documentation is retained for
recovery/rebuild, not as a pending manual setup step.

## 11. Merge-gate policy

Browser Validation is operational but is not yet configured as a required GitHub
check. That policy decision remains separate from the capability itself.

Current expectation for significant frontend/interactive work remains:

```text
technical tests
-> governed fresh browser proof when relevant
-> machine verdict
-> visual evidence review
-> merge
```

## 12. Reload rule

Before stating any of the following:

```text
there is no Agency runner
Playwright is unavailable
Playwright MCP is unavailable
Chrome DevTools MCP is unavailable
UI cannot be inspected
Jonathan must run this manually
```

reload:

1. `docs/operations/execution-capabilities.md`;
2. this document;
3. the relevant issue/PR;
4. the latest trusted workflow runs and artifacts.

Live executor evidence wins over stale documentation. When they disagree, fix
the documentation rather than forgetting the capability.
