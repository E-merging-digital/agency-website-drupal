# Default Content — contenu initial versionné

Ce projet utilise le module contrib `default_content` pour versionner et importer automatiquement le contenu éditorial initial.

## Entités incluses

Les exports sont stockés dans `web/modules/custom/emerging_digital_content/content/`.

- `node/` : 5 pages stratégiques (Accueil, Services, IA & Drupal, Cas clients, Contact)
- `paragraph/` : Paragraphs référencés par ces pages (`hero`, `text_block`, `services`, `ai_features`, `case_clients`, `trust_list`, `cta`)

> Conformément à la documentation officielle du module, les UUID à importer sont déclarés dans `emerging_digital_content.info.yml` sous la clé `default_content`.

## Exporter le contenu (workflow recommandé)

Pré-requis : contenu validé déjà présent dans Drupal.

1. Vérifier/compléter la section `default_content` dans `emerging_digital_content.info.yml`.
2. Exporter selon la doc du module :
   - `drush default-content:export-module emerging_digital_content`
   - ou (avec dépendances) `drush default-content:export-module-with-references emerging_digital_content`
3. Vérifier les JSON générés dans :
   - `web/modules/custom/emerging_digital_content/content/node/`
   - `web/modules/custom/emerging_digital_content/content/paragraph/`

## Importer le contenu — environnement neuf

1. Installer les dépendances :
   - `composer install`
   - si besoin: `composer require "drupal/default_content:^2.0@alpha" "drupal/hal:^2.0"`
2. Importer la configuration :
   - `drush cim -y`
3. Activer les modules nécessaires (si pas déjà actifs) :
   - `hal` est requis pour l'import HAL JSON en default_content 2.x
   - `drush en hal serialization default_content emerging_digital_content -y`
4. Vider le cache :
   - `drush cr`

L’import du contenu est déclenché lors de l’installation du module `emerging_digital_content` grâce à la section `default_content` du `.info.yml`.

## Procédure si le module est déjà installé (cas actuel)

Si `emerging_digital_content` est déjà activé, l’import automatique à l’installation ne se relance pas tout seul.

### Option A (recommandée) : réimport sans désinstaller

```bash
drush php:eval "\\Drupal::service('default_content.importer')->importContent('emerging_digital_content');"
drush cr
```

### Option B : désinstaller/réinstaller le module de contenu

```bash
drush pmu emerging_digital_content -y
drush en emerging_digital_content -y
drush cr
```

## Vérifications après import

- Front page configurée :
  - `drush cget system.site page.front` (doit retourner `/accueil`)
- Nœuds pages présents :
  - `drush sqlq "SELECT nid,title FROM node_field_data WHERE type='page' ORDER BY nid;"`
- Alias front disponible :
  - `drush sqlq "SELECT alias,path FROM path_alias WHERE alias='/accueil';"`
- Références Paragraphs :
  - vérifier le rendu des pages stratégiques et l’ordre des sections.

## Dépannage commandes Drush

Selon la version de `default_content`, la commande `default-content:import` peut ne pas exister.

- Vérifier les commandes disponibles : `drush list --filter=default-content`
- Si la commande d’import n’est pas disponible, utiliser `drush php:eval` avec le service `default_content.importer`.

- Si tu vois `Undefined array key "uuid"` ou `Default content with uuid "" exists twice`, cela indique des exports sans clé `uuid` au niveau racine du JSON (format non attendu par le parseur courant).
