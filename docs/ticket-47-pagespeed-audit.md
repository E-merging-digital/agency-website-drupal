# Ticket 47 - Audit PageSpeed et optimisations Drupal core

Date d'audit: 2026-05-07

## Scores PageSpeed

L'API PageSpeed Insights a ete appelee pour `https://emergingdigital.be/`, mais Google a retourne `429 Too Many Requests`.
Les scores Lighthouse mobile/desktop avant et apres ne sont donc pas exploitables depuis cet environnement.

Tentatives effectuees:

- Mobile: `429 Too Many Requests`.
- Desktop: `429 Too Many Requests`.

Mesures locales observables:

- Avant: `/` et `/llms.txt` retournaient `Cache-Control: must-revalidate, no-cache, private`.
- Apres: `/` retourne `Cache-Control: max-age=3600, public`.
- Apres: `/llms.txt` retourne `Cache-Control: max-age=3600, public`.
- Apres: `/fr` reste `UNCACHEABLE (response policy)` a cause du rendu de formulaire sur la page.

## Constats

- `page_cache` et `dynamic_page_cache` sont actives.
- `system.performance` avait deja `css.preprocess: true` et `js.preprocess: true`.
- `system.performance cache.page.max_age` etait a `0`, ce qui produit des headers `Cache-Control: must-revalidate, no-cache, private`.
- En DDEV local, `web/sites/default/settings.local.php` n'est pas versionne et force le mode dev: agregation CSS/JS desactivee, bins `render`, `dynamic_page_cache` et `page` en `cache.backend.null`.
- La home locale `/fr` expose uniquement une balise image directe, le logo SVG, avec `width`, `height`, `loading="eager"`, `decoding="async"` et `fetchpriority="high"`.
- Le module core `responsive_image` n'est pas active. Aucune image raster de contenu n'est rendue sur la home dans l'audit local, donc aucune correction responsive image n'a ete appliquee.
- Le favicon SVG reference pesait environ 2,5 Mo.
- Le visuel CSS `home-hero-ia-drupal.svg` pesait environ 2,3 Mo car il embarquait une image PNG en base64.
- Le visuel hero etait reference dans le CSS global, donc potentiellement telecharge sur mobile meme lorsque l'espace visuel disponible est reduit.
- La home `/fr` rend un formulaire Webform via le theme custom, ce qui maintient la reponse en `UNCACHEABLE (response policy)`.

## Changements effectues

- Passage du favicon du theme vers le PNG existant, beaucoup plus leger.
- Suppression du favicon SVG lourd, qui n'est plus reference.
- Remplacement du SVG hero de la home par une illustration vectorielle legere au meme chemin.
- Chargement du visuel hero uniquement a partir de `48rem` pour eviter ce telechargement sur mobile.
- Passage du `cache.page.max_age` Drupal core a 3600 secondes.
- Export de configuration apres changement de `system.performance` et `system.theme.global`.

## Resultats apres correction

- `system.performance cache.page.max_age`: `3600`.
- Favicon rendu: `/themes/custom/emerging_digital/images/branding/emerging-digital-favicon.png`.
- Favicon PNG: environ 11 Ko.
- Hero SVG home: environ 1,6 Ko, contre environ 2,3 Mo avant.
- Hero CSS: image chargee seulement en breakpoint desktop/tablette large.
- `/llms.txt`: `Cache-Control: max-age=3600, public`.
- `/`: `Cache-Control: max-age=3600, public`.
- `/fr`: encore `Cache-Control: must-revalidate, no-cache, private` et `UNCACHEABLE (response policy)`.

## Commandes executees

- `git switch -c feature/ticket-47-pagespeed-audit`
- `ddev status`
- `ddev exec drush pml --status=enabled --type=module`
- `ddev exec drush config:get system.performance`
- `ddev exec curl -I http://agency-website-drupal.ddev.site/`
- `ddev exec curl -I http://agency-website-drupal.ddev.site/fr`
- `ddev exec curl -I http://agency-website-drupal.ddev.site/llms.txt`
- `ddev exec drush config:set system.performance cache.page.max_age 3600 -y`
- `ddev exec drush config:set system.theme.global favicon.path themes/custom/emerging_digital/images/branding/emerging-digital-favicon.png -y`
- `ddev exec drush config:set system.theme.global favicon.mimetype image/png -y`
- `ddev exec drush cr`
- `ddev exec drush cex -y`
- `ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests`

## Tests

Suite ciblee `agency_project_tests`: OK.

- Tests: 5.
- Assertions: 41.
- Deprecations: 18.
- Aucun test contrib global lance.
- `AiTranslationWorkflowTest` non relance.

## Recommandations suivantes

- Relancer PageSpeed Insights hors limite 429, idealement depuis CI ou avec un compte/API key dedie.
- Verifier que la production ne charge jamais `settings.local.php` et que les caches Drupal ne sont pas remplaces par `cache.backend.null`.
- Ouvrir un ticket separe pour traiter le formulaire Webform de la home: soit le retirer de la home, soit le charger via un parcours/contact dedie, soit verifier une strategie de placeholder/lazy rendering compatible cache.
- Creer un ticket separe si des images raster editoriales sont ajoutees: activer/configurer `responsive_image` et des image styles adaptes.
- Envisager un ticket dedie pour un cache applicatif ou reverse proxy seulement apres validation des optimisations core.
