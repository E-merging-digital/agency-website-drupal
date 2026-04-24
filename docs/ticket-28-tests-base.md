# Ticket 28 — Base de tests automatisés

## Ce qui est couvert

- Workflow de traduction IA individuelle avec écran de confirmation.
- Exécution de l’action de masse depuis `/admin/content`.
- Vérification de la création de traduction en langue cible (`en`) et du contenu traduit.
- Vérification de base de l’alias de traduction (quand un alias EN existe).
- Formulaire de contact public : affichage des champs, cas invalide, cas valide.

## Isolation des tests (important)

Les tests `BrowserTestBase` sont exécutés dans une installation Drupal de test isolée.
Ils ne doivent **pas** dépendre de la base locale existante.

Le type de contenu `page` est créé explicitement dans `setUp()` du test de workflow avant l’activation de `content_translation`.

## Pré-requis local

Créer le dossier de sortie navigateur utilisé par Simpletest (si absent) :

```bash
mkdir -p web/sites/simpletest/browser_output
```

## Lancer les tests en local (DDEV)

```bash
ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site \
SIMPLETEST_DB=mysql://db:db@db/db \
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
web/modules/custom/agency_ai_translation/tests/src/Functional/AiTranslationWorkflowTest.php
```

```bash
ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site \
SIMPLETEST_DB=mysql://db:db@db/db \
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
web/modules/custom/agency_ai_translation/tests/src/Functional/ContactFormTest.php
```

Suite complète :

```bash
ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site \
SIMPLETEST_DB=mysql://db:db@db/db \
vendor/bin/phpunit -c web/core/phpunit.xml.dist
```

## Limites actuelles

- Le test contact cible le module `contact` cœur Drupal. Si `webform` est requis dans le projet, un test dédié `webform` sera ajouté quand le module sera présent dans le dépôt.
- La génération d’alias Pathauto est validée de manière pragmatique (absence d’alias brut `/node/{nid}` lorsqu’un alias EN est généré).
