# Ticket 64 - Synchronisation multiple Content Sync

La commande `drush emerging:content-sync --all` applique desormais tous les
contenus declares dans le catalogue YAML versionne du module
`emerging_digital_content`.

Le mode `drush emerging:content-sync --all --dry-run` reste strictement en
lecture seule : il charge le catalogue, valide les entrees, lit les mappings
existants et produit un rapport sans creer ni modifier de contenu Drupal ni
ligne de mapping.

## Perimetre

La synchronisation multiple traite uniquement les entrees presentes dans :

- `web/modules/custom/emerging_digital_content/content_sync/catalog.yml`
- les fichiers references par ce catalogue.

Elle reutilise la meme logique que la synchronisation ciblee existante :

```powershell
ddev drush emerging:content-sync agence-drupal-belgique
```

La commande ciblee reste disponible avec ou sans `--dry-run`.

## Validation avant ecriture

`--all` charge et valide tout le catalogue avant toute ecriture. Si le catalogue
contient une erreur bloquante, la commande s'arrete avant de sauvegarder des
entites Drupal ou des mappings.

La commande effectue aussi un preflight du perimetre supporte avant la boucle
d'ecriture. Une entree hors perimetre bloque l'execution globale avant toute
sauvegarde.

## Idempotence et mappings

Chaque contenu du catalogue est synchronise via son identifiant metier stable.
Les mappings existants sont preserves et mis a jour. Une deuxieme execution de
`drush emerging:content-sync --all` rejoue la synchronisation sans dupliquer les
contenus deja geres.

Le rapport groupe les actions par contenu pour faciliter la lecture des
creations, mises a jour, resolutions de mapping et avertissements.

## Hors perimetre

Cette implementation ne fait pas de prune et ne supprime rien :

- pas de `--prune` ;
- aucune depubllication de contenus absents du catalogue ;
- aucune suppression de contenu ;
- aucune modification de menus ;
- aucun changement sur les contenus manuels non geres ;
- aucune modification des workflows GitHub Actions ;
- aucune modification de `deploy-production.sh` ;
- aucune suppression de `default_content`.

## Commandes de verification

```powershell
ddev drush cr
ddev drush updb -y
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync agence-drupal-belgique --dry-run
ddev drush emerging:content-sync agence-drupal-belgique
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev drush emerging:content-sync --all
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
