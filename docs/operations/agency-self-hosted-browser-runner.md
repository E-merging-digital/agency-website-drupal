# Agency self-hosted browser validation runner

Status: **OPERATIONAL — browser validation stabilization in progress**  
Issue: #400  
Repository: `E-merging-digital/agency-website-drupal`

The authoritative inventory of machine capabilities is:

```text
docs/operations/execution-capabilities.md
```

Every agent must reload that registry before concluding that a runner, browser,
Playwright MCP or Chrome DevTools MCP capability is absent.

## 1. Architecture

Agency reuses the already managed host `preflight-runner-01`, but it does **not**
reuse the Preflight GitHub runner process/account.

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

The Agency runner has a separate Unix account, installation directory, workdir
and service.

Observed runtime on trusted Agency runs:

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
```

## 2. Security model

Agency is a public repository. Untrusted pull-request code must never run
directly on the self-hosted runner.

The browser validation route is therefore **trusted dispatch only**:

```text
agent / authorized operator
        |
        v
control branch request
        |
        v
GitHub-hosted validation gateway
        |
        v
same-repository + exact-head validation
        |
        v
trusted workflow from main
        |
        v
agency-browser-runner-01
```

The self-hosted workflow has no direct `pull_request` or `push` trigger.

## 3. Governed workflows

### `.github/workflows/agent-browser-validation-dispatch.yml`

The GitHub-hosted control surface observes only:

```text
branch = agency/browser-validation-dispatch-control
file   = .agency/browser-validation-request.json
```

A request contains a bounded request id, exact SHA and optional PR number. The
gateway validates the authorized actor, exact target, same-repository PR state
and allowed infrastructure before dispatching the trusted self-hosted workflow.

### `.github/workflows/self-hosted-browser-validation.yml`

The trusted workflow targets only:

```yaml
runs-on:
  - self-hosted
  - linux
  - x64
  - agency
  - ddev
  - browser
```

Before reaching the runner, a GitHub-hosted preflight validates the dispatch
actor and exact target.

## 4. Reproducible Agency rebuild

No mutable local database dump is required.

Each run creates an isolated DDEV project and executes the equivalent of:

```text
exact checkout
-> Node 24
-> npm ci
-> Playwright Chromium
-> ddev start
-> composer install
-> drush site:install --existing-config
-> Content Sync validate
-> Content Sync dry-run
-> Content Sync apply
-> drush cr / status / config:status
-> npm run browser:validate
```

The DDEV database image is MariaDB 11.8.

The temporary DDEV project is deleted at the end of the run and the workflow
verifies that the workspace returns clean.

## 5. Browser validation evidence

The workflow publishes:

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

- desktop/mobile result JSON;
- success screenshots;
- Playwright failure screenshots;
- traces on failure;
- console/network details;
- Playwright HTML report.

These artifacts are not merely diagnostic attachments. An agent can fetch them
from GitHub and visually inspect the rendered interface itself.

On 2026-08-15 the agent downloaded and inspected the desktop and mobile failure
screenshots from run `31881266521`. The desktop screenshot visibly showed the
Blog page without primary navigation links. The mobile screenshot visibly showed
an opened navigation drawer with no links. This independently confirmed the
functional assertions.

## 6. Browser validation state on 2026-08-15

The executor itself is proven operational:

- trusted dispatch works;
- exact SHA checkout works;
- Node/Chromium installation works;
- DDEV isolated rebuild works;
- Drupal `--existing-config` installation works;
- Content Sync validation/dry-run/apply works;
- artifacts upload works;
- cleanup returns the runner workspace clean.

Run `31881409021` executed against PR #399 exact HEAD
`493232b4b3076543ccc58bb56df49681d33882ae`.

It proved that the previous `/cookies` 404 is fixed:

```text
console_errors        = 0
unexpected_http_4xx   = 0
http_5xx              = 0
failed_requests       = 0
```

The run still fails because the fresh installation renders no primary navigation
links. That application bootstrap defect is being fixed before #399 can merge.
The failure is **not** a runner/Playwright infrastructure failure.

## 7. Playwright Test

Playwright Test is the deterministic browser-validation layer.

Current vertical slice checks the public Blog in desktop and mobile contexts and
is intended to cover real navigation, final DOM, console, network and visual
evidence.

Playwright Test complements Drupal `BrowserTestBase`; neither replaces the
other.

## 8. Playwright MCP and Chrome DevTools MCP

Both MCP capabilities are available on the Agency self-hosted runner:

```text
Playwright MCP      = AVAILABLE
Chrome DevTools MCP = AVAILABLE
```

They are project-executor capabilities.

Important invariant:

```text
operator-surface capability
!=
project-executor capability
```

Therefore, a ChatGPT conversation that does not expose a direct MCP tool must
never conclude that the MCP server is absent from Agency.

Correct interpretation when direct transport is missing:

```text
MCP AVAILABLE ON RUNNER
DIRECT COCKPIT MCP ROUTE NOT EXPOSED IN THIS SESSION
```

Playwright MCP is useful for interactive browser navigation, accessibility/DOM
inspection and iterative diagnosis.

Chrome DevTools MCP is useful when deeper DevTools-level inspection is required,
for example console/network/CSS/JS/runtime diagnosis beyond the deterministic
Playwright contract.

Neither MCP is required for the CI verdict. The deterministic Playwright suite
remains the reproducible proof.

## 9. Private-only MCP rule

MCP must not be exposed publicly.

Allowed architecture is local/private/governed execution on the managed runner.
Do not expose MCP using `0.0.0.0`, a public forwarded port or a browser profile
containing uncontrolled persistent credentials.

A direct remote control-plane route must remain private and governed if/when it
is materialized.

## 10. Authentication strategy

The current public Blog validation needs no secret.

Future authenticated scenarios should use ephemeral credentials and Playwright
`storageState` under:

```text
tests/browser/.auth/
```

That directory remains ignored. Passwords, cookies, storage state and sensitive
browser profiles must never be committed or uploaded as normal artifacts.

## 11. Provisioning reference

The repository-owned bootstrap script is:

```text
scripts/runner/bootstrap-agency-browser-runner.sh
```

It creates the dedicated `agency-runner` account and installs/configures the
repository-scoped runner under:

```text
/opt/actions-runner-agency
```

The runner registration token is ephemeral and must never be committed.
Provisioning is already completed for the live runner; this section is retained
for recovery/rebuild purposes.

## 12. Merge-gate policy

Browser Validation is not yet a required GitHub merge check.

Target sequence remains:

```text
executor operational
-> stable real runs
-> application bootstrap defects corrected
-> complete PASS evidence
-> repeated confidence
-> separate decision on required merge gate
```

## 13. Reload rule

Before stating any of the following:

```text
there is no Agency runner
Playwright is unavailable
Playwright MCP is unavailable
Chrome DevTools MCP is unavailable
UI cannot be inspected
manual human execution is required
```

reload:

1. `docs/operations/execution-capabilities.md`;
2. this document;
3. the latest issue/PR state;
4. the latest trusted workflow runs and artifacts.

Live executor evidence wins over stale documentation; when they disagree, fix
the documentation rather than forgetting the capability.
