# Default Content — contenu initial versionné

Ce projet utilise le module contrib `default_content` pour versionner et importer automatiquement le contenu éditorial initial.

## Entités incluses

Les exports sont stockés dans `web/modules/custom/emerging_digital_content/content/`.

- `node/` : 5 pages stratégiques (Accueil, Services, IA & Drupal, Cas clients, Contact)
- `paragraph/` : Paragraphs référencés par ces pages (`hero`, `text_block`, `services`, `ai_features`, `case_clients`, `trust_list`, `cta`)

## Exporter le contenu

Pré-requis : contenu validé déjà présent dans Drupal.

1. Installer les dépendances puis activer le module :
   - `composer require drupal/default_content`
   - `drush en default_content emerging_digital_content -y`
2. Exporter chaque nœud stratégique par UUID :
   - `drush dcer node <UUID_NODE> emerging_digital_content`
3. Vérifier que les JSON sont générés sous :
   - `web/modules/custom/emerging_digital_content/content/node/`
   - `web/modules/custom/emerging_digital_content/content/paragraph/`

> `dcer` exporte automatiquement les dépendances (Paragraphs référencés).

## Importer le contenu

Sur un environnement neuf :

1. Installer les dépendances :
   - `composer install`
2. Activer les modules :
   - `drush en default_content emerging_digital_content -y`
3. (Re)import forcé si besoin :
   - `drush default-content:import module emerging_digital_content`
4. Vider le cache :
   - `drush cr`

## Non-duplication et reproductibilité

- `default_content` s’appuie sur les UUID versionnés : la réimportation met à jour les entités existantes au lieu d’en créer de nouvelles.
- Les relations `node -> field_home_components -> paragraphs` sont conservées via les UUID des Paragraphs exportés.
- La front page est définie automatiquement à l’installation du module sur `/accueil`.

## Vérifications recommandées

- `drush sqlq "SELECT title, nid FROM node_field_data WHERE type='page' ORDER BY nid;"`
- Vérifier qu’un second import ne modifie pas le nombre de nœuds.
- Contrôler le rendu de la home et des pages stratégiques.
