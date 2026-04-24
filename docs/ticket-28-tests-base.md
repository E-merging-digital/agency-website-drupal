# Rationalisation des tests projet (pré-CI)

## Décision d’architecture

- `homepage_smoke_test` testait uniquement le rendu de `<front>` (status 200 + absence d’erreur runtime).
- Ce besoin reste pertinent, mais il est désormais déplacé dans un module de tests transversal plus explicite : `agency_project_tests`.
- Les tests transversaux (homepage, contact) vivent dans `agency_project_tests`.
- Les tests strictement métier IA restent dans `agency_ai_translation`.

## Structure cible

- `web/modules/custom/agency_project_tests`
  - `tests/src/Functional/HomepageRenderTest.php`
  - `tests/src/Functional/ContactFormTest.php`
- `web/modules/custom/agency_ai_translation`
  - `tests/src/Functional/AiTranslationWorkflowTest.php` (`@group unstable_ia`, exclu temporairement)

## Pré-requis local (PowerShell, une ligne)

```powershell
ddev exec mkdir -p web/sites/simpletest/browser_output; ddev exec chmod -R 777 web/sites/simpletest
```

Si vous voyez encore `HTML output directory ... is not a writable directory`, forcez le dossier :

```powershell
ddev exec env BROWSERTEST_OUTPUT_DIRECTORY=web/sites/simpletest/browser_output SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests/src/Functional/HomepageRenderTest.php
```

## Commandes de tests transversaux (PowerShell, une ligne)

Homepage smoke :

```powershell
ddev exec env BROWSERTEST_OUTPUT_DIRECTORY=web/sites/simpletest/browser_output SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests/src/Functional/HomepageRenderTest.php
```

Via script Composer (recommandé) :

```powershell
ddev composer test:homepage-smoke
```

Contact fonctionnel :

```powershell
ddev exec env BROWSERTEST_OUTPUT_DIRECTORY=web/sites/simpletest/browser_output SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests/src/Functional/ContactFormTest.php
```

Via script Composer :

```powershell
ddev composer test:contact
```

Tous les tests transversaux du module :

```powershell
ddev exec env BROWSERTEST_OUTPUT_DIRECTORY=web/sites/simpletest/browser_output SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests/src/Functional
```

Via script Composer :

```powershell
ddev composer test:project-functional
```

## Commandes agency_ai_translation (PowerShell, une ligne)

Exécuter les tests du module **en excluant temporairement** le workflow IA complet instable :

```powershell
ddev exec env BROWSERTEST_OUTPUT_DIRECTORY=web/sites/simpletest/browser_output SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist --exclude-group unstable_ia web/modules/custom/agency_ai_translation/tests/src/Functional
```

Via script Composer :

```powershell
ddev composer test:ai-translation:stable
```

Exécuter explicitement le test instable (debug uniquement, hors CI pour le moment) :

```powershell
ddev exec env BROWSERTEST_OUTPUT_DIRECTORY=web/sites/simpletest/browser_output SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_ai_translation/tests/src/Functional/AiTranslationWorkflowTest.php
```

## Notes importantes

- Ne pas utiliser `--filter` global seul (risque de chargement de tests contrib hors périmètre).
- Ne pas dépendre de la base locale : `BrowserTestBase` crée une installation isolée.
- Les scripts Composer neutralisent les deprecations Symfony via `SYMFONY_DEPRECATIONS_HELPER=disabled` pour éviter `OK, but there were issues!` en exécution locale.
- `test:homepage-smoke` n’ouvre plus systématiquement un serveur PHP en arrière-plan : en DDEV, il réutilise `DDEV_PRIMARY_URL`; hors DDEV, un serveur local est démarré automatiquement en fallback.
- Le branchement GitHub Actions est volontairement traité dans un ticket séparé.
