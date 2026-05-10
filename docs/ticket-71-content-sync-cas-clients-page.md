# Ticket 71 - Migration Content Sync de la page Cas clients

## Objectif

La page Cas clients est désormais déclarée dans le catalogue `content_sync/`
sous l’identifiant métier stable `cas-clients`.

Cette migration conserve les anciens fichiers `default_content` et ne retire
pas la dépendance au module `default_content`. Elle ne modifie ni les menus, ni
la homepage, ni le script de déploiement production, ni les workflows GitHub
Actions.

## Alias existants

Les alias réels constatés en base avant migration sont conservés :

- Alias FR : `/cas-clients`
- Alias EN : `/case-studies`

L’alias anglais public est donc `/en/case-studies` avec la négociation de
langue par préfixe.

## Contenu synchronisé

- Node `page` : `cas-clients`
- UUID historique du node : `28345cad-8947-4f91-b77e-9251609a5bcc`
- Titre FR : `Cas clients`
- Titre EN : `Case studies`
- Paragraphes synchronisés dans l’ordre éditorial :
  1. `cas-clients.hero`
  2. `cas-clients.intro`
  3. `cas-clients.case-studies`
  4. `cas-clients.cta`

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
Cas clients :

- dry-run sans écriture ;
- création ciblée du node et des 4 paragraphes ;
- traductions FR/EN ;
- alias FR `/cas-clients` et EN `/case-studies` ;
- ordre des paragraphes ;
- champs `field_items`, `field_case_problem`, `field_case_solution` et
  `field_case_result` du paragraphe `case_clients` ;
- mapping stable du node et des paragraphes ;
- rejouabilité sans duplication.

Le test `--all` attend désormais quatre contenus de catalogue :

1. `agence-drupal-belgique`
2. `services`
3. `ia-drupal`
4. `cas-clients`

## Validation prévue

Les commandes de validation du ticket sont :

```powershell
ddev drush cr
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync cas-clients --dry-run
ddev drush emerging:content-sync cas-clients
ddev drush emerging:content-sync cas-clients
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
