# Ticket 60 - Audit et stratégie de Content Sync versionné

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/185

## Résumé de décision

Le projet doit conserver temporairement `default_content` pour ne pas casser les environnements existants ni mélanger ce ticket avec une désinstallation Composer ou Drupal. Le remplacement doit être progressif : documenter, introduire un catalogue custom versionné, migrer les contenus un par un, puis retirer `default_content` dans un ticket dédié seulement quand aucun contenu ni mécanisme runtime ne dépend encore de lui.

Le système cible doit être idempotent, auditable et prudent en production. Par défaut, il crée ou met à jour uniquement les contenus explicitement présents dans un catalogue, ne touche jamais aux contenus manuels non gérés, ne modifie pas les menus, et ne supprime jamais définitivement sans option explicite.

## Périmètre de ce ticket

Ce ticket est un audit et une stratégie documentaire. Il ne doit pas :

- modifier `scripts/deploy-production.sh` ;
- modifier `.github/workflows/deploy-production.yml` ;
- modifier `.github/workflows/ci.yml` ;
- supprimer `default_content` de Composer ou de la configuration Drupal ;
- désinstaller un module ;
- modifier les menus ;
- modifier les contenus de production ;
- introduire une suppression automatique.

## Audit de `default_content`

### Dépendances Composer et modules

`default_content` est encore une dépendance explicite du projet :

- `composer.json` requiert `drupal/default_content` avec la contrainte `^2.0@alpha`.
- `composer.lock` installe actuellement `drupal/default_content` en version `2.0.0-beta1`.
- `config/sync/core.extension.yml` active le module `default_content`.
- `web/modules/custom/emerging_digital_content/emerging_digital_content.info.yml` déclare une dépendance vers `drupal:default_content`.
- Le module custom dépend aussi de `node`, `paragraphs`, `serialization` et `hal`, utilisés par le format historique de contenu empaqueté.

Conclusion : `default_content` est encore structurellement lié au module `emerging_digital_content`. Le retirer maintenant serait risqué et hors périmètre.

### Contenu empaqueté

Les contenus historiques sont stockés dans `web/modules/custom/emerging_digital_content/content/`.

Le dossier contient actuellement :

- 5 nodes de type `page` :
  - `Accueil` avec alias `/accueil` ;
  - `Services` avec alias `/services` ;
  - `IA & Drupal` avec alias `/ia-drupal` ;
  - `Cas clients` avec alias `/cas-clients` ;
  - `Contact` avec alias `/contact`.
- 29 paragraphes YAML, principalement des bundles `hero`, `text_block`, `services`, `trust_list`, `ai_features`, `case_clients` et `cta`.

Le bloc `default_content:` de `emerging_digital_content.info.yml` référence 5 nodes et 27 paragraphes. Deux fichiers paragraphes présents dans le dossier `content/paragraph/` ne sont pas listés dans ce bloc :

- `856cbeed-a7cd-430b-ba72-aca0b79b6022.yml` ;
- `fe7288c1-8611-4fc9-8b3e-fdb6219038d2.yml`.

Ces écarts doivent être traités comme un signal d'audit : avant toute migration automatisée, le catalogue cible doit être la source de vérité et doit refuser les fichiers orphelins ou non référencés sans rapport explicite.

### Mécanismes encore liés à `default_content`

Les mécanismes liés à `default_content` sont les suivants :

- `emerging_digital_content.info.yml` déclare la liste des entités à importer via `default_content`.
- `emerging_digital_content.install` appelle `_emerging_digital_content_purge_stale_entities()` lors de l'installation, puis configure la front page et la navigation principale.
- `_emerging_digital_content_purge_stale_entities()` supprime les nodes et paragraphes existants dont les UUID correspondent aux fichiers YAML empaquetés. C'est destructeur et ne doit pas servir de modèle au futur Content Sync global.
- `emerging_digital_content.post_update.php` contient `emerging_digital_content_post_update_import_default_content()`, qui utilise le service `default_content.importer`.
- `web/modules/custom/emerging_digital_content/scripts/import_default_content.php` utilise aussi `default_content.importer` pour les workflows locaux ou de bootstrap.

### Post-updates historiques

Le fichier `emerging_digital_content.post_update.php` contient plusieurs générations de logique éditoriale :

- import initial du contenu empaqueté ;
- création, normalisation et déduplication de liens de menu ;
- correction d'alias multilingues ;
- harmonisation de la page Contact ;
- création ou mise à jour des pages légales, cookies et politique de confidentialité ;
- mise à jour de CTA ;
- repositionnement éditorial de paragraphes par UUID ;
- normalisation de la langue source et des chemins ;
- alignement du language switcher et des traductions de menu.

Ces post-updates ont rendu service pour faire évoluer des environnements déjà installés, mais ils ne constituent pas une stratégie de Content Sync pérenne. Ils mélangent contenu, menus, alias, langue, suppression de doublons et corrections ponctuelles. Le système cible doit reprendre uniquement les intentions éditoriales utiles, avec des règles explicites et un dry-run lisible.

## Contenus déjà gérés par le Content Sync custom

Le seul contenu actuellement géré par le système custom `emerging:content-sync` est :

- identifiant métier : `agence-drupal-belgique` ;
- type de contenu cible : `service` ;
- alias français : `/agence-drupal-belgique` ;
- alias anglais : `/en/drupal-agency-belgium` en URL publique, avec alias interne `/drupal-agency-belgium` selon la configuration de langue Drupal ;
- champs synchronisés : titre, publication, alias, `field_short_description`, `field_detailed_description` ;
- promotions éditoriales : ajout ou mise à jour d'une carte dans le paragraphe `services` de la page `/services` et de la homepage ;
- menus : explicitement non modifiés.

Cette approche est plus sûre que `default_content` parce qu'elle cherche un contenu par alias métier ou titre avant création, et peut être rejouée sans duplication.

## Analyse de `ContentSyncManager`

`ContentSyncManager` est aujourd'hui un prototype ciblé, pas encore un Content Sync global.

Ses points positifs :

- il utilise un identifiant métier stable plutôt qu'un UUID empaqueté ;
- il propose un mode `dry-run` ;
- il évite de modifier les entités `menu_link_content` ;
- il sait créer une traduction manquante ;
- il force les alias attendus en désactivant Pathauto pour les pages gérées ;
- il retourne un rapport compact avec actions, warnings, node cible et indication `menus_touched`.

Ses limites :

- les définitions sont codées en dur dans `getDefinition()` ;
- une seule entrée est supportée ;
- la détection d'un contenu existant repose sur alias puis titre, sans marque de gestion persistante ;
- il n'existe pas encore de catalogue externe versionné ;
- il n'existe pas de notion de contenu retiré du catalogue ;
- il n'existe pas de stratégie `--all` ;
- il n'existe pas de mode prune ;
- le dry-run décrit les intentions mais ne calcule pas encore un diff détaillé champ par champ ;
- les paragraphes ne sont pas modélisés comme sous-objets versionnés complets ;
- les références entre contenus sont limitées à des liens éditoriaux dans des champs texte ;
- les conflits éditoriaux ne sont pas encore distingués des mises à jour normales ;
- il ne marque pas en base qu'une entité est gérée par Content Sync.

## Architecture cible proposée

### Principe général

Le futur Content Sync doit être une couche applicative custom dans `emerging_digital_content`, responsable uniquement des contenus éditoriaux versionnés explicitement déclarés.

Architecture cible :

- un catalogue versionné dans le dépôt ;
- un loader de catalogue ;
- un validateur de schéma ;
- un resolver d'entités par identifiant métier ;
- un planificateur d'actions ;
- un applicateur d'actions ;
- un rapport dry-run et un rapport d'exécution ;
- un système de marquage des contenus gérés ;
- des commandes Drush explicites.

Le comportement attendu est proche d'un import déclaratif, mais avec des garde-fous métier adaptés à la production.

### Catalogue de contenus versionnés

Le catalogue doit devenir la source de vérité pour les contenus gérés. Il peut être introduit sous une structure dédiée, par exemple :

```text
web/modules/custom/emerging_digital_content/content_sync/
  catalog.yml
  node/
    service/
      agence-drupal-belgique.yml
    page/
      services.yml
      ia-drupal.yml
  paragraph/
    ...
```

Chaque entrée de catalogue doit contenir au minimum :

- `id` : identifiant métier stable, unique et humainement lisible ;
- `entity_type` : par exemple `node` ou `paragraph` ;
- `bundle` : par exemple `service`, `page`, `text_block` ;
- `status` cible ;
- `langcode` source ;
- `translations` ;
- `aliases` si applicable ;
- `fields` ;
- `references` exprimées par identifiants métier, jamais par IDs numériques ;
- `seo` si des métadonnées spécifiques doivent être forcées ;
- `ownership` ou `managed_policy` pour préciser ce que le sync a le droit d'écraser.

Le catalogue doit être validé avant exécution. Une entrée invalide doit faire échouer le dry-run et l'exécution réelle.

### Marquage d'un contenu géré

Un contenu géré doit être identifiable en base indépendamment de son alias ou de son titre. La stratégie recommandée est d'ajouter une trace persistante non éditoriale, par exemple :

- une table custom `emerging_digital_content_sync_map` ;
- colonnes minimales : `content_id`, `entity_type`, `entity_id`, `entity_uuid`, `langcode`, `catalog_hash`, `last_synced`, `last_action`, `status`.

Cette table permet de savoir qu'un contenu a déjà été géré, même si son titre ou son alias a été modifié. Elle permet aussi d'identifier les contenus précédemment gérés mais retirés du catalogue.

Une alternative serait un champ dédié sur les bundles concernés, mais elle polluerait le modèle éditorial et nécessiterait des changements de configuration sur chaque type de contenu. La table custom est donc préférable pour une première version prudente.

## Règles de synchronisation

Le système cible doit appliquer les règles suivantes :

- contenu présent dans le catalogue et absent en base : création ;
- contenu présent dans le catalogue et présent en base : mise à jour ;
- contenu absent du catalogue mais précédemment géré : dépublication par défaut ;
- suppression définitive uniquement avec option explicite ;
- contenu manuel non géré : ne jamais toucher.

La résolution doit suivre cet ordre :

1. chercher une ligne de mapping par `content_id` ;
2. vérifier que l'entité existe encore ;
3. si aucune ligne n'existe, chercher par alias métier déclaré ;
4. en dernier recours, chercher par titre uniquement si le catalogue l'autorise explicitement ;
5. créer uniquement si aucune ambiguïté n'est détectée.

En cas de doublon ou d'ambiguïté, le sync doit s'arrêter pour l'entrée concernée et produire un warning bloquant.

## Comportements attendus par domaine

### Traductions

Le catalogue doit déclarer les traductions attendues par langue. Le sync peut créer une traduction absente et mettre à jour une traduction existante pour les champs explicitement gérés.

Règles proposées :

- ne jamais supprimer une traduction sans option future explicite et ticket dédié ;
- dépublier une traduction retirée du catalogue plutôt que la supprimer ;
- conserver la langue source stable, actuellement `fr` ;
- refuser une entrée si la langue demandée n'est pas activée ;
- rapporter les traductions créées, mises à jour, absentes ou ignorées dans le dry-run.

### Alias

Les alias doivent être gérés comme des données versionnées pour les contenus gérés uniquement.

Règles proposées :

- alias explicite par langue dans le catalogue ;
- `pathauto: false` pour les alias versionnés ;
- vérification de collision avant écriture ;
- si l'alias existe déjà sur une autre entité, bloquer l'exécution et demander une correction manuelle ;
- ne jamais nettoyer globalement les alias inconnus ;
- ne pas toucher aux alias de contenus non gérés.

### SEO

Le sync doit privilégier les defaults Metatag déjà configurés quand ils suffisent. Pour les contenus qui exigent des métadonnées spécifiques, le catalogue peut contenir une section `seo`.

Règles proposées :

- par défaut, ne pas écrire de metatags spécifiques ;
- documenter les champs utilisés par les defaults, par exemple `field_short_description` pour les services ;
- si `seo` est déclaré, mettre à jour uniquement les clés listées ;
- ne jamais écraser des métadonnées manuelles sur un contenu non géré ;
- rapporter les métadonnées SEO qui changeraient en dry-run.

### Paragraphes

Les paragraphes doivent être traités comme des sous-contenus versionnés, mais avec prudence.

Règles proposées :

- référencer les paragraphes par identifiant métier interne au catalogue, pas par ID numérique ;
- préserver l'ordre déclaré des paragraphes sur les champs de référence gérés ;
- créer ou mettre à jour les paragraphes gérés ;
- ne pas supprimer physiquement les paragraphes retirés sans option explicite ;
- si un champ de paragraphes contient des composants manuels non gérés, les préserver sauf si le champ entier est déclaré comme strictement géré ;
- éviter les valeurs encodées sous forme `Titre|Description|URL` dans les nouveaux contenus et préférer une structure déclarative claire.

### Références entre contenus

Les références doivent être exprimées en identifiants métier. Le sync doit résoudre les références après avoir planifié les créations.

Règles proposées :

- charger ou créer toutes les entités du catalogue avant de résoudre les références ;
- bloquer si une référence obligatoire pointe vers un contenu absent du catalogue et introuvable en base ;
- autoriser les références optionnelles avec warning ;
- ne jamais stocker d'IDs numériques dans le catalogue ;
- produire un ordre d'exécution déterministe.

### Menus

Les menus sont hors périmètre du Content Sync global. Cette règle doit rester explicite, même si des post-updates historiques ont créé ou corrigé des liens de menus.

Règles proposées :

- aucune commande `emerging:content-sync` ne crée, modifie ou supprime de `menu_link_content` ;
- les liens de navigation doivent être gérés par configuration, par ticket dédié ou manuellement ;
- le dry-run doit afficher `menus_touched: false` ;
- toute future automatisation de menus doit faire l'objet d'un ticket séparé et d'un mécanisme dédié.

## Commandes Drush cibles

Les commandes cibles proposées sont :

```bash
drush emerging:content-sync --all --dry-run
drush emerging:content-sync --all
drush emerging:content-sync --all --prune=unpublish
drush emerging:content-sync --all --prune=delete
```

Comportement attendu :

- `--all --dry-run` : charge le catalogue, valide les entrées, résout les contenus, affiche les créations, mises à jour, dépublications potentielles, conflits, warnings et erreurs bloquantes sans écrire en base.
- `--all` : crée ou met à jour les contenus présents dans le catalogue, sans traiter les contenus retirés du catalogue.
- `--all --prune=unpublish` : crée ou met à jour les contenus du catalogue, puis dépublie les contenus précédemment gérés mais absents du catalogue.
- `--all --prune=delete` : crée ou met à jour les contenus du catalogue, puis supprime définitivement les contenus précédemment gérés mais absents du catalogue. Cette option doit être interdite en production tant qu'un garde-fou explicite n'est pas ajouté.

Une commande ciblée par identifiant doit rester disponible :

```bash
drush emerging:content-sync agence-drupal-belgique --dry-run
drush emerging:content-sync agence-drupal-belgique
```

## Stratégie prudente pour la production

La future intégration dans `scripts/deploy-production.sh` ne doit pas être ajoutée dans ce ticket.

Stratégie recommandée par étapes :

1. Introduire le catalogue et le mapping sans intégration au déploiement.
2. Ajouter des tests automatisés sur le loader, le resolver, le dry-run et l'idempotence.
3. Exécuter `drush emerging:content-sync --all --dry-run` en local et en staging.
4. Ajouter une commande de rapport lisible dans la CI ou dans un workflow manuel, sans écriture.
5. Tester l'exécution réelle en staging avec sauvegarde de base.
6. N'autoriser en production que `--all` dans un premier temps, sans `--prune`.
7. Introduire `--prune=unpublish` uniquement après validation métier.
8. Garder `--prune=delete` hors déploiement automatique.

En production, le sync doit être exécuté après `drush updb -y` et `drush cim -y`, car il dépend du code et de la configuration à jour. Il doit échouer proprement avant remise hors maintenance si des conflits critiques sont détectés.

## Risques identifiés

- duplication de contenus si la résolution par alias ou titre est insuffisante ;
- écrasement de modifications éditoriales manuelles sur un contenu géré ;
- collision d'alias entre langues ou entre contenus ;
- rupture de traductions si la langue source ou les traductions existantes sont mal interprétées ;
- perte de paragraphes si le système remplace brutalement un champ de référence ;
- suppression involontaire si le prune est trop agressif ;
- modification indirecte des menus si l'historique est repris sans séparation ;
- dépendance aux UUID historiques de `default_content` ;
- divergence entre contenu YAML empaqueté et contenu réellement présent en base ;
- échec de déploiement si le sync n'a pas de dry-run exploitable ;
- difficulté de rollback si une exécution modifie trop d'entités à la fois.

## Garde-fous nécessaires

Avant toute automatisation de production, il faut mettre en place :

- un dry-run obligatoire et lisible ;
- un mapping persistant des contenus gérés ;
- une validation stricte du catalogue ;
- une détection de collisions d'alias ;
- une détection de doublons par identifiant métier ;
- une protection explicite des contenus non gérés ;
- une interdiction par défaut des suppressions définitives ;
- une option `--prune=delete` protégée par confirmation, variable d'environnement ou environnement non production ;
- des logs structurés par contenu et par action ;
- des tests d'idempotence ;
- des tests de non-modification des menus ;
- une sauvegarde de base avant toute exécution réelle en production ;
- une documentation de rollback.

## Ce qui doit être migré vers le Content Sync custom

À migrer progressivement :

- les pages stratégiques actuellement empaquetées par `default_content` : Accueil, Services, IA & Drupal, Cas clients, Contact ;
- les paragraphes rattachés à ces pages, avec identifiants métier et structure claire ;
- les pages légales créées par post-update : Mentions légales, Politique de cookies, Politique de confidentialité ;
- les contenus de type `service` stratégiques, dont `agence-drupal-belgique` déjà synchronisé ;
- les alias et champs SEO explicitement liés à ces contenus gérés.

## Ce qui ne doit jamais être automatisé par ce système

Le Content Sync global ne doit jamais automatiser :

- les contenus manuels non marqués comme gérés ;
- les menus et liens `menu_link_content` ;
- les suppressions définitives implicites ;
- les fichiers publics ou privés uploadés en production ;
- les webform submissions et données utilisateur ;
- les corrections globales de base de données ;
- les migrations Composer ;
- les opérations de configuration qui relèvent de `drush cim`.

## Position sur `default_content`

`default_content` doit être conservé temporairement.

Il ne doit pas être supprimé tant que :

- le module `emerging_digital_content` le déclare comme dépendance ;
- les contenus historiques ne sont pas tous représentés dans un catalogue custom ;
- les post-updates ou scripts locaux peuvent encore s'appuyer sur `default_content.importer` ;
- un ticket dédié n'a pas validé la désinstallation et le retrait Composer.

La cible reste bien son remplacement progressif pour les contenus éditoriaux stratégiques.

## Tickets suivants proposés

Tickets recommandés, dans cet ordre :

1. Créer le squelette du catalogue `content_sync/` et un validateur de schéma, sans écriture en base.
2. Ajouter une table de mapping des contenus gérés et les services de lecture/écriture associés.
3. Extraire `agence-drupal-belgique` depuis `ContentSyncManager::getDefinition()` vers le catalogue.
4. Ajouter `--all --dry-run` avec rapport détaillé et tests d'idempotence.
5. Migrer une première page `default_content` simple vers le catalogue, sans menus.
6. Migrer les paragraphes en sous-contenus versionnés.
7. Ajouter `--prune=unpublish` pour les contenus précédemment gérés.
8. Évaluer `--prune=delete` avec garde-fou fort, hors déploiement automatique.
9. Auditer puis retirer les post-updates historiques devenus obsolètes.
10. Retirer progressivement la dépendance `default_content` dans un ticket dédié, après validation staging.
11. Étudier une intégration production limitée à `drush emerging:content-sync --all`, précédée d'un dry-run et jamais avec suppression automatique.

## Conclusion

Le projet dispose déjà d'une première base saine avec `emerging:content-sync agence-drupal-belgique`, mais le système actuel est volontairement limité. Le remplacement de `default_content` doit passer par un catalogue versionné, un mapping persistant, un dry-run fiable et des règles de prune prudentes.

`default_content` doit rester en place temporairement. Les contenus stratégiques et légaux doivent être migrés progressivement vers le Content Sync custom. Les menus, les contenus manuels, les fichiers utilisateurs et les suppressions définitives ne doivent jamais être automatisés par défaut. Les prochains tickets doivent construire la mécanique étape par étape avant toute intégration au déploiement production.
