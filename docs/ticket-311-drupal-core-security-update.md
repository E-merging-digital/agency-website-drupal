# Ticket 311 - Mise a jour securite Drupal Core

## Contexte

- Issue: #311
- Objectif: mettre a jour Drupal Core 11.3.x vers la version de correction 11.3.10.
- Branche: `feature/311-maj-drupal-core-securite`

## Versions

| Paquet | Avant | Apres |
| --- | --- | --- |
| `drupal/core` | 11.3.9 | 11.3.10 |
| `drupal/core-recommended` | 11.3.9 | 11.3.10 |
| `drupal/core-composer-scaffold` | 11.3.9 | 11.3.10 |
| `drupal/core-project-message` | 11.3.9 | 11.3.10 |
| `drupal/core-dev` | 11.3.9 | 11.3.10 |
| `drupal/core-recipe-unpack` | 11.3.9 | 11.3.10 |

## Notes Composer

- Commande principale executee dans le conteneur web DDEV:
  `composer update drupal/core-recommended drupal/core-composer-scaffold drupal/core-project-message --with-all-dependencies`
- Mise a jour complementaire ciblee executee pour aligner les paquets Core/dev et corriger les advisories restantes:
  `composer update drupal/core-dev drupal/core-recipe-unpack symfony/dom-crawler composer/composer --with-all-dependencies`
- Aucun module contrib applicatif n'a ete mis a jour.
- `composer audit` ne signale plus d'advisory de securite.
- `composer outdated "drupal/*"` signale encore `drupal/ai` 1.3.4 -> 1.3.5 et `drupal/coder` 8.3.31 -> 9.0.0, laisses hors perimetre.

## Validations

- `drush updb -y`: aucun update en attente.
- `drush cim -y`: aucun changement a importer.
- `drush cr`: cache reconstruit.
- `drush config:status`: aucune difference entre DB et sync.
- `git diff --check`: OK.
- `composer lint:phpcs`: OK.
- `composer lint:phpstan`: OK.
- `composer lint:drupal-check`: OK, avec une deprecation existante sur `drupal_root`.
- `drush emerging:content-sync:validate`: OK, 36 contenus valides, 0 erreur.
- `drush emerging:content-sync --all --dry-run`: OK, aucune ecriture, menus intacts.
- Test Kernel cible: OK, 15 tests, 0 failure.
