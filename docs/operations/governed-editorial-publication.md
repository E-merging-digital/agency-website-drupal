# Governed editorial publication

Status: **TRUSTED PRODUCTION MUTATION ROUTE**  
Owner issue: #576  
Initial consumer: #401

## Purpose

Agency ordinary editorial content, including Blog Articles, is editor-owned in
Drupal and remains outside Content Sync / Governed Content.

This route exists only to remove mechanical human data entry when an authorized
operator or agent already has a reviewed Article payload. It does not move the
editorial source of truth into Git. GitHub is the governed request and audit
surface; after a successful apply, Drupal is the editorial source of truth.

The initial route is deliberately limited to the `article` bundle.

## Control surface

On an OPEN repository issue created by `E-merging-digital`, the owner can post
exactly one of:

```text
/agency-editorial inspect
/agency-editorial dry-run
/agency-editorial apply
```

The workflow refuses every other comment. It also verifies that:

- `GITHUB_ACTOR` is exactly `E-merging-digital`;
- the comment author is the actor;
- the target is an OPEN issue, not a pull request;
- the issue author is `E-merging-digital`;
- the workflow revision is the current live `main` SHA.

The trigger contains no shell, Drush command, server path or URL input.

## Payload v1

`inspect` needs no payload.

`dry-run` and `apply` use the latest owner-authored issue comment beginning
exactly with:

```text
<!-- agency-editorial-payload:v1 -->
```

The remainder of that comment must be one JSON object with exactly:

```json
{
  "schema_version": 1,
  "issue_number": 401,
  "bundle": "article",
  "published": true,
  "category": {
    "tid": 123,
    "name": "Existing category name"
  },
  "fr": {
    "title": "...",
    "short_description": "...",
    "body_html": "..."
  },
  "en": {
    "title": "...",
    "short_description": "...",
    "body_html": "..."
  }
}
```

Unknown fields are refused. The workflow canonicalizes the JSON with stable key
ordering and hashes the canonical UTF-8 bytes with SHA-256. The canonical JSON
is transferred as a file; payload values are never interpolated into a command.

## Drupal contract

V1 is fixed to:

```text
bundle                 = article
source language        = fr
required translation   = en
text format            = basic_html
category vocabulary    = blog_categories
technical author       = uid 1
aliases                 = Pathauto-owned
```

The runtime script checks that the Article bundle, required fields, languages,
`basic_html`, active uid 1 and the selected existing Blog category are present
before any write.

No user, role, permission, bundle, text format, alias or taxonomy creation is an
input.

### HTML boundary

The body accepts only a conservative subset of the existing `basic_html`
contract:

```text
p h2 h3 h4 ul ol li strong em a blockquote dl dt dd code br
```

Only `href` is accepted on `<a>`. Links must be same-site relative paths or an
`https://emergingdigital.be/` URL. Scripts, styles, iframes, images, arbitrary
attributes, unsafe schemes and tables are refused.

This is intentionally narrower than the editor format. For #401 the audit
"table" is therefore represented as structured paragraphs/list/definition list
instead of widening the text format.

`title`, category name and short description are plain text only.

## Inspect

`/agency-editorial inspect` performs no mutation. It returns a machine-readable
`result.json` containing:

- Article/runtime readiness;
- presence of FR and EN;
- `basic_html` availability;
- active technical author uid 1;
- existing `blog_categories` TIDs and FR/EN labels;
- existing technical issue mapping, if any.

Use inspect before preparing the final category/TID pair for an Article.

## Dry-run

`/agency-editorial dry-run`:

1. loads and hashes the latest valid payload;
2. transfers only the canonical JSON and trusted PHP helper over the existing
   production SSH channel;
3. validates the same schema again inside Drupal;
4. checks runtime prerequisites and category TID/name consistency;
5. checks idempotence and exact-title collision rules;
6. performs no `save()`;
7. publishes `DRY_RUN PASS` evidence with payload hash and trusted `main` SHA.

The dry-run verdict is `READY` or `IDEMPOTENT`.

## Apply authorization

`/agency-editorial apply` is refused unless the same issue already contains a
`github-actions[bot]` dry-run PASS for:

- the exact current payload SHA-256; and
- the exact current live `main` SHA.

A change to the payload or trusted `main` therefore requires a new dry-run.

The production runner also performs a fresh Drupal dry-run immediately before
any backup or mutation.

## Apply

For a `READY` payload, the runner creates:

```text
/var/www/agency/shared/backups/editorial-issue-<N>-<UTC timestamp>.sql.gz
```

and verifies that the backup is non-empty before the first content write.

The Drupal helper then:

- switches the current account to active uid 1;
- creates one FR Article through Entity API;
- sets `field_short_description` and `body` with `basic_html`;
- references only the validated existing Blog taxonomy term;
- adds the EN translation through Entity API;
- creates a revision with issue + payload hash in the technical revision log;
- saves the Article;
- writes the technical idempotence mapping;
- reloads and verifies the Article and EN translation;
- reports node ID, UUID, revision ID and FR/EN aliases;
- returns control to the runner, which rebuilds Drupal caches.

No direct SQL content mutation is used.

## Idempotence and collision rules

The technical state key is:

```text
agency_editorial.issue.<issue_number>
```

It stores only:

```text
node_id
payload_sha256
```

This mapping is audit/idempotence metadata, not editorial content.

Rules:

- no mapping + no exact FR Article title => creation may proceed;
- coherent mapping + same hash + same Article => `IDEMPOTENT`;
- different hash for an existing mapping => fail closed;
- missing/invalid mapped node => fail closed;
- exact FR title already present without mapping => fail closed;
- multiple/ambiguous content states are never auto-repaired.

V1 never updates an existing Article.

## Image boundary

`field_feature_image` is optional at the Drupal field level, so the initial
route can publish a safe textual Article. #401 is not complete until its required
feature image and translated ALT are present and validated.

V1 does not accept a remote image URL and does not download arbitrary media.
Media support requires a separately reviewed bounded increment if no existing
safe primitive is available.

## Evidence

Every run uploads:

```text
artifacts/editorial-publication/result.json
artifacts/editorial-publication/preapply.json   # apply only
```

`apply` evidence also records the server-side backup path. Result comments expose
only bounded metadata: status, verdict, node/revision IDs, aliases, trusted main,
payload hash, run ID and artifact name.

No secret, settings.php content, database content or payload body is copied into
the result comment.

## Explicit non-capabilities

This route cannot:

- execute arbitrary shell or Drush input;
- run `drush cim` or `updb`;
- invoke Governed Content / Content Sync;
- deploy code;
- change users or permissions;
- create taxonomy terms;
- edit menus or homepage;
- mutate another bundle;
- select another text format;
- set arbitrary aliases;
- invoke an AI provider;
- update an existing Article in v1.

## #401 sequence

After #576 is merged and CI is green:

```text
/agency-editorial inspect
-> choose an existing category returned by inspect
-> post the final #401 payload
-> /agency-editorial dry-run
-> verify hash/verdict
-> /agency-editorial apply
-> verify node/revision/FR+EN aliases
-> complete feature image + ALT if still required
-> Browser Validation desktop/mobile
-> verify canonical/hreflang/sitemap/console/network
-> close #401 only when its complete DoD is satisfied
```
