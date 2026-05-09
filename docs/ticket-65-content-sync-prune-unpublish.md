# Ticket 65 - Prune prudent par depublication

La commande Content Sync accepte desormais le mode prudent :

```powershell
ddev drush emerging:content-sync --all --prune=unpublish
```

Ce mode reste volontairement limite. Il identifie les mappings Content Sync
actifs dont l'identifiant metier n'est plus present dans le catalogue YAML, puis
depublie uniquement les nodes geres correspondants.

## Comportement

- `--all` sans `--prune` conserve le comportement du ticket 64 : creation ou
  mise a jour des contenus declares dans le catalogue.
- `--prune=unpublish --dry-run` lit le catalogue, lit les mappings actifs
  absents du catalogue et affiche les nodes qui seraient depublies sans ecrire
  en base.
- `--prune=unpublish` applique d'abord le catalogue complet, puis depublie les
  nodes geres absents du catalogue.
- Chaque mapping depublie est conserve et mis a jour avec
  `last_action = unpublished`.
- Les contenus manuels non geres, sans mapping actif, ne sont jamais touches.

## Garde-fous

Le prune est refuse sans `--all`.

Le seul mode implemente est `unpublish`. Le mode `delete` n'est pas implemente
et doit etre refuse. Aucune execution de Content Sync ne supprime de contenu
Drupal.

Le prune ne modifie aucun menu et ne cree, modifie ou supprime aucune entite
`menu_link_content`.

Le mode `--dry-run` reste strictement en lecture seule : il ne sauvegarde ni
node, ni mapping, ni menu.

## Hors perimetre

Cette implementation ne fait pas de suppression definitive :

- pas de `--prune=delete` ;
- aucune suppression de contenu ;
- aucune modification des contenus manuels non geres ;
- aucune modification des workflows GitHub Actions ;
- aucune modification de `deploy-production.sh` ;
- aucune suppression de `default_content`.

## Commandes de validation

```powershell
ddev drush cr
ddev drush updb -y
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev drush emerging:content-sync --all --prune=unpublish --dry-run
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
