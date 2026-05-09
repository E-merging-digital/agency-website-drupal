# Ticket 66 - Content Sync deploy safety

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/197

Ce ticket ajoute des garde-fous operationnels autour de Content Sync avant une
future integration au deploiement production.

Il ne branche pas Content Sync au deploiement et ne modifie pas :

- `scripts/deploy-production.sh` ;
- `.github/workflows/ci.yml` ;
- `.github/workflows/deploy-production.yml`.

## Rapport `--all --dry-run`

La commande :

```powershell
ddev drush emerging:content-sync --all --dry-run
```

reste strictement read-only. Elle lit le catalogue YAML, valide les entrees,
inspecte les mappings existants et ajoute maintenant un rapport detaille par
contenu :

- identifiant metier ;
- type d'entite et bundle ;
- operation prevue (`would create managed entity` ou `would update mapped entity`) ;
- statut du mapping ;
- traductions detectees ;
- hash de catalogue.

Le dry-run ne cree ni entite Drupal, ni mapping, ni alias, ni lien de menu.

## Resume final structure

Chaque rapport Content Sync contient une cle `summary` et la commande Drush
l'affiche en fin de sortie sous `Final summary`.

Le resume expose notamment :

- le mode d'execution ;
- le fait que `--all` et `--dry-run` soient actifs ou non ;
- le mode prune ;
- le nombre de contenus trouves, valides et invalides ;
- le nombre d'actions, warnings et erreurs ;
- `blocking_errors`, utilise pour rendre l'echec explicite ;
- `writes_attempted` ;
- `menus_touched`.

Une commande Drush retourne un exit code non nul des qu'une erreur bloquante est
presente dans le rapport ou qu'une exception interrompt l'execution.

## Protection production du prune

Le mode prudent existant reste limite a :

```powershell
ddev drush emerging:content-sync --all --prune=unpublish
```

`--prune=delete` n'est pas implemente.

En environnement production, l'application reelle de
`--prune=unpublish` est bloquee par defaut. Elle n'est autorisee que si la
variable suivante est explicitement definie :

```powershell
CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH=1
```

La detection production repose sur le split
`config_split.config_split.production` active par les settings de production,
avec fallback sur les variables `APP_ENV`, `DRUPAL_ENV` ou `ENVIRONMENT` quand
leur valeur vaut `prod` ou `production`.

Le dry-run du prune reste autorise sans cette variable, car il ne fait aucune
modification :

```powershell
ddev drush emerging:content-sync --all --prune=unpublish --dry-run
```

## Garanties conservees

- aucune suppression definitive ;
- aucun `--prune=delete` ;
- aucun changement de menu ;
- aucun retrait de `default_content` ;
- aucun changement destructif ;
- aucune integration au script de deploiement production dans ce ticket.

## Validation

Commandes a executer :

```powershell
ddev drush cr
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev drush emerging:content-sync --all --prune=unpublish --dry-run
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
