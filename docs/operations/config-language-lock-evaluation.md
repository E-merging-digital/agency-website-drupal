# Configuration Language Lock evaluation

Status: **TRUSTED CANDIDATE EVALUATION CONTRACT**  
Owner issue: #630  
Product candidate: #628 / PR #629

## Purpose

This route proves whether `drupal/config_language_lock:^1.0` can be introduced into Agency without silently rewriting the existing mixed configuration-language state before Agency explicitly adopts `locked_langcode: en`.

The route is an evaluation primitive only. Presence of this workflow on `main` does **not** mean Configuration Language Lock is adopted or enabled in Agency.

The canonical policy remains:

```text
status                           = migration_required
canonical configuration language= en
enforce_consistency              = false
```

The baseline captured for #609 remains authoritative for the pre-migration state.

## Composer materialization

The existing governed Composer route receives one new repository-owned profile:

```text
profile          = config-language-lock-628
package          = drupal/config_language_lock
constraint       = ^1.0
resolved version = stable 1.0.x
owner issue      = #628
```

The control request still exposes only:

```text
request_id
pr_number
head_sha
profile
```

Package names, constraints, Composer commands and shell arguments are not request inputs. The gateway explicitly allowlists `canvas-ai-agents-530` and `config-language-lock-628`; every other profile fails closed.

Before materialization, PR #629 must differ from its base by exactly `composer.json`, and semantic validation proves that the only change is:

```json
"drupal/config_language_lock": "^1.0"
```

The self-hosted resolver remains `contents: read` and `persist-credentials: false`. It publishes only a bounded `composer.lock` artifact. The GitHub-hosted publisher alone can fast-forward the validated lock to the PR branch after a live HEAD recheck.

## DDEV evaluation route

Trigger, after the real lock has been materialized:

```text
/agency-config-language-lock evaluate
```

The trigger is accepted only on PR #629, from `E-merging-digital`, while the PR is OPEN, draft, same-repository, based on live `main`, and uses branch:

```text
feature/628-config-language-lock-candidate
```

The target diff must be exactly:

```text
composer.json
composer.lock
```

The lock must resolve `drupal/config_language_lock` to stable `1.0.x`.

## Evaluation sequence

```text
trusted main workflow
-> exact PR HEAD read-only checkout
-> isolated DDEV
-> composer install from exact lock
-> composer audit --locked
-> site:install --existing-config
-> cim / cr / config:status
-> fingerprint entire active configuration
-> enable config_language_lock with no lock configured
-> fingerprint active configuration again
-> validate bounded enable delta
-> uninstall config_language_lock
-> fingerprint active configuration again
-> require exact baseline restoration
-> config:status clean
-> workspace/config sync unchanged
-> artifact + cleanup
```

`scripts/runner/config-language-lock-state.php` reads every active configuration object, canonicalizes nested map ordering, records a SHA-256 per object and a global SHA-256, and exposes the semantic watch values used by #609.

## Accepted enable delta

Existing active configuration may not be silently rewritten.

After module enable:

- no pre-existing object may disappear;
- the only pre-existing object allowed to change is `core.extension`;
- newly created objects, if any, must be owned by the `config_language_lock.*` namespace;
- module-owned configuration may not contain a non-empty `locked_langcode` anywhere;
- `system.menu.footer` must remain `langcode: und`;
- `language.entity.und` and `language.entity.zxx` must remain present and unchanged.

The route performs no `cex` and does not materialize `locked_langcode: en` or `follow_site_default: false`.

## Reversibility requirement

After uninstall, the entire active-configuration fingerprint must exactly equal the baseline captured immediately before enable. `config:status` must report no differences, and `config/sync` must remain untouched.

A failure at any point is evidence against adoption, not permission to normalize configuration manually.

## Security boundaries

The route provides no:

- arbitrary PR or branch selection;
- arbitrary package/constraint/Composer arguments;
- arbitrary shell input;
- self-hosted repository write credentials;
- provider/API secret;
- production SSH/access;
- Content Sync mutation;
- config export;
- automatic Configuration Language Lock enforcement.

## Evidence

The workflow uploads `artifacts/config-language-lock-evaluation` with machine-readable fingerprints, enable delta, Composer audit and a bounded result. The result becomes authoritative only after the route has executed successfully on the exact #629 HEAD.

Until then the capability itself is **CANDIDATE** and #609 remains in its non-enforcing evaluation phase.

Refs #609 #608 #614 #628 #629 #531 #412.
