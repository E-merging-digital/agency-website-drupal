# Governed editorial publication

Status: **PREPROD-FIRST / HUMAN-APPROVED PROD PROMOTION**
Owner hardening issue: #1014
Historical route owner: #576
Candidate capability: #872 / #959
Feature-image primitive: #584

## Durable policy

Agency ordinary editorial content is editor-owned in Drupal and remains outside
Content Sync / Governed Content. GitHub is the governed candidate, authority and
audit surface; Drupal becomes the public editorial source of truth only after an
exact approved promotion.

For every new public Article, the mandatory path is:

```text
owner-authored deterministic Article payload
-> candidate_id + candidate_revision + payload_sha256
-> PREPROD materialization through #959
-> exact feature image through #584 on PREPROD
-> real human review of the rendered FR/EN candidate
-> explicit Project Lead approval bound to content + image identities
-> fresh PROD dry-run on current main
-> same-artifact PROD promotion
-> post-promotion verification
```

```text
DIRECT_PROD_EDITORIAL_CREATION = FORBIDDEN_BY_DEFAULT
PREPROD_FIRST = MANDATORY
HUMAN_APPROVAL = MANDATORY
ARTICLE_IMAGE = MANDATORY
IMAGE_WAIVER = UNSUPPORTED_BY_NORMAL_ROUTE
```

A green PR, successful CI, an open issue, candidate creation, an available
workflow or approval of another candidate is never PROD publication authority.

## Control surface

On an open issue created by `E-merging-digital`, the owner may use:

```text
/agency-editorial inspect
/agency-editorial dry-run
/agency-editorial apply
```

`inspect` and `dry-run` remain read-only. `apply` is no longer a direct-create
authority. It is the final promotion command and fails closed before PROD SSH
unless the complete PREPROD-first authority below is present.

The workflow remains fixed to:

```text
bundle = article
source language = fr
required translation = en
text format = basic_html
category vocabulary = blog_categories
technical author = uid 1
aliases = Pathauto-owned
image field = field_feature_image
```

No caller-controlled bundle, entity type, field, text format, shell, Drush
command, server path, host, URL or Content Sync surface exists.

## Durable candidate identity

The latest owner-authored comment beginning exactly with:

```text
<!-- agency-editorial-payload:v1 -->
```

must contain the closed #576 Article JSON schema. The canonical bytes use sorted
JSON keys, compact separators, UTF-8 and exactly one trailing newline.

The promotion identity is:

```text
candidate_id       = agency-article-<issue_number>
candidate_revision = exact GitHub payload comment id
payload_sha256     = SHA-256 of canonical payload bytes
trusted_main       = exact current main SHA
```

Immediately before PROD secrets are configured, the workflow reloads live
`main` and all issue comments, then recomputes the latest owner payload identity.
Any newer payload comment, content hash drift or main drift refuses the request.

## PREPROD evidence required for apply

The exact issue must contain one bot-authored PREPROD Article apply receipt whose
fields match all of:

```text
target = PREPROD
candidate_id = exact candidate
candidate_revision = exact payload comment id
payload_sha256 = exact current payload
trusted_main = exact current main
node_id = exact PREPROD Article
prod_write = NONE
```

The Article must also have an exact repository-owned #584 feature-image profile:

```text
issue_number
bundle = article
article_payload_sha256 = exact current payload
field_name = field_feature_image
asset.path
asset.sha256
asset MIME/dimensions/size limits
ALT FR + EN
```

The profile is canonicalized and SHA-256 bound. Its bounded issue-specific image
generator must reproduce the exact asset hash. Arbitrary image URLs/downloads
are not accepted.

PREPROD must then contain both:

1. a bot-authored image apply PASS for the exact profile/asset/current main;
2. a later bot-authored image dry-run PASS with `IDEMPOTENT` for the same
   node/revision/profile/asset/current main.

## Explicit human approval

The normal route accepts exactly one owner-authored approval headed:

```text
## PROJECT LEAD — HUMAN APPROVAL / exact #<issue> candidate approved for PROD promotion
```

It must bind the exact current identities and PREPROD evidence:

```text
CANDIDATE_ID
CANDIDATE_REVISION
ARTICLE_PAYLOAD_SHA256
PREPROD_ARTICLE_APPLY
PREPROD_NODE_ID
PREPROD_ARTICLE_REVISION_AFTER_IMAGE
IMAGE_PROFILE_SHA256
IMAGE_ASSET_SHA256
PREPROD_IMAGE_APPLY
PREPROD_IMAGE_POST_APPLY_DRY_RUN
```

It must contain exact FR and EN `https://preprod.emergingdigital.be/...`
rendered URLs and explicitly assert:

```text
HUMAN_REVIEW = PASS
CONTENT = APPROVED
IMAGE = APPROVED
ALT_FR_EN = APPROVED
IMAGE_SOURCE_POLICY = APPROVED
RESPONSIVE_RENDER = APPROVED
LISTING_DETAIL_RENDER = APPROVED
EXACT_CANDIDATE_PROMOTION_TO_PROD = AUTHORIZED
CONTENT_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL
IMAGE_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL
```

The approval comment must be later than the candidate Article and image evidence.
A fresh bot-authored PROD dry-run for the exact payload/current main must be
later than the human approval. This makes stale approval and pre-approval PROD
dry-runs unusable.

No general image waiver or direct-PROD exception flag exists. A legal/emergency
exception requires a separate explicit, bounded, auditable Project Lead
capability and cannot become a reusable precedent.

## Same-artifact PROD promotion

After the authority gate passes, the production runner takes a database backup
before any Drupal mutation. The runner does not call the historical direct
published-Article path for `apply`.

Instead it composes the already proven #576 and #584 primitives:

```text
1. validate exact payload/profile/asset again
2. create/reuse the mapped Article as UNPUBLISHED
3. attach/reconcile the exact approved feature image + FR/EN ALT
4. compare mapped FR/EN title/description/body/category with approved payload
5. compare image bytes/ALT with approved image profile
6. publish FR + EN in a new revision only after all comparisons pass
7. finalize Pathauto aliases
8. rebuild caches
```

If image materialization or any later check fails after initial creation, the new
Article remains unpublished. A public Article cannot escape from the normal
route without the exact visual.

Exact replay is idempotent. Candidate content drift, image bytes drift, ALT
drift, missing translation, mismatched mapping or divergent publication states
fail closed.

## Visual completeness gate

For Article promotion, the human review must cover:

```text
IMAGE_PRESENT = YES
IMAGE_RELEVANCE_REVIEW = PASS
IMAGE_SOURCE_POLICY = PASS
ALT_FR_EN = PASS
RESPONSIVE_RENDER_REVIEW = PASS
LISTING_AND_DETAIL_REVIEW = PASS
```

The machine gate additionally proves the image is the exact repository-owned
asset attached to FR/EN before publication. Human visual judgment is never
inferred from an automated test.

## Evidence and privacy

Runs upload metadata-only evidence. Result comments may expose:

- status/verdict;
- trusted main;
- payload hash;
- approval comment id;
- visual-completeness verdict;
- node/revision IDs;
- aliases;
- run/artifact identifiers.

They must not expose secrets, database data, settings.php, payload body, private
PREPROD credentials or arbitrary runtime output.

## Historical note

#576 originally allowed direct Article creation in PROD after only a same-hash,
same-main bot dry-run. That behavior is historical and superseded by #1014.
#403 proved why the distinction matters: a technical route remained capable of
publishing even though the Project Lead authority for the editorial pass said
publication was not yet authorized.

#872/#959 and the #958 real candidate established the correct architecture:
PREPROD materialization, exact image, real human review, immutable approval and
only then PROD promotion. #1014 makes that architecture an enforcement boundary
instead of documentation-only guidance.

## Explicit non-capabilities

The normal route cannot:

- bypass PREPROD;
- infer human approval;
- publish an Article without its exact governed feature image;
- use an image waiver;
- execute arbitrary shell or Drush input;
- run `drush cim` or `updb`;
- invoke Content Sync / Governed Content;
- deploy code;
- create taxonomy terms;
- mutate another bundle;
- choose another text format;
- accept arbitrary aliases or remote media URLs;
- invoke an AI provider;
- turn an emergency exception into a permanent switch.
