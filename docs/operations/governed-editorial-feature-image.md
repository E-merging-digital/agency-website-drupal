# Governed editorial feature image

Status: **TRUSTED BOUNDED ARTICLE FEATURE-IMAGE ROUTE**
Owner issue: #584  
Initial consumer: #401  
PREPROD consumer: #958
Parent Article route: #576

## Purpose

The editor-owned Article route deliberately keeps media outside its v1 payload.
This increment supplies the missing mechanical capability for an already mapped
Article to receive one reviewed feature-image asset without turning Agency into a
generic uploader or remote downloader.

Drupal remains the editorial source of truth for the selected environment after
apply. The repository owns the reviewed transfer profile plus the deterministic
source code needed to reproduce the exact image bytes and prove their provenance.

## Control surface

On an OPEN owner-created issue, `E-merging-digital` may post exactly one of:

```text
/agency-editorial-image inspect
/agency-editorial-image dry-run
/agency-editorial-image apply
```

The trusted workflow executes only from the live `main` revision and checks the
actor, comment author, issue owner and closed issue-to-target binding before any
target connection.

There is no workflow-dispatch input, URL input, server path input, MIME input,
filename input, ALT input, shell input or Drush input.

## Repository-owned profile

Profiles are versioned in:

```text
scripts/runner/editorial-feature-image-profiles.json
```

The registry contains only explicitly reviewed Article profiles. The initial
#401 profile targets PROD; #958 is a closed PREPROD-only extension. Each profile
fixes:

- issue number;
- bundle `article`;
- the exact original Article payload SHA-256 already stored in
  `agency_editorial.issue.<issue_number>`;
- field `field_feature_image`;
- generated asset path;
- final Drupal filename;
- exact generated asset SHA-256;
- MIME `image/png`;
- exact dimensions;
- maximum byte size;
- FR ALT;
- EN ALT.

The workflow resolves the profile from trusted `main`, generates the approved
asset from repository-owned deterministic source code, verifies its GD
decodability and exact hash, then computes the canonical profile SHA-256.
`apply` requires a prior bot-authored image dry-run PASS for the same canonical
profile hash, asset hash and live `main` SHA.

Adding another Article is not a runtime input: it requires a reviewed repository
profile and generator change.

## Asset boundary

V1 accepts only the exact PNG generated from the approved repository source for
the closed profile. The deterministic sources currently are:

```text
scripts/runner/generate-editorial-feature-image-401.py
scripts/runner/generate-editorial-feature-image-958.py
```

The generated PNG itself is deliberately **not committed as opaque binary
bytes**. The trusted workflow generates it in the checked-out workspace, checks
its exact SHA-256 and proves `imagecreatefrompng()` can decode it at the expected
dimensions before the normal transport route may copy it to the selected bounded
target.

The generators are deterministic and use Python standard-library primitives
only. They do not depend on a font, external rasterizer, network resource or
uploaded attachment. The route does not:

- fetch HTTP/HTTPS URLs;
- consume issue attachments;
- accept base64 supplied in comments;
- accept arbitrary repository paths;
- scan directories;
- generate an image on the Drupal target;
- create Media entities.

The assets are language-neutral, sober illustrations based on Agency
design-system colors. They have no embedded marketing claim and use translated
Drupal ALT text instead of visible language-specific copy.

## Drupal contract

The helper reuses the existing technical Article mapping:

```text
agency_editorial.issue.<issue_number>
```

Before mutation it verifies:

- mapping exists and has exactly the expected original Article payload hash;
- mapped entity is an `article` node;
- EN translation exists;
- `field_feature_image` exists;
- asset bytes match SHA-256, PNG MIME, dimensions and maximum size;
- technical author uid 1 is active.

The trusted workflow additionally requires the generated PNG to be GD-decodable
before it reaches this helper.

The final file is stored below:

```text
public://articles/
```

using Drupal File API. The same FID is referenced by FR and EN. Because Drupal's
field configuration synchronizes the file property but not ALT, the route stores
distinct mandatory ALT values on the two translations.

## Target binding

The runtime target is not caller-controlled. The repository currently admits
exactly:

```text
issue #401 -> PROD -> /var/www/agency/current
issue #958 -> PREPROD -> /var/www/agency-preprod/current
```

For #958, the existing #959 PREPROD connection primitives are reused:

- `PREPROD_SSH_PRIVATE_KEY`;
- `PREPROD_SERVER_HOST`;
- fixed Unix account `agency-preprod`;
- pinned PREPROD host trust;
- PREPROD runtime validation before and after the image route.

The #958 path does not connect to PROD and records `target=PREPROD` and
`prod_write=NONE` in its bounded result evidence.

## Dry-run classification

`dry-run` is read-only and returns one of:

```text
READY
REPAIR_REQUIRED
IDEMPOTENT
```

- `READY`: no feature image is attached yet;
- `REPAIR_REQUIRED`: the exact governed asset is already attached but one or both
  translated ALTs do not match the reviewed profile;
- `IDEMPOTENT`: exact asset and both ALTs already match.

If the Article already references another asset, FR/EN FIDs diverge, the File
entity is missing, hashes differ, or the Article mapping is inconsistent, the
route fails closed instead of replacing or guessing.

## Apply

`apply` first runs a fresh dry-run on the selected target. For `READY` or
`REPAIR_REQUIRED`, the runner creates and verifies a SQL backup below the fixed
target-specific shared backup directory:

```text
PROD:    /var/www/agency/shared/backups/editorial-image-issue-<N>-<UTC>.sql.gz
PREPROD: /var/www/agency-preprod/shared/backups/editorial-image-issue-<N>-<UTC>.sql.gz
```

The helper then:

1. materializes or reuses the deterministic file under `public://articles/`;
2. marks the file permanent and assigns technical owner uid 1;
3. sets only `field_feature_image` on FR and EN;
4. uses the same FID and distinct reviewed ALT values;
5. creates one explicit Article revision with issue + asset hash in the revision
   log;
6. reloads the mapped Article and requires the resulting state to classify as
   `IDEMPOTENT`.

A second apply against the converged state performs no write and creates no new
revision.

No cache rebuild is forced by this route: the node revision save performs normal
Drupal cache invalidation, avoiding an unrelated site-wide mutation after the
bounded content write.

## #596 source-corruption repair

The first #401 binary committed under #584 was not the originally reviewed byte
sequence. The original documentation/profile expected SHA-256
`a61c36785d2395e30067d747b62d8153a3eb21d77508f150d92807a8ab85e9a8`,
but commit `251d295ace6876d003f43dcdb371bdc03b2cc5e3` introduced another PNG and
the profile was later changed to its SHA
`f925e3b41c325e9e863d1936d41b18cea5d0b9c064fac7ba6f551741f863fad4`
instead of failing the provenance gate.

Production diagnostics under #596 proved that this committed PNG has an invalid
IDAT CRC, invalid terminal structure and an invalid/truncated compressed image
stream. Both production GD and GitHub-hosted PHP 8.4/GD refuse to decode it. The
HTTP 500 on the AVIF derivative therefore occurs **before AVIF/WebP encoding**.

The durable correction has two parts:

1. deterministic generation plus PNG-structure/GD tests prevent an unreadable
   source from satisfying the profile again;
2. the one-shot trusted route
   `/agency-production-image repair-401-source` on issue #596 permits only the
   exact transition from the legacy URI+SHA to the replacement URI+SHA.

The one-shot repair creates a SQL backup before mutation, creates/reuses the exact
new File entity, changes only `field_feature_image` on FR/EN, creates one Article
revision and then requires the replacement state to be exact. It does **not**
delete the legacy File entity. Any other current URI, hash or ALT state fails
closed.

## Evidence

Every normal image-route run uploads:

```text
artifacts/editorial-feature-image/result.json
artifacts/editorial-feature-image/preapply.json   # apply only
```

The #596 one-shot repair uploads:

```text
artifacts/production-image-source-repair/preapply.json
artifacts/production-image-source-repair/result.json
```

Result evidence includes only bounded metadata: profile/asset hashes, trusted
main, issue/run IDs, target, verdict, node/revision IDs, FID and public
stream-wrapper URI. No SSH secret, file bytes or database content is copied into
comments.

## #401 profile

Deterministic source:

```text
scripts/runner/generate-editorial-feature-image-401.py
```

Generated asset:

```text
assets/editorial/issue-401-redesign-checklist.png
final Drupal filename: issue-401-redesign-checklist-70bf17abe69d.png
SHA-256 70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898
1200 x 630
image/png
```

ALT FR:

```text
Checklist de préparation avant la refonte d’un site web
```

ALT EN:

```text
Website redesign preparation checklist
```

## #958 PREPROD profile

The #958 profile is intentionally PREPROD-only and is bound to the already
materialized Editorial Candidate Article payload SHA-256:

```text
2e92228480ee6ae7410c028eab2b88c7d7db1534668477f6eafbc236668cb700
```

Deterministic source:

```text
scripts/runner/generate-editorial-feature-image-958.py
```

Generated asset:

```text
assets/editorial/issue-958-drupal-upgrade-roadmap.png
final Drupal filename: issue-958-drupal-upgrade-roadmap-3a705624b08a.png
SHA-256 3a705624b08ac2c21a10db0e8573841ff8c4cecf087409cd8782953e3684c6f4
1200 x 630
image/png
```

ALT FR:

```text
Préparation d’une mise à niveau Drupal 10 vers Drupal 11
```

ALT EN:

```text
Planning a Drupal 10 to Drupal 11 upgrade
```

The intended illustration is a clean 10 -> 11 modernization roadmap with a
controlled checklist/validation metaphor. It contains no remote image, fake
Drupal UI, humanoid robot, stock-photo scene or visible language-specific claim.

Normal converged flow for either closed profile remains:

```text
/agency-editorial-image inspect
-> /agency-editorial-image dry-run
-> verify target + profile + asset hashes and READY/REPAIR_REQUIRED/IDEMPOTENT
-> /agency-editorial-image apply when required
-> verify FID + FR/EN ALT + new revision
-> second dry-run must be IDEMPOTENT
-> render/browser validation on the selected environment
```

For #958, this flow stops after PREPROD render validation and human review;
PROD publication remains a separate Project Lead decision.
