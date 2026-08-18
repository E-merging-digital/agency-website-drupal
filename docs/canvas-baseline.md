# Governed Drupal Canvas baseline

Status: **IMPLEMENTED — ADMISSION GOVERNED BY #526**  
Owner: #526  
Strategic decision: `docs/decisions/ADR-001-governed-ai-experience.md`  
Design contract: `DESIGN.md`

## 1. Purpose

Agency uses Drupal Canvas as the preferred visual composition engine for approved
Drupal components. This baseline proves **USE DRUPAL** before any Canvas AI or
page-generation work.

It does not create an Agency page builder and it does not permit prompt-generated
HTML/CSS.

The bounded composition is:

```text
approved Agency SDC catalog
-> Drupal Canvas Component entities
-> exactly three enabled Agency components
-> native Canvas Page
-> Drupal core Default Content export/import
-> Governed Content full apply
-> independent Playwright proof
```

## 2. Versions actually materialized

Repository state resolves:

```text
Drupal core   = 11.4.5
Drupal Canvas = 1.10.1
```

`composer.json` intentionally uses `drupal/canvas:^1.8`; `composer.lock` is the
runtime source of truth for the resolved version. Adding Canvas did not update
Drupal core or the existing locked dependency set beyond packages required by
Canvas.

Canvas requires and materializes the Media / Media Library surface used by its
editor. Those dependencies and their configuration are repository-owned.

## 3. Governed component allowlist

Canvas discovers Drupal components using its own native Component entities. Agency
does not replace this registry.

The only enabled components are derived from
`docs/design-system/component-catalog.yml` entries where:

```text
status = approved
approved_for_ai_composition = true
```

Current allowlist:

```text
sdc.emerging_digital.hero
sdc.emerging_digital.trust-list
sdc.emerging_digital.cta
```

Every other Canvas-discovered Block/SDC component remains present for upstream
compatibility/audit but has `status: false`.

`GovernedCanvasCatalogCiTest` fails if the Canvas-enabled set differs from the
Agency approved catalog.

## 4. Native proof page

The proof surface is a real `canvas_page` content entity:

```text
UUID  = 52600000-0000-4000-8000-000000000001
alias = /canvas-governed-sdc-baseline
```

Composition:

```text
Hero
-> Trust list
-> CTA
```

Only typed props are supplied. Hero/CTA slots are intentionally left empty rather
than inserting arbitrary rich HTML: Agency has not yet approved a nested text
primitive for Canvas composition.

## 5. Persistence model

The existing contrib `default_content` package remains the compatibility format
for historical Agency node/paragraph content.

The Canvas proof page uses **Drupal core Default Content** instead:

```text
web/modules/custom/emerging_digital_content/core_default_content/
```

Why:

- Drupal core 11.x provides `Drupal\Core\DefaultContent\Exporter` and `Importer`;
- Canvas integrates its component tree with the core export/import events;
- contrib `default_content 2.0.0-beta1` cannot export this Canvas tree and fails
  on Canvas `MaybeUrl` typed data;
- Agency does not add a custom normalizer to work around that contrib limitation.

The core exporter intentionally omits `component_version` from portable YAML.
On import, Canvas resolves each component to its active version. The governed
import adapter verifies the resolved IDs and versions after import and fails
closed if they diverge.

## 6. Governed Content integration

`emerging:governed-content --all` already runs after the canonical configuration
import in the trusted Agency browser workflow.

For full apply only:

```text
catalog validation
-> target/released-mapping preflight
-> Drupal Core DefaultContent Importer (Existing::Skip)
-> verify Canvas proof page + component versions
-> existing catalog writes
-> existing promotion/prune policy
```

Dry-run and targeted apply do not import the Canvas page.

This is a minimal **EXTEND DRUPAL** adapter around Drupal core's importer. It does
not define another content format or composition engine.

## 7. Canvas preview contract

`web/themes/custom/emerging_digital/emerging_digital.canvas.yml` uses Canvas'
native theme viewport configuration:

```text
mobile        = 390
tablet        = 1024
desktop       = 1440
large_desktop = 1920
```

The 390px and 1440px widths align with Agency's existing deterministic browser
proofs.

## 8. Validation

Permanent automated checks include:

- locked Canvas dependency and canonical Canvas/Media configuration;
- exact enabled-component allowlist;
- core-native Canvas Page YAML restricted to approved SDCs;
- Drupal core import validation and Canvas active-version resolution during full
  Governed Content apply;
- public Playwright scenario `tests/browser/governed-canvas.spec.mjs`;
- desktop/mobile rendering, semantic headings/list, no horizontal overflow,
  browser console/page errors, and success screenshots.

Admission under #526 requires exact-head hosted CI, self-hosted Browser Validation
and human visual review before merge.

## 9. Explicit non-goals

This baseline does **not** enable or prove:

- Canvas AI page generation;
- Figma-to-code;
- arbitrary HTML/CSS generation;
- automatic new-component generation;
- Context Control Center production integration;
- migration of existing Paragraph pages to Canvas;
- autonomous publication;
- a proprietary Agency page builder.

Those capabilities may only proceed after this Canvas baseline is proven and a
new bounded owner demonstrates a real upstream gap or capability.
