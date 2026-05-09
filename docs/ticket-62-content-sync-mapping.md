# Ticket 62 - Mapping persistant Content Sync

## Objectif

Ce ticket ajoute la couche persistante qui permettra aux prochains tickets
Content Sync d'identifier les contenus Drupal geres par un identifiant metier
stable du catalogue.

La synchronisation reelle des contenus reste hors perimetre. Le dry-run continue
de lire et valider le catalogue sans creer, modifier ou supprimer d'entite
Drupal.

## Table ajoutee

La table `emerging_digital_content_sync_mapping` est declaree dans
`emerging_digital_content_schema()` et creee sur les environnements deja
installes via `emerging_digital_content_update_11001()`.

Colonnes principales :

- `content_id` : identifiant metier stable du catalogue.
- `entity_type` : type d'entite Drupal gere.
- `entity_id` : identifiant numerique Drupal.
- `entity_uuid` : UUID Drupal.
- `langcode` : langue principale suivie par le mapping.
- `catalog_hash` : hash de la definition catalogue lors de la derniere synchro.
- `last_synced` : timestamp de la derniere synchronisation reussie.
- `last_action` : derniere action enregistree.
- `status` : statut reserve aux futurs modes `--all` et `--prune`.

`content_id` est unique afin de garantir l'idempotence : un identifiant metier
ne peut pointer que vers un seul mapping actif.

## Repository

Le service
`emerging_digital_content.content_sync_mapping_repository` encapsule les acces
SQL a la table custom.

Methodes disponibles :

- `findByContentId(string $content_id)` : retourne le mapping connu ou `NULL`.
- `exists(string $content_id)` : indique si le mapping existe.
- `createOrUpdate(ContentSyncMappingRecord $record)` : cree ou met a jour le
  mapping pour un identifiant metier.
- `remove(string $content_id)` : supprime uniquement la ligne de mapping.

`remove()` ne supprime jamais de contenu Drupal. Les futures suppressions ou
desactivations eventuelles devront etre implementees explicitement dans un
ticket ulterieur.

## Integration actuelle

`ContentSyncManager` recoit le repository et consulte le mapping pendant le
dry-run. Le rapport indique si un contenu du catalogue possede deja un mapping,
ainsi que les informations lisibles disponibles.

Aucune ecriture de mapping n'est effectuee par le dry-run. La methode
`createOrUpdate()` est disponible pour les prochains tickets qui introduiront la
synchronisation reelle.

## Garanties de perimetre

Cette implementation :

- ne modifie aucun menu ;
- ne modifie aucun workflow GitHub Actions ;
- ne modifie pas `deploy-production.sh` ;
- ne supprime pas `default_content` ;
- ne cree, modifie ou supprime aucun contenu Drupal reel ;
- n'implemente pas `--all` ;
- n'implemente pas `--prune` ;
- ne cree aucune suppression automatique.

## Preparation des tickets suivants

La table conserve les informations necessaires pour :

- rejouer une synchronisation sans dupliquer les contenus ;
- detecter les contenus deja geres ;
- comparer le hash du catalogue avec le dernier hash synchronise ;
- proteger les contenus manuels non presents dans le mapping ;
- preparer un futur inventaire `--all` ;
- preparer un futur audit `--prune` sans suppression implicite.
