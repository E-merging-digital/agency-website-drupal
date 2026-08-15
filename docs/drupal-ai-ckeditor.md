# Drupal AI CKEditor — governed Article authoring

Status: initial guarded rollout  
Owner issue: #380  
Parent roadmap: #32

## Purpose

Agency uses the upstream `ai_ckeditor` submodule from Drupal AI to provide
explicit, human-triggered writing assistance inside CKEditor 5.

This feature assists the editor. It does not generate or publish Article content
automatically.

## Version baseline

The project currently locks Drupal AI 1.4.6. On 2026-08-15 this is the latest
stable, security-covered 1.4.x release compatible with Drupal 11.4.5. Drupal AI
1.5 is still a release candidate and is not part of this rollout.

No experimental/dev dependency is introduced by #380.

## Scope

The initial rollout uses the existing `basic_html` CKEditor 5 text format and
adds the upstream `aickeditor` toolbar item immediately before Source Editing.

Enabled tools:

```text
Completion / Generate fragment = enabled
Modify with a prompt           = enabled
Fix spelling                   = enabled
Summarize                      = enabled
Reformat HTML                  = disabled
Tone                           = disabled
Translate                      = disabled
Help                           = disabled
```

Tone and Translate remain disabled so this ticket does not invent a `Tone of
voice` or `Languages` taxonomy. CKEditor Translate is also not a substitute for
Drupal entity translation; the FR/EN entity workflow belongs to #382.

## Provider abstraction

Every enabled AI CKEditor plugin is versioned with:

```yaml
provider: ''
```

This is deliberate. No provider name, model, API key or secret is encoded in the
editor configuration. At runtime, Drupal AI resolves the configured/default Chat
provider through its provider abstraction.

If no provider is configured or the provider is unavailable, the AI action may
fail, but normal Drupal authoring remains independent: editors can continue to
edit and save content without invoking an AI action.

Provider configuration and secrets remain environment-owned and must never be
committed.

## Guardrails

#380 is downstream of #379. The global deterministic guardrail set
`agency_editorial_baseline` applies to Drupal AI requests, including AI CKEditor
requests that use the Drupal AI provider abstraction.

Initial baseline:

```text
Input Length Limit = 20,000 characters
latest message only
pre-generate
stop threshold = 1.0
```

No second LLM guardrail call is enabled by default.

## Access control

AI CKEditor has its own permission:

```text
use ai ckeditor
```

Agency grants it to `content_editor` only in this rollout. The generic
`authenticated` role can still use the Basic HTML format but does **not** receive
the AI permission.

This distinction is intentional: access to a text format alone is not treated as
permission to incur AI cost or send selected content to a provider.

The ticket does not grant AI CKEditor administration privileges to editors.

## Human-in-the-loop contract

An AI request happens only after an editor explicitly chooses an AI action.
Selected text or editor context may then be sent to the configured provider.

The returned text remains an editable proposal inside the Article form. The
editor chooses whether to apply it and whether to save the Article. No action in
this rollout publishes content automatically.

## Multilingual contract

Article body remains translatable through Drupal. AI CKEditor Translate is
explicitly disabled because translating a selected fragment is not equivalent to
creating and governing a Drupal FR/EN entity translation.

Entity translation remains a separate roadmap item (#382).

## Failure behaviour

The expected failure mode is bounded:

```text
provider absent/down
-> requested AI action fails/degrades
-> CKEditor/Drupal form remains usable
-> existing text remains under editor control
-> normal save path remains available
```

No provider call occurs merely because the edit form is loaded.

## Validation gates

Before merge #380 requires:

1. standard CI green on the exact PR head;
2. fresh DDEV `site:install --existing-config`;
3. final `cim` and clean `config:status`;
4. Content Sync validate/dry-run/apply;
5. clean `config:status` after Content Sync;
6. public Playwright regression PASS;
7. evidence that the versioned CKEditor configuration imports canonically;
8. no secret or provider-specific credential in the diff.

A later provider-backed UI exercise may validate generation quality, but it must
use a governed runtime secret and is not permission to commit provider secrets.
