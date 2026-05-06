# SEO classique: Metatag + Schema.org

## Périmètre

Cette configuration couvre uniquement le SEO classique (balises meta, Open Graph, Twitter/X Cards et Schema.org JSON-LD via Metatag).

## Configurations exportées

- Defaults globaux (`front`, `node`) avec tokens Drupal/Metatag.
- Defaults par type de contenu (`page`, `article`, `service`, `case_client`, `ai_feature`).
- Open Graph et Twitter/X Cards activés sur les defaults principaux.
- Schema.org configuré selon le type:
  - Front: `WebSite` + `Organization`
  - Contenu générique: `WebPage`
  - Article: `Article`

## Commandes utiles

- Export config (Drush):
  - `drush config:export -y`
  - Équivalent DDEV/PowerShell: `ddev drush config:export -y`

- Exécuter les tests custom (hors groupe instable IA):
  - `SIMPLETEST_BASE_URL=http://localhost SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests --exclude-group unstable_ia`
  - Équivalent DDEV/PowerShell: `ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests --exclude-group unstable_ia`
