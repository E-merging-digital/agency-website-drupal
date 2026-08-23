# Configuration Language Lock evaluation

Status: **TRUSTED CANDIDATE EVALUATION CONTRACT**  
Owner issues: #630, #632  
Product candidate: #628 / PR #629

## Purpose

This route proves whether `drupal/config_language_lock:^1.0` can be introduced into Agency without silently enabling Agency's future `locked_langcode: en` policy before that migration is explicitly reviewed.

The route is an evaluation primitive only. Presence of this workflow on `main` does **not** mean Configuration Language Lock is adopted or enabled in Agency.

The canonical policy remains:

```text
status                           = migration_required
canonical configuration language= en
enforce_consistency              = false
```

The baseline captured for #609 remains authoritative for the pre-migration state.

Upstream behavior is also part of the contract. Configuration Language Lock documents that installing the module without selecting a lock preserves Drupal's normal behavior. When Locale is enabled and no lock is set, Locale's extension-install hooks run normally. On a site whose default language is French, that native Drupal behavior can rewrite the **source language of extension-managed active configuration** toward `fr` during module installation.

Upstream reference:

```text
https://project.pages.drupalcode.org/config_language_lock/
```

This Locale footprint is not Configuration Language Lock enforcement. The proof must therefore distinguish those two mechanisms rather than treating any active-config hash change as lock enforcement.

## Composer materialization

The governed Composer route owns the repository profile:

```text
profile          = config-language-lock-628
package          = drupal/config_language_lock
constraint       = ^1.0
resolved version = stable 1.0.x
owner issue      = #628
```

The control request exposes only:

```text
request_id
pr_number
head_sha
profile
```

Package names, constraints, Composer commands and shell arguments are not request inputs. The gateway explicitly allowlists repository-owned profiles and fails closed for every other value.

Before materialization, PR #629 must differ from its base by exactly `composer.json`, and semantic validation proves that the only change is:

```json
"drupal/config_language_lock": "^1.0"
```

The self-hosted resolver remains `contents: read` and `persist-credentials: false`. It publishes only a bounded `composer.lock` artifact. The GitHub-hosted publisher alone can fast-forward the validated lock to the PR branch after a live HEAD recheck.

The first real resolution for #628 produced:

```text
resolved package = drupal/config_language_lock 1.0.0
trusted run      = 32562142545
lock SHA-256     = 1b445381f71964aef29d11a92ddfea0f8df3e14b765c1a1d055ad54c5c785461
```

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

## First evaluation and corrected model

Run `32562350246` was intentionally fail-closed and produced the evidence that refined this contract.

The fresh baseline was clean at 595 active/repository objects. Enabling `config_language_lock 1.0.0` without a lock created only `config_language_lock.settings`, with:

```text
locked_langcode      = null
follow_site_default  = false
```

During the same extension install, Locale imported the module's French translations and reported 43 configuration objects updated. The fingerprint observed 19 existing objects with changed hashes and 12 source-langcode transitions. Every transition was toward the Drupal site default `fr`; there was no normalization toward Agency's future canonical `en` lock.

Observed distribution:

```text
before enable : none=59, en=183, fr=352, und=1
after enable  : none=59, en=172, fr=364, und=1
```

`system.menu.footer` remained `langcode: und`. `language.entity.und` and `language.entity.zxx` remained the semantic language entities `und` and `zxx`, while the source language of those configuration objects was among the Locale `en -> fr` transitions. These concepts must not be conflated.

First evaluation artifact:

```text
artifact = 9473184700
SHA-256 = 82407f130c6b457bff50976378ec098fac48ca2bce6e76536c15bb5dfcd2f17b
```

The first workflow stopped before uninstall because its old intermediate gate was too strict. Issue #632 corrects that proof design; it does not weaken the product invariant.

## Evaluation sequence

```text
trusted main workflow
-> exact PR HEAD read-only checkout
-> isolated DDEV
-> composer audit --locked
-> composer install from exact lock
-> site:install --existing-config
-> canonical cim / cr / config:status
-> fingerprint entire active configuration
-> enable config_language_lock with no lock configured
-> fingerprint active configuration again
-> classify native Locale footprint vs lock enforcement
-> uninstall config_language_lock even if semantic assessment failed
-> fingerprint post-uninstall state
-> record any surviving native Locale drift
-> canonical cim in ephemeral DDEV
-> require exact pre-enable fingerprint restoration
-> clean config:status
-> workspace/config sync unchanged
-> artifact + cleanup
```

`scripts/runner/config-language-lock-state.php` reads every active configuration object, canonicalizes nested map ordering, records a SHA-256 per object and globally, records each object's source `langcode` and semantic `id`, and exposes the semantic watch values used by #609.

## Accepted non-enforcing enable footprint

The route remains fail-closed.

After module enable:

- no pre-existing configuration object may disappear;
- the only new configuration object must be `config_language_lock.settings`;
- `locked_langcode` must be `null`;
- `follow_site_default` must remain `false`;
- all source-langcode transitions must be native-Locale shaped: previous value `en` or absent, new value equal to Drupal's site default `fr`;
- no object may transition toward Agency's future canonical `en` lock;
- every active/repository langcode mismatch after enable must correspond exactly to one observed Locale transition;
- `system.menu.footer` must remain source `langcode: und`;
- `language.entity.und` must retain semantic `id: und`;
- `language.entity.zxx` must retain semantic `id: zxx`;
- versioned translation-directory inventory and `config/sync` must not change.

Changes to full config hashes are recorded, not silently ignored. They are classified separately from source-langcode transitions so Locale translation updates remain observable.

## Uninstall and reversibility requirement

Uninstall is executed whenever module enable itself succeeded, even if the semantic gate reports a failure. This guarantees that every evaluation gathers rollback evidence.

Immediately after uninstall:

- the module must be disabled;
- module-owned configuration must be absent;
- compared with the post-enable state, only `core.extension` and removal of the module-owned settings are expected; no additional existing config mutation is accepted;
- Locale-originated source-language drift may still exist because Drupal does not reverse extension-install translation processing merely by uninstalling the extension.

The route records `config:status` at this point instead of pretending uninstall alone restores repository canonical state.

Then, inside the ephemeral DDEV only, the route performs repository-owned `drush cim -y`. After that canonical restore:

- all 595 active configuration fingerprints must exactly equal the pre-enable baseline;
- overall SHA-256 must equal the pre-enable SHA-256;
- repository/active langcode mismatches must be zero;
- `config:status` must report no differences;
- `config/sync` remains untouched.

There is still no `cex`. Canonical restoration is from repository state to disposable active state, never the reverse.

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

## Evidence and decision rule

The workflow uploads `artifacts/config-language-lock-evaluation` with:

```text
composer-audit.json
state-before.json
language-before.json
state-enabled.json
language-enabled.json
enable-comparison.json
state-after-uninstall.json
language-after-uninstall.json
uninstall-comparison.json
config-status-after-uninstall.txt
state-restored.json
language-restored.json
config-status-restored.txt
result.json
```

A PASS means only that **non-enforcing mode is understood and reversible in Agency's fresh-DDEV model**. It does not authorize `locked_langcode: en`, bulk migration, production enablement or adoption.

#609 remains `migration_required` until the later EN migration dry-run and transformation tests are separately green.

Refs #609 #608 #614 #628 #629 #630 #632 #531 #412.
