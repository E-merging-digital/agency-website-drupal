# Ticket 28 — Base de tests automatisés

## Ce qui est couvert

- Workflow de traduction IA individuelle avec écran de confirmation.
- Exécution de l’action de masse depuis `/admin/content`.
- Vérification de la création de traduction en langue cible (`en`) et du contenu traduit.
- Vérification de base de l’alias de traduction (quand un alias EN existe).
- Formulaire de contact public : affichage des champs, cas invalide, cas valide.

## Limites actuelles

- Le test contact cible le module `contact` cœur Drupal. Si le module `webform` est requis en environnement projet, un test dédié `webform` devra être ajouté quand le module sera disponible dans le dépôt.
- La génération d’alias Pathauto est validée de manière pragmatique (absence d’alias brut `/node/{nid}` lorsqu’un alias EN est généré).

## Lancer les tests en local

```bash
ddev drush cr
vendor/bin/phpunit web/modules/custom/agency_ai_translation/tests/src/Functional/AiTranslationWorkflowTest.php
vendor/bin/phpunit web/modules/custom/agency_ai_translation/tests/src/Functional/ContactFormTest.php
```

Ou la suite complète :

```bash
vendor/bin/phpunit
```
