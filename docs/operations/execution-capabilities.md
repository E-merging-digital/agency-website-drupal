# Agency execution capabilities

Status: **AUTHORITATIVE CAPABILITY REGISTRY**  
Repository: `E-merging-digital/agency-website-drupal`  
Owner issue: #421  
Last materialized: 2026-08-15

## 1. Purpose

This file is the durable source of truth for Agency machine execution and UI
inspection capabilities.

Every agent working on this repository MUST read this file before concluding
that an executor, browser, MCP, DDEV environment or UI inspection capability is
missing, unavailable or requires a human.

Fundamental invariant:

```text
operator-surface capability
!=
project-executor capability
```

Therefore:

```text
tool not exposed directly in the current ChatGPT cockpit
!= tool absent from Agency
!= HUMAN_REQUIRED
```

If a governed machine route exists, use or extend that route rather than asking
Jonathan to perform a mechanical step.

## 2. Self-hosted Agency runner

Agency has a dedicated repository-scoped self-hosted runner on the already
managed Linux host:

```text
host                 = preflight-runner-01
runner               = agency-browser-runner-01
account              = agency-runner
runner directory     = /opt/actions-runner-agency
workdir              = /opt/actions-runner-agency/_work
labels               = self-hosted, linux, x64, agency, ddev, browser
```

The Agency runner is distinct from the Preflight runner even though both use the
same host. Their Unix accounts, runner installation directories, workdirs and
services are separate.

Observed runtime evidence from trusted Agency runs includes:

```text
Ubuntu               = 24.04 LTS class host
GitHub Actions runner= 2.336.0
Docker               = 29.7.2
DDEV                 = 1.25.3
DDEV database image  = MariaDB 11.8
PHP in DDEV           = 8.4
Node for browser jobs = 24 via actions/setup-node
Chromium              = Playwright-managed
```

The repository `.ddev/config.yaml` also targets MariaDB 11.8. Any older
reference to MariaDB 10.11 is historical documentation debt, not the current
Agency DDEV runtime.

## 3. Browser and UI capabilities available on the runner

The following capabilities are available on the Agency self-hosted runner and
must not be rediscovered or proposed for installation as if they were absent:

| Capability | Status | Primary use |
| --- | --- | --- |
| Playwright Test | AVAILABLE | Reproducible browser validation, desktop/mobile, DOM, console, network, screenshots and traces |
| Chromium for Playwright | AVAILABLE | Real browser used by the validation suite |
| Playwright MCP | AVAILABLE | Interactive agent-driven browser inspection on the runner |
| Chrome DevTools MCP | AVAILABLE | Interactive DevTools-level diagnosis on the runner when deeper browser/CSS/JS/network inspection is useful |
| DDEV | AVAILABLE | Real Agency Drupal runtime |
| Docker | AVAILABLE | DDEV/container execution |

Playwright MCP and Chrome DevTools MCP are **project-executor capabilities**.
They remain available even when the current ChatGPT product surface does not
present them as direct top-level tools.

An agent MUST NOT infer `MCP unavailable` from `no MCP tool visible in this
chat`.

## 4. Governed browser validation route

The currently proven unattended route is:

```text
ChatGPT / authorized operator
-> GitHub control branch request
-> GitHub-hosted validation gateway
-> trusted workflow on main
-> agency-browser-runner-01
-> exact checkout
-> Node / Playwright / Chromium
-> isolated DDEV
-> Drupal --existing-config
-> Content Sync
-> browser validation
-> GitHub artifacts
```

The runner is never reached directly by an untrusted pull request. Agency is a
public repository, so self-hosted execution remains trusted-dispatch only.

The implementation is documented in:

```text
docs/operations/agency-self-hosted-browser-runner.md
```

## 5. What the agent can verify visually today

The browser workflow publishes evidence including:

```text
artifacts/browser-validation/result.json
artifacts/browser-validation/evidence/*.json
screenshots
Playwright test screenshots on failure
Playwright traces on failure
playwright-report
```

These GitHub Actions artifacts can be fetched by the agent and the screenshots
can be inspected visually. This means an agent can already perform an
asynchronous real-UI review from the runner output rather than relying only on
DOM assertions or log text.

Example of proven use on 2026-08-15: the agent downloaded the runner artifact
for self-hosted run `31881266521` and visually inspected both desktop and mobile
failure screenshots. The desktop screenshot showed the public Blog header with
no primary navigation links. The mobile screenshot showed an opened navigation
drawer with no navigation entries. Those observations independently confirmed
the browser assertions.

## 6. Interactive MCP versus artifact review

Do not conflate these two paths.

### Artifact-based review

This path is already reachable from the current ChatGPT/GitHub control surface:

```text
runner executes Playwright
-> artifact uploaded to GitHub
-> agent fetches artifact
-> agent examines screenshot / trace / result
```

It is asynchronous but gives the agent the final rendered browser evidence.

### Interactive Playwright MCP / Chrome DevTools MCP

Both MCP servers are available on the self-hosted runner. They support a more
interactive diagnostic loop from an agent runtime that is attached to the
runner and permitted to invoke them.

Availability on the runner does **not** imply that every ChatGPT conversation
has a direct MCP transport attached to that runner. If the current cockpit lacks
that direct transport, the correct conclusion is:

```text
MCP AVAILABLE ON PROJECT EXECUTOR
DIRECT COCKPIT ROUTE NOT CURRENTLY EXPOSED
```

not:

```text
MCP UNAVAILABLE
```

When a task benefits from interactive MCP and the current operator surface lacks
an invocation route, first look for an existing governed executor/agent route.
Only create a new route under a dedicated issue if the existing routes truly
cannot invoke the installed capability.

## 7. Tool responsibilities

Use the tools for complementary purposes:

```text
Drupal BrowserTestBase
  -> server-side/runtime Drupal functional confidence

Playwright Test
  -> deterministic real-browser merge/delivery evidence

Playwright MCP
  -> interactive browser navigation and inspection by an agent

Chrome DevTools MCP
  -> deeper DevTools diagnosis when console/network/CSS/JS/runtime inspection
     beyond the Playwright interaction layer is useful
```

MCP is not a replacement for deterministic tests and deterministic tests are not
a replacement for visual inspection.

## 8. Secrets and authenticated UI

Public validation requires no secret.

For future authenticated Drupal scenarios:

- use ephemeral credentials supplied to the runner through a governed secret
  surface;
- use Playwright `storageState` only as ephemeral runtime state;
- keep `tests/browser/.auth/` ignored;
- never commit cookies, passwords or browser profiles;
- never upload auth state as a normal artifact;
- exclude sensitive/private pages from screenshots unless the task explicitly
  requires them and the artifact policy is safe.

## 9. Reload rule for future agents

Before any statement such as:

```text
there is no runner
Playwright is unavailable
Playwright MCP is unavailable
Chrome DevTools MCP is unavailable
I cannot validate the UI
Jonathan must run this manually
```

reload, in this order:

1. `docs/operations/execution-capabilities.md`;
2. `docs/operations/agency-self-hosted-browser-runner.md`;
3. the relevant open issue/PR and latest trusted workflow runs;
4. only then decide whether a capability or governed route is actually missing.

If repository documentation and live executor evidence disagree, live evidence
wins and this registry must be corrected in the same bounded task or a dedicated
documentation task.
