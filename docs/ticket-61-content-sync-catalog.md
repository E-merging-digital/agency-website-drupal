# Ticket 61 - Catalogue Content Sync versionne

## Objectif

Ce ticket introduit la couche de lecture et de validation du futur Content Sync
global, sans synchronisation reelle et sans ecriture en base.

La definition prototype `agence-drupal-belgique` est deplacee hors du PHP vers
des fichiers YAML versionnes dans le module `emerging_digital_content`.

## Structure ajoutee

```text
web/modules/custom/emerging_digital_content/content_sync/
├── catalog.yml
├── node/
│   └── agence-drupal-belgique.yml
└── paragraph/
    └── .gitkeep
```

`catalog.yml` contient les metadonnees minimales :

- `id`
- `entity_type`
- `bundle`
- `file`
- `translations`
- `business_aliases`

Le fichier reference dans `file` contient les champs et les definitions
editoriales complementaires lues par le dry-run.

## Services

Trois services composent cette premiere infrastructure :

- `ContentSyncCatalogLoader` lit `catalog.yml` et fusionne les fichiers YAML
  declares.
- `ContentSyncCatalogValidator` controle le schema minimal.
- `ContentSyncManager` expose des rapports de validation et de dry-run
  strictement en lecture seule.

## Validations realisees

Le validateur verifie :

- les cles obligatoires `id`, `entity_type`, `bundle`, `translations` ;
- l'unicite des identifiants metier ;
- l'existence des fichiers declares ;
- la presence d'un alias pour chaque traduction ;
- les chemins de fichiers manifestement non surs.

Les erreurs YAML ou de structure sont converties en exceptions lisibles par le
loader, puis remontees dans le rapport Drush.

## Commandes Drush

Valider tout le catalogue :

```bash
ddev drush emerging:content-sync:validate
```

Lire le catalogue en dry-run pour un contenu :

```bash
ddev drush emerging:content-sync agence-drupal-belgique --dry-run
```

Le dry-run produit un rapport lisible :

- contenus trouves ;
- contenus valides ;
- contenus invalides ;
- warnings ;
- erreurs bloquantes ;
- actions simulees.

## Garanties de perimetre

Cette implementation ne cree aucune entite Drupal, ne modifie aucun contenu, ne
met a jour aucun menu et n'ecrit pas en base. Le systeme `default_content` reste
intact pour conserver la compatibilite avec l'existant.
