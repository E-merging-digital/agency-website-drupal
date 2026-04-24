# CI GitHub Actions

## Objectif

La CI exécute un pipeline ciblé pour Drupal 11 / PHP 8.3 sur GitHub Actions, avec :

- validation Composer stricte ;
- installation des dépendances (avec `require-dev`) ;
- contrôles qualité déclarés dans `composer.json` uniquement ;
- exécution PHPUnit strictement limitée aux tests custom stables.

## Déclenchement

Le workflow est défini dans `.github/workflows/ci.yml` et se lance sur :

- `push` vers `main` ;
- `pull_request`.

## Pourquoi seuls les tests custom sont exécutés

La CI exécute explicitement cette commande :

```bash
vendor/bin/phpunit -c web/core/phpunit.xml.dist --exclude-group unstable_ia web/modules/custom/agency_project_tests/tests
```

Conséquences :

- aucun lancement global de PHPUnit ;
- aucun scan de `web/core` ni de `web/modules/contrib` ;
- seuls les tests du module custom `agency_project_tests` sont ciblés.

## Pourquoi `unstable_ia` est exclu

Les tests IA du module `agency_ai_translation` sont marqués instables via le groupe `unstable_ia`.

La CI les exclut explicitement avec `--exclude-group unstable_ia` pour garantir un pipeline fiable et reproductible, sans tentative de stabilisation forcée et sans dépendance à des appels externes (OpenAI).

## Environnement de tests

Le workflow configure :

- `SIMPLETEST_BASE_URL=http://127.0.0.1:8888`
- `SIMPLETEST_DB=sqlite://localhost/tmp/simpletest.sqlite`

Un serveur PHP local est démarré sur `127.0.0.1:8888` et la base SQLite est utilisée pour les tests BrowserTestBase en CI.

## Commande locale (PowerShell via ddev)

```powershell
ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist --exclude-group unstable_ia web/modules/custom/agency_project_tests/tests
```
