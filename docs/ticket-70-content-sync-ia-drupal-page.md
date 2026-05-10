# Ticket 70 - Migration Content Sync de la page IA & Drupal

## Objectif

La page IA & Drupal est désormais déclarée dans le catalogue
`content_sync/` sous l’identifiant métier stable `ia-drupal`.

Cette migration conserve les anciens fichiers `default_content` et ne retire
pas la dépendance au module `default_content`. Elle ne modifie ni les menus, ni
la homepage, ni le script de déploiement production, ni les workflows GitHub
Actions.

## Alias existants

Les alias réels constatés en base avant migration sont conservés :

- Alias FR : `/ia-drupal`
- Alias EN : `/ai-drupal`

L’alias anglais public est donc `/en/ai-drupal` avec la négociation de langue
par préfixe.

## Contenu synchronisé

- Node `page` : `ia-drupal`
- UUID historique du node : `3636da02-c8e6-40ea-aa71-54148f029a09`
- Titre FR : `IA & Drupal`
- Titre EN : `AI & Drupal`
- Paragraphes synchronisés dans l’ordre éditorial :
  1. `ia-drupal.hero`
  2. `ia-drupal.intro`
  3. `ia-drupal.features`
  4. `ia-drupal.benefits`
  5. `ia-drupal.integration`
  6. `ia-drupal.cta`

Chaque paragraphe garde un identifiant métier stable et déclare son UUID
historique `default_content` pour permettre une première reprise sans créer de
doublon lorsque le contenu existe déjà.

## Moteur Content Sync

Aucun changement du moteur Content Sync n’a été nécessaire pour ce ticket.

Le moteur existant supporte déjà :

- la résolution d’un node par mapping, UUID historique, puis alias ;
- les traductions FR/EN et les alias par langue ;
- les composants de page `field_home_components` ;
- la résolution des paragraphes par mapping ou UUID historique ;
- la préservation de l’ordre des paragraphes depuis le YAML ;
- l’écriture du mapping du node et des paragraphes ;
- l’exclusion explicite des menus.

## Tests

Le test Kernel `ContentSyncManagerTargetedWriteTest` couvre maintenant la page
IA & Drupal :

- dry-run sans écriture ;
- création ciblée du node et des 6 paragraphes ;
- traductions FR/EN ;
- alias FR `/ia-drupal` et EN `/ai-drupal` ;
- ordre des paragraphes ;
- mapping stable du node et des paragraphes ;
- rejouabilité sans duplication.

Le test `--all` attend désormais trois contenus de catalogue :

1. `agence-drupal-belgique`
2. `services`
3. `ia-drupal`

## Validation prévue

Les commandes de validation du ticket sont :

```powershell
ddev drush cr
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync ia-drupal --dry-run
ddev drush emerging:content-sync ia-drupal
ddev drush emerging:content-sync ia-drupal
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
