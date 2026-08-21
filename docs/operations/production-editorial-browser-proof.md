# Production editorial browser proof

Status: **GOVERNED / READ-ONLY**  
Owner: #586, execution hardening #588  
Initial consumer: #401

## Purpose

Ordinary Blog Articles are editor-owned in Drupal and intentionally outside
Content Sync. The canonical self-hosted Browser Validation route rebuilds a
fresh DDEV site from repository-owned state, so it cannot prove the final public
rendering of an editor-owned Article that exists only in production.

Issue #586 adds a separate, bounded proof for that case. It reuses the existing
Playwright Test, Chromium, browser audit and machine-readable result primitives,
but targets the public production origin read-only.

Issue #588 hardens the execution model after three self-hosted proof attempts
returned the same public HTTP 503 before Playwright (`32484873812`,
`32485251713`, `32485555392`) while the governed SSH Drupal inspection remained
healthy (`32485845917`). Public availability evidence must therefore be observed
from an independent external execution point rather than from the managed
Agency/DDEV host.

This route complements the DDEV Browser Validation route; it does not replace it.

## Control surface

The only v1 command is an exact issue comment:

```text
/agency-production-browser validate
```

The workflow accepts the command only when all of the following are true:

- the event belongs to an issue, not a pull request;
- the actor and comment author are exactly `E-merging-digital`;
- the issue is OPEN and owner-created;
- the issue number is exactly `401`;
- the workflow revision is the current live `main` revision.

No URL, selector, path, shell command, Playwright argument, credential or
mutation flag comes from the comment.

## Repository-owned profile

The v1 profile is:

```text
tests/browser/contracts/production-editorial-401.json
```

It fixes:

- production origin `https://emergingdigital.be`;
- FR and EN Article paths;
- expected H1 and document languages;
- category label;
- FR and EN image ALT values;
- canonical URLs;
- FR/EN/x-default hreflang URLs;
- sitemap endpoint;
- the bounded set of internal links that must resolve.

Adding another Article requires a reviewed repository change. The issue comment
cannot select an arbitrary contract.

## Execution model

```text
owner issue comment
-> GitHub-hosted ubuntu-24.04 external observer
-> verify OPEN owner-created #401 + live main
-> checkout exact trusted main without persisted credentials
-> verify immutable #401 contract
-> Node 24 + npm ci + Playwright Chromium
-> BROWSER_VALIDATION_BASE_URL=https://emergingdigital.be
-> existing browser validation runner
-> production editorial Playwright scenario
-> desktop + mobile evidence
-> artifact upload
-> bounded bot comment
-> workspace cleanup
```

The public proof deliberately does **not** use `agency-browser-runner-01`.
That self-hosted runner remains the correct executor for fresh-DDEV Browser
Validation and project-local MCP/browser work, but it is not an independent
observer of the public production route.

No DDEV, Drupal CLI, SSH, deployment or production secret is needed. The route
performs only public HTTP(S) reads against the fixed production origin.

## Browser checks

For both FR and EN the scenario verifies:

- initial HTTP response below 400;
- exact H1 and one-H1 structure;
- exact `<html lang>`;
- category visibility;
- feature image visibility, successful load and exact translated ALT;
- canonical URL;
- FR, EN and x-default hreflang values;
- absence of obvious horizontal overflow;
- required internal links are present and return below 400;
- the canonical Article URL is present in the sitemap or one of its bounded
  same-origin child sitemaps.

Across the complete desktop/mobile run the existing browser audit also fails on:

- console errors;
- uncaught page errors;
- same-origin unexpected 4xx responses;
- same-origin 5xx responses;
- failed same-origin browser requests.

## Evidence

The route uploads the existing Browser Validation evidence format:

```text
artifacts/browser-validation/result.json
artifacts/browser-validation/evidence/desktop.json
artifacts/browser-validation/evidence/mobile.json
artifacts/browser-validation/screenshots/
artifacts/browser-validation/test-results/
```

Four success screenshots are expected for #401:

```text
production-editorial-401-desktop-fr.png
production-editorial-401-desktop-en.png
production-editorial-401-mobile-fr.png
production-editorial-401-mobile-en.png
```

The issue bot comment publishes the run ID, exact trusted `main`, machine result,
functional/DOM/visual verdicts and console/network counters.

## Production deployment trigger invariant

The production deploy workflow places Drupal in maintenance mode while the new
release is prepared, switched and converged. A repository change that cannot
alter the deployed runtime must therefore not cause that maintenance window.

`deploy-production.yml` ignores a push when **all** changed files are confined
to these reviewed non-runtime areas:

```text
.github/**
docs/**
tests/**
web/modules/custom/agency_project_tests/**
*.md                     # repository-root Markdown
```

This is only a push filter. `workflow_dispatch` remains available for an
explicit deployment.

Runtime-bearing paths such as `scripts/**`, `web/**` outside the test module,
`config/**`, `composer.json` and `composer.lock` are intentionally **not** in
`paths-ignore`. Consequently a mixed commit containing any runtime file still
triggers the normal production deployment.

Do not widen the ignore set merely to suppress a deployment failure. A path may
be ignored only when changes under that path cannot affect the deployed Agency
runtime.

## Security invariants

The route must remain:

```text
public only
read-only
live-main only
owner-command only
profile-owned
URL-input free
selector-input free
shell-input free
secret free
mutation free
external-observer execution
```

Do not generalize it into a public crawler or arbitrary Playwright executor.
A new production proof target must be admitted through repository review.

## Relationship to other Agency routes

```text
canonical Browser Validation
  -> managed self-hosted runner
  -> fresh DDEV / repository-owned state

Governed editorial publication
  -> bounded production Article mutation

Governed editorial feature image
  -> bounded production field_feature_image mutation

Production editorial browser proof
  -> GitHub-hosted external observer
  -> bounded public read-only proof of the final editor-owned Article
```

The last route is the appropriate final UI/SEO evidence after the two production
mutation routes have converged.
