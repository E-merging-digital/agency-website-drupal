# Drupal AI Translate parity proof

Issue: #382  
Decision parent: #378  
Evaluated standalone package: `drupal/ai_translate 1.3.1`

## Verdict

**PARITY_INSUFFICIENT — keep `agency_ai_translation` for now.**

The standalone project is viable and follows the Drupal AI provider abstraction, but version 1.3.1 cannot replace the current Agency workflow without a functional regression. No standalone dependency is retained by this issue and no custom translation code is removed.

The replacement decision must be reconsidered only after the blocking gaps below are resolved upstream or an explicitly approved Drupal-AI-native composition covers them without reintroducing a direct provider integration.

## Reproducible evidence

A GitHub-hosted disposable inspection resolved and materialized the standalone package without modifying the repository dependency graph:

- base repository state: `a7a7af96bb5a5f3feb95f9edeebe731fa9899933`;
- inspection workflow run: `31913777842`;
- inspected branch head: `988dbe8da3ca0f20cc03c4b4bb70d3968ed36c8a`;
- artifact: `issue-382-ai-translate-inspection-31913777842` / ID `9254371513`;
- artifact digest: `sha256:396ce6a1e102a7a4b042d77d04ae4a9d277bece18c743fc4ace11601980b0d96`;
- `composer require 'drupal/ai_translate:^1.3' --dry-run` resolved exactly `1.3.1` with no other lock operation;
- the actual package source, routes, permissions, services and configuration were captured before restoring `composer.json` and `composer.lock`.

No provider credential was configured and no provider request was made during the inspection.

## Parity matrix

| Capability | Standalone 1.3.1 | Evidence / consequence |
| --- | --- | --- |
| Drupal Content Translation integration | GREEN | Replaces the Content Translation overview controller and adds AI translation links for missing translations. |
| Drupal AI provider abstraction | GREEN | `TextTranslator` resolves the `translate_text` operation through `@ai.provider`; no direct OpenAI HTTP client is required. |
| Explicit source / target language | GREEN | Route and service calls carry `lang_from` / `lang_to`. |
| Create a missing translation | GREEN | Controller extracts fields, translates them and calls `addTranslation()` before save. |
| Human review redirect | GREEN, configuration required | `redirect_after_create: edit` redirects to the new translation edit form; package default is `list`. |
| Keep AI result unpublished | GREEN, configuration required | `translation_status: create_draft` explicitly unpublishes the generated translation; package default is `keep_original` and is therefore not acceptable for Agency guardrails. |
| Update / retranslate an existing translation | **BLOCKING GAP** | Controller contains `@todo support updating existing translations`; if the target exists it returns `Translation already exists.`. Drush follows the same rule. This loses the current explicit "generate/update" workflow. |
| Preserve human-edited target unless explicitly decided | PARTIAL | Existing targets are not touched at all, which is safe, but there is no supported explicit retranslation path when the editor does decide to regenerate. |
| Entity references / Paragraphs | GREEN in the synchronous extractor path, configuration required | `ReferenceFieldExtractor` supports `entity_reference` and `entity_reference_revisions`, recursively translates referenced content entities and begins from original reference values so failures do not blank the source structure. Agency structured pages use a translatable `entity_reference_revisions` field targeting Paragraphs. Article itself has no Paragraph field. |
| Provider failure does not delete source/reference | GREEN for the 1.3.1 reference fix / partial translation remains possible | Failed field translations are omitted from processed metadata and original reference values are retained. The batch still reaches insertion, so a partially translated target can be created and must be reviewed. |
| Pathauto / target alias regeneration | **BLOCKING / NOT PROVEN** | No `pathauto`, `path_alias` or alias-regeneration integration exists in the inspected 1.3.1 package source. The retained custom explicitly clears the target alias, enables Pathauto and asks the Pathauto generator to update the target-language alias. Replacing it would remove a proven behavior without an equivalent proof. |
| Minimal entity-specific permission boundary | **BLOCKING GAP** | The standalone execution route requires the global `create ai content translation` permission. The inspected route has no node update or bundle-specific Content Translation requirement. The overview link respects route access, but the direct route itself is not an equivalent entity-specific access contract. |
| Bulk editor workflow | GAP / non-blocking to the issue DoD but a real regression | Agency currently exposes a target-language bulk action. Standalone 1.3.1 provides entity UI links and Drush translation, not an equivalent `/admin/content` bulk editor action. |

## Agency content inventory relevant to the decision

### Article

`node.article` has Content Translation enabled. Its editorial fields are direct fields rather than Paragraphs. The body (`text_with_summary`) is translatable; the Article form also contains the short description, primary image and taxonomy references configured by the blog foundation and #381.

This means standalone AI Translate can cover the basic *first* Article translation path, but its lack of controlled retranslation and proven alias parity still blocks replacement.

### Structured pages / Paragraphs

The repository contains one node storage targeting Paragraphs: `field_home_components`, type `entity_reference_revisions`, unlimited cardinality, translatable. Multiple Paragraph bundles are Content Translation-enabled. Standalone 1.3.1 has a recursive reference extractor for this field type, gated by global reference defaults or per-field third-party settings and a recursion-depth setting.

This is technically compatible with the synchronous reference model, but it is not sufficient to override the blocking gaps above.

## Retained custom contract

Until parity becomes green, `agency_ai_translation` remains the production implementation. #382 strengthens the retained path rather than expanding its provider coupling:

- AI-generated or AI-regenerated target translations are forced unpublished before save;
- provider failure must leave both the source and an existing human-reviewed target unchanged;
- the explicit confirmation/review workflow remains mandatory;
- direct provider integration is frozen: no new direct-provider dependency or capability may be added;
- the custom remains a temporary compatibility layer to be deleted once a Drupal-AI-native replacement passes this matrix.

The custom still owns explicit target-language selection, controlled regeneration, recursive Paragraph handling and Pathauto regeneration. Its direct OpenAI-compatible client remains technical debt and is the principal reason to revisit the standalone project when its missing parity is addressed.

## Migration re-entry criteria

Re-evaluate replacement when all of the following can be proven on the then-current stable AI Translate release:

1. an existing target translation can be regenerated only after an explicit editor decision, without silently overwriting human work;
2. the target can be forced to draft/unpublished and the editor is redirected to review it;
3. Article and `field_home_components` Paragraph references survive success and provider failure;
4. target-language Pathauto aliases are regenerated or an equivalent repository-owned integration is proven;
5. execution access is constrained by both AI permission and native entity/bundle translation access;
6. the retained Agency regression tests pass against the replacement before any custom module deletion.
