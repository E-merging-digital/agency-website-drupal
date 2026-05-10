# Ticket 72 - Migration Content Sync de la page Contact

## Objectif

La page Contact est désormais déclarée dans le catalogue `content_sync/` sous
l'identifiant métier stable `contact`.

Cette migration conserve les anciens fichiers `default_content` et ne retire
pas la dépendance au module `default_content`. Elle ne modifie ni les menus, ni
la homepage, ni le script `deploy-production.sh`, ni les workflows GitHub
Actions.

## Alias existants

Les alias réels constatés en base avant migration sont conservés :

- Alias FR : `/contact`
- Alias EN : `/contact`

L'alias anglais public reste donc `/en/contact` avec la négociation de langue
par préfixe.

## Contenu synchronisé

- Node `page` : `contact`
- UUID historique du node : `d962ec05-25ec-4446-b645-0959a57964e7`
- Titre FR : `Contact`
- Titre EN : `Contact`
- Paragraphes synchronisés dans l'ordre éditorial réel :
  1. `contact.hero`
  2. `contact.intro`
  3. `contact.coordinates`
  4. `contact.information`
  5. `contact.form`
  6. `contact.map`

Chaque paragraphe garde un identifiant métier stable. Les UUID déclarés comme
`legacy_uuid` correspondent aux entités réellement utilisées par la page quand
elles existent en base, afin de rejouer la synchronisation sans créer de
doublons.

Les paragraphes `contact.coordinates` et `contact.map` reprennent les UUID
actuellement référencés par la page installée, car les post-updates historiques
ont créé ces blocs après l'import initial `default_content`.

## Moteur Content Sync

Aucun changement du moteur Content Sync n'a été nécessaire pour ce ticket.

Le moteur existant supporte déjà :

- la résolution d'un node par mapping, UUID historique, puis alias ;
- les traductions FR/EN et les alias par langue ;
- les composants de page `field_home_components` ;
- la résolution des paragraphes par mapping ou UUID déclaré ;
- la préservation de l'ordre des paragraphes depuis le YAML ;
- l'écriture du mapping du node et des paragraphes ;
- l'exclusion explicite des menus.

## Tests

Le test Kernel `ContentSyncManagerTargetedWriteTest` couvre maintenant la page
Contact :

- dry-run sans écriture ;
- création ciblée du node et des 6 paragraphes ;
- traductions FR/EN ;
- alias FR `/contact` et EN `/contact` ;
- ordre éditorial des paragraphes ;
- reprise des blocs coordonnées, informations, formulaire et carte ;
- mapping stable du node et des paragraphes ;
- rejouabilité sans duplication.

Le test `--all` attend désormais cinq contenus de catalogue :

1. `agence-drupal-belgique`
2. `services`
3. `ia-drupal`
4. `cas-clients`
5. `contact`

## Validation prévue

Les commandes de validation du ticket sont :

```powershell
ddev drush cr
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync contact --dry-run
ddev drush emerging:content-sync contact
ddev drush emerging:content-sync contact
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
