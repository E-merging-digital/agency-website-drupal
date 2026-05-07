# Ticket 48 - Validation optimisation des images

Date de validation: 2026-05-07

## Objectif

Valider le perimetre images apres l'integration du ticket 47, sans dupliquer les changements deja merges.

## Constats

- La branche `feature/ticket-48-image-optimization-v2` est basee sur `main` a jour, incluant le merge du ticket 47.
- La configuration active Drupal est alignee avec `config/sync`.
- Le cache page core est configure a `3600` secondes dans `system.performance`.
- Le favicon global pointe vers `themes/custom/emerging_digital/images/branding/emerging-digital-favicon.png`.
- Le theme declare aussi le favicon PNG dans `emerging_digital.info.yml`.
- Le fichier `emerging-digital-favicon.svg` lourd n'est plus present dans le theme.
- Le hero `home-hero-ia-drupal.svg` est un SVG leger servi a environ 1,6 Ko.
- Le CSS du hero reference `home-hero-ia-drupal.svg` uniquement dans le breakpoint `min-width: 48rem`.
- Aucun asset statique du theme ne depasse 500 Ko.

## Assets statiques du theme

Verification DDEV:

- `find web/themes/custom/emerging_digital/images -type f -size +500k`: aucun resultat.

Plus gros assets observes:

- `emerging-digital-mark.svg`: environ 20 Ko.
- `emerging-digital-logo.svg`: environ 18 Ko.
- `emerging-digital-favicon.png`: environ 11 Ko.
- `home-hero-ia-drupal.svg`: environ 1,6 Ko.

Aucune optimisation technique supplementaire n'a ete identifiee sur les assets statiques.

## Styles d'image Drupal

Styles exportes dans `config/sync`:

- `thumbnail`: scale 100 x 100, conversion WebP.
- `medium`: scale 220 x 220, conversion WebP.
- `large`: scale 480 x 480, conversion WebP.
- `wide`: scale 1090 px de large, conversion WebP.

La conversion WebP est donc deja couverte par Drupal core via les styles d'image existants. Aucun module contrib WebP/Image Optimize n'est necessaire a ce stade.

## Responsive image

Le module core `image` est actif, ainsi que `breakpoint`.
Le module `responsive_image` n'est pas actif et aucun fichier `responsive_image.styles.*.yml` n'est exporte.

Conclusion: `responsive_image` n'est pas necessaire maintenant, car les pages auditees rendent principalement des assets statiques de theme et le seul `<img>` critique observe sur la home est le logo SVG. L'activation de `responsive_image` sera utile uniquement si des images editoriales raster sont affichees en contenu avec plusieurs tailles attendues selon viewport.

## Lazy loading et images critiques

Rendu HTML observe sur la home:

- Favicon: `/themes/custom/emerging_digital/images/branding/emerging-digital-favicon.png`.
- Logo: `<img>` avec `width`, `height`, `loading="eager"`, `decoding="async"` et `fetchpriority="high"`.

Le hook `emerging_digital_preprocess_image()` garde le logo critique en eager/high priority et applique `loading="lazy"` uniquement aux autres images qui n'ont pas deja d'attribut `loading`.

Conclusion: aucune image LCP critique observee n'est ralentie par du lazy loading.

## Commandes executees

- `git branch --show-current`
- `git status --short`
- `git log --oneline --decorate -5`
- `ddev exec drush cst`
- `ddev exec find web/themes/custom/emerging_digital/images -type f -size +500k`
- `ddev exec drush pml --status=enabled --type=module`
- `ddev exec curl -L -s http://agency-website-drupal.ddev.site/`
- `ddev exec curl -I -L -s http://agency-website-drupal.ddev.site/themes/custom/emerging_digital/images/home/home-hero-ia-drupal.svg`
- `ddev exec curl -I -L -s http://agency-website-drupal.ddev.site/themes/custom/emerging_digital/images/branding/emerging-digital-favicon.png`
- `ddev exec curl -I -L -s http://agency-website-drupal.ddev.site/themes/custom/emerging_digital/images/branding/emerging-digital-mark.svg`

## Decision

Aucune modification technique supplementaire n'est necessaire pour le ticket 48.
Le ticket est traite comme validation complementaire du perimetre images apres le ticket 47.

## Recommandations

- Ne pas installer de module contrib d'optimisation image tant que le besoin n'est pas demontre par des images editoriales lourdes ou un pipeline media plus complexe.
- Activer `responsive_image` dans un ticket separe uniquement quand des images de contenu raster doivent fournir des variantes par breakpoint.
- Relancer PageSpeed Insights apres deploiement des changements du ticket 47 pour confirmer l'impact mobile en conditions reelles.
