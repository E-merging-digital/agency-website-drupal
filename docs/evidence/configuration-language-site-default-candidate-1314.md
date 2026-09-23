# #1314 — Candidate A site-default Language Lock proof

Status: **PROOF ONLY — NOT ADOPTED**

Authority: `PROJECT_LEAD_EXECUTION_AUTHORITY_1314_R1 / 5794179907`

Candidate A: site default `fr`, `locked_langcode=fr`, `follow_site_default=true`.
Exact tested versions: Drupal 11.4.7, Canvas 1.11.0, Config Language Lock 1.0.2.

This evidence comes only from disposable Kernel/DDEV proof. It does not change
Agency's current canonical policy and does not authorize PREPROD or PROD.

## Canvas contract

The exact installed `ConfigLanguageLockRequirementsHooks::runtimeRequirements()`
requires Canvas sites to have `follow_site_default=true` and the locked langcode
equal to the site default. Candidate A invokes that implementation directly and
the `config_language_lock_canvas_mismatch` requirement is absent.

```text
CANDIDATE_A_CANVAS_VERDICT = PASS
```

## Future write behavior

Candidate A makes FR the enforced language for future config-entity writes.

- Core config entity: explicit EN save becomes FR.
- Drupal Config Action: settings alone leave an existing EN object untouched;
  the later real Config Action save normalizes it to FR.
- Recipe: a real Recipe Config Action attempting EN persists FR.
- Extension installation: the real install hook queues Config Language Lock's
  global normalization batch over active configuration.
- Canvas Folder create/update: writes become FR; an existing EN Folder changes
  only when later saved.
- Drupal AI `AiAutomator`: create/update writes become FR; an existing EN
  Automator changes only when later saved.

```text
FUTURE_WRITE_BEHAVIOR_CORE = FR
FUTURE_WRITE_BEHAVIOR_CONFIG_ACTION = FR
FUTURE_WRITE_BEHAVIOR_RECIPE = FR
FUTURE_WRITE_BEHAVIOR_CANVAS = FR
FUTURE_WRITE_BEHAVIOR_AI = FR
```

These are intended under Candidate A except that extension installation proves
a latent global migration if the repository base remains EN.

## Existing configuration

Changing only the lock settings from EN/false to FR/true does not immediately
rewrite an existing config entity. Rewrites occur on later config-entity saves
or when the module's explicit manager/batch is invoked.

```text
EXISTING_CONFIG_AUTOREWRITE = NO
```

The administration form and extension-install hooks can queue that batch, so
`NO` must not be interpreted as permission to adopt the settings without a
migration plan.

## Translations

A controlled real `ConfigLanguageLockConfigManager` switch proves that FR
translated values can become the FR base while the former EN source becomes
the EN override. The redundant same-language FR override is removed, and
effective FR and EN values remain correct.

```text
TRANSLATION_PRESERVATION = PASS
```

In the full existing-config proof, Candidate A normalization expanded the EN
override collection from 1 repository-owned item to 194 active items before
the control import. This is a real source/override migration surface and must
be explicit, reviewed and versioned rather than occurring as a hotfix side
effect.

## und / zxx

The semantic IDs and locked status remain intact:

```text
LANGUAGE_UND_PRESENT = true
LANGUAGE_UND_ID = und
LANGUAGE_UND_LOCKED = true
LANGUAGE_ZXX_PRESENT = true
LANGUAGE_ZXX_ID = zxx
LANGUAGE_ZXX_LOCKED = true
UND_ZXX_PRESERVATION = PASS
```

Their technical config `langcode` becomes `fr` under the real normalization
path. That technical field is not their semantic ID.

## Disposable existing-config reproducibility

A disposable `site:install --existing-config` with only the lock settings
temporarily changed to FR/true completes, and the Canvas requirement passes.
However Config Language Lock reports `353 configuration objects updated`, and
the first config-status checkpoint is dirty.

One explicitly allowed control `cim` restores the repository FR/EN translation
collection names, but overall configuration still does not converge:
`config:status` reports 515 differences in the local proof.

```text
EXISTING_CONFIG_REPRODUCIBILITY = FAIL
```

This is a policy/migration result, not a CI defect. Candidate A cannot be
adopted as a two-setting change against the current EN-oriented repository.

## Policy recommendation

**Recommendation C:** evolve the policy schema to distinguish:

1. existing/historical canonical base-language intent (`en`);
2. future config-write enforcement (`site_default`, currently resolved to `fr`).

The current single `canonical_configuration_language` field cannot accurately
represent both concepts after Candidate A.

```text
CANONICAL_LANGUAGE_POLICY_RECOMMENDATION = C
DATA_MIGRATION_REQUIRED = NO
CONFIG_OBJECT_BULK_MIGRATION_REQUIRED = YES
LOCK_SETTINGS_CHANGE_REQUIRED = YES
POLICY_SCHEMA_CHANGE_REQUIRED = YES
```

`DATA_MIGRATION_REQUIRED=NO` means no business/content entity migration is
proven necessary. The configuration layer itself needs a controlled bulk
migration because the real module changes base language and translation
ownership across many config objects.

## Scope classification

| Surface | Classification |
| --- | --- |
| `config/sync/config_language_lock.settings.yml` | MUST_CHANGE_WITH_POLICY |
| `docs/configuration-language-policy.yml` | MUST_CHANGE_WITH_POLICY |
| `docs/configuration-language-governance.md` | MUST_CHANGE_WITH_POLICY |
| `docs/decisions/ADR-002-configuration-language-governance.md` | HISTORICAL_EVIDENCE_IMMUTABLE |
| `AGENTS.md` | MUST_CHANGE_WITH_POLICY |
| `ConfigurationLanguageGovernanceTest.php` | REUSABLE_TEST_TO_REBASELINE |
| `ConfigurationLanguageLockCoreWritesKernelTest.php` | REUSABLE_TEST_TO_REBASELINE |
| `ConfigurationLanguageLockRecipeInstallKernelTest.php` | REUSABLE_TEST_TO_REBASELINE |
| `ConfigurationLanguageLockCanvasKernelTest.php` | REUSABLE_TEST_TO_REBASELINE |
| `ConfigurationLanguageLockAiAutomatorKernelTest.php` | REUSABLE_TEST_TO_REBASELINE |
| `ConfigurationLanguageTranslationPromotionKernelTest.php` | OBSOLETE_ASSERTION_TO_RETIRE |
| `.github/workflows/config-language-lock-609-hosted-ddev.yml` | HISTORICAL_EVIDENCE_IMMUTABLE |
| #609/#692 evidence artifacts | HISTORICAL_EVIDENCE_IMMUTABLE |
| Candidate A #1314 proof tests | REUSABLE_TEST_TO_REBASELINE |

Do not rewrite historical evidence merely to reflect a future policy.

## Future implementation and promotion

A future adoption issue must introduce an explicit policy schema, change the
lock settings, run the Drupal-owned migration mechanism first in disposable
development, materialize and review the complete intentional config/translation
diff, rebaseline current policy tests, and prove existing-config installation
converges cleanly before any environment promotion.

```text
PREPROD_REQUIRED = YES
PROD_REQUIRED = YES
PROMOTION_METHOD = reviewed Drupal-owned config migration -> deterministic
repository config/translation diff -> governed PREPROD import -> governed PROD
import -> governed read-only post-promotion diagnostic
```

No Config Language Lock UI change, broad YAML replacement, or `cex` discovery
is authorized by #1314.
