# Ticket 67 - Content Sync deploy integration

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/198

Ce ticket intègre prudemment Content Sync au script de déploiement production.

## Périmètre

Fichiers modifiés dans ce ticket :

- `scripts/deploy-production.sh`
- `docs/ticket-67-content-sync-deploy-integration.md`

Fichiers volontairement non modifiés :

- `.github/workflows/ci.yml`
- `.github/workflows/deploy-production.yml`
- menus Drupal
- `default_content`
- système Content Sync custom

## Audit du script

Le script `scripts/deploy-production.sh` applique déjà la séquence critique
post-bascule suivante sur la nouvelle release :

```bash
"$CURRENT_LINK/vendor/bin/drush" updb -y
"$CURRENT_LINK/vendor/bin/drush" cim -y
"$CURRENT_LINK/vendor/bin/drush" cr
```

Il utilise `set -Eeuo pipefail` et un trap `ERR`. Une commande Drush qui échoue
interrompt donc le déploiement, journalise l'échec, tente de sortir du mode
maintenance et conserve la release précédente disponible pour rollback.

## Intégration retenue

Content Sync est exécuté après `drush updb -y` et `drush cim -y`, car la
synchronisation dépend du code et de la configuration importée.

La commande intégrée est strictement :

```bash
"$CURRENT_LINK/vendor/bin/drush" emerging:content-sync --all
```

Elle est précédée d'un log explicite :

```bash
log "[deploy] Content Sync"
```

Aucun mode prune n'est ajouté :

- pas de `--prune=unpublish`
- pas de `--prune=delete`

Un `drush cr` reste exécuté après Content Sync afin de reconstruire les caches
après les changements de contenu.

## Comportement en cas d'échec

L'erreur n'est pas masquée. Si :

```bash
"$CURRENT_LINK/vendor/bin/drush" emerging:content-sync --all
```

retourne un code non nul, `set -Eeuo pipefail` et le trap `ERR` font échouer le
déploiement.

## Validation

Commandes à exécuter :

```powershell
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
