# Ticket 53 - Optimisation de navigation avec Quicklink

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/173

## Objectif

Evaluer et mettre en place un prechargement intelligent des liens internes pour ameliorer la vitesse percue de navigation entre pages publiques, sans complexite custom et sans regression frontend.

## Statut du module

Module evalue : `drupal/quicklink`.

Elements verifies le 2026-05-08 :

- page projet Drupal.org : https://www.drupal.org/project/quicklink
- version retenue : `3.0.0`
- statut : release stable couverte par la Drupal Security Team
- compatibilite declaree : `^10.2 || ^11`
- package installe par Composer : `drupal/quicklink:^3.0`
- fichier module : `web/modules/contrib/quicklink/quicklink.info.yml`

Conclusion : le module est pertinent pour Drupal 11. Il est feature-complete/maintenance fixes only, mais dispose d'une release stable recente et d'une compatibilite Drupal 11 explicite. Il n'a pas ete modifie.

## Installation appliquee

Commandes principales executees :

- `ddev composer require drupal/quicklink:^3.0`
- `ddev exec drush en quicklink -y`
- `ddev exec drush cr`
- `ddev exec drush cex -y`

Fichiers projet impactes :

- `composer.json`
- `composer.lock`
- `config/sync/core.extension.yml`
- `config/sync/quicklink.settings.yml`
- `web/libraries/quicklink/dist/quicklink.umd.js`

La librairie JavaScript Quicklink a ete placee localement dans `web/libraries/quicklink/dist/quicklink.umd.js`, emplacement supporte nativement par le module. Cela evite le chargement CDN `unpkg.com` sur les pages publiques.

SHA256 de la librairie locale :

`E3805E767CC8A5479025C63CAAD2F26AB16ED140824DB82658EBDAA1E8621504`

## Configuration retenue

Configuration exportee dans `config/sync/quicklink.settings.yml` :

- `no_load_when_authenticated: true`
- `no_load_when_session: true`
- `ignore_admin_paths: true`
- `ignore_ajax_links: true`
- `ignore_hashes: true`
- `ignore_file_ext: true`
- `allowed_domains: ''`
- `enable_debug_mode: false`
- `total_request_limit: 4`
- `concurrency_throttle_limit: 1`
- `viewport_delay: 500`
- `idle_wait_timeout: 2500`
- `url_patterns_to_ignore: "/user\n/admin\n/edit"`

Cette configuration garde Quicklink limite aux visiteurs anonymes sans session PHP active, avec un volume faible de prechargements et aucun domaine externe additionnel.

## Verification des exclusions

### Routes admin

Le HTML anonyme expose les parametres Quicklink suivants dans `drupalSettings` :

- `ignore_admin_paths: true`
- `url_patterns_to_ignore` contient `user/logout`, `/user`, `/admin`, `/edit` et `#`
- `admin_link_container_patterns` contient notamment `#toolbar-administration a`, `#drupal-off-canvas a` et les local tasks

Le module ne charge pas la librairie pour les utilisateurs authentifies avec la configuration retenue. Les liens admin ne doivent donc pas etre precharges.

### Liens externes

`allowed_domains` reste vide dans la configuration exportee et n'est pas expose dans `drupalSettings`. Quicklink garde donc son comportement same-origin.

La homepage `/fr` ne charge plus la librairie depuis `unpkg.com` :

- observe : `/libraries/quicklink/dist/quicklink.umd.js`
- non observe apres cache rebuild : `https://unpkg.com/quicklink@3.0.1/dist/quicklink.umd.js`

## Impact reseau mobile

Quicklink lui-meme evite les prechargements lorsque `Save-Data` est actif ou quand la connexion declaree est lente (`2g`). La configuration projet reduit aussi les risques :

- 4 prechargements maximum par page
- 1 requete concurrente maximum
- attente idle `2500 ms`
- delai viewport `500 ms`
- librairie locale de 5 335 octets, sans DNS/TLS externe vers un CDN

Conclusion : le comportement reste prudent pour mobile et ne devrait pas creer de prechargements agressifs.

## Verification pages publiques

Commandes HTTP executees avec `curl -I -L -s` via DDEV.

Resultats :

- `/` redirige vers `/fr`, puis 200 OK
- `/services` redirige vers `/fr/services`, puis 200 OK
- `/ia-drupal` redirige vers `/fr/ia-drupal`, puis 200 OK
- `/cas-clients` redirige vers `/fr/cas-clients`, puis 200 OK
- `/contact` redirige vers `/fr/contact`, puis 200 OK

Quicklink est expose sur les pages anonymes principales via `drupalSettings.quicklink` et les scripts locaux :

- `/libraries/quicklink/dist/quicklink.umd.js`
- `/modules/contrib/quicklink/js/quicklink_init.js`

## Verification switch langue

La homepage `/fr` contient :

- `hreflang="fr"` vers `/fr`
- `hreflang="en"` vers `/en`
- switch langue visible avec lien `/en`

Verification HTTP :

- `/en` repond 200 OK
- `Content-Language: en`

Le switch langue reste un lien interne same-origin ; il est compatible avec la configuration Quicklink.

## Lighthouse

Lighthouse n'a pas ete execute dans cette passe :

- aucun binaire `lighthouse` detecte cote Windows
- aucun binaire `lighthouse` detecte dans le conteneur DDEV

Pour garder le ticket simple et reproductible, aucune installation ponctuelle de Lighthouse ou dependance Node globale n'a ete ajoutee. Les scores de reference fournis dans le ticket restent le point de comparaison connu.

## Tests

Commande executee :

`ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests --exclude-group unstable_language_switcher`

Resultat :

- 3 tests executes
- 23 assertions
- statut : OK
- 16 deprecations contrib signalees par PHPUnit

Aucun test contrib global n'a ete lance.

## Decision

Quicklink est installe et configure, car :

- compatibilite Drupal 11 confirmee
- release stable couverte par la politique de securite Drupal
- comportement natif prudent
- configuration projet limitee aux pages publiques anonymes
- prechargements externes non autorises
- librairie JavaScript servie localement

Aucune modification des modules contrib n'a ete faite.
