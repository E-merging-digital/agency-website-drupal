# Production image derivative diagnostic

Status: **INCIDENT-BOUND / DIAGNOSTIC**  
Owner: #596  
Blocked editorial proof: #401

## Purpose

The public production Browser Validation for Article #401 proved that the
`large` image-style derivative returns HTTP 500 on both desktop and mobile:

```text
/sites/default/files/styles/large/public/articles/
issue-401-redesign-checklist-f925e3b41c32.png.avif?itok=bTtxA4Oo
```

The repository-owned `large` style uses Drupal core `image_convert_avif` with
WebP as fallback. The diagnostic determines why the production runtime chooses
an AVIF derivative but cannot serve it.

It must run before any change to the image style, toolkit configuration or
production PHP extensions.

## Control surface

The only accepted command is:

```text
/agency-production-image diagnose
```

It is accepted only on OPEN owner-created issue #596 from actor and comment
author `E-merging-digital`, and only when the workflow revision is current live
`main`.

No host, path, URL, toolkit, command or format is accepted from the comment.

## Fixed observations

Through the existing production SSH channel, the route records:

- current release and Git SHA;
- maintenance mode and active deployment count;
- configured Drupal image toolkit;
- PHP version and memory limit;
- GD extension presence;
- `imageavif()` and `imagewebp()` availability;
- GD AVIF and WebP support flags;
- source file existence, size, dimensions and MIME type;
- derivative existence, size, dimensions and MIME type;
- the HTTP status/content type/body hash for the exact failing derivative;
- bounded Drupal error log output;
- bounded Nginx and PHP-FPM log tails when readable by the deployment user.

The fixed HTTP GET exercises the same public derivative endpoint already
exercised by Browser Validation. Drupal may attempt its normal derivative-cache
generation as a consequence of that GET; the diagnostic itself contains no
explicit image generation, flush, deletion or file-write command.

## Forbidden actions

The workflow contains no:

- `state:set` or config mutation;
- `drush cr`, `cim`, `updb` or image-style flush;
- service restart/reload;
- deployment;
- Git mutation;
- Composer mutation;
- arbitrary shell/URL/path supplied by the operator.

## Verdicts

The machine-readable result distinguishes:

- `SOURCE_MISSING`;
- `AVIF_CAPABILITY_MISMATCH`;
- `DERIVATIVE_GENERATION_FAILED`;
- `DERIVATIVE_SERVING_FAILED`;
- `DERIVATIVE_HEALTHY`;
- `SSH_DIAGNOSTIC_FAILED`;
- `UNKNOWN`.

Raw evidence stays in the 30-day workflow artifact; the issue comment publishes
only bounded fields required for the next decision.

## Follow-up rule

Do not disable AVIF or switch the image style merely to make the browser test
pass. A configuration or infrastructure correction must follow the observed
capability/log evidence. After correction, the exact derivative must return an
image response below HTTP 400, then #401 Browser Validation is rerun once with
all image, console, network, canonical, hreflang, sitemap and visual assertions
unchanged.
