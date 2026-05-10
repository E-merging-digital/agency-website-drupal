# Ticket 68 - Audit post-merge avant migration `default_content`

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/201

## Objectif

Ce document audite l'état du projet après les tickets 60 à 67, avant toute
migration des contenus historiques `default_content` vers le système Content
Sync custom.

Ce ticket ne migre aucun contenu. Il ne supprime aucun fichier
`default_content`, ne modifie aucun menu, aucun script de déploiement, aucun
workflow GitHub Actions et aucune logique Content Sync.

## Résumé

Le projet est prêt pour un premier ticket de migration prudent, mais
`default_content` reste encore structurellement nécessaire.

Constats principaux :

- le dossier `web/modules/custom/emerging_digital_content/content/` contient
  encore 5 nodes historiques et 29 paragraphes YAML ;
- le bloc `default_content:` de `emerging_digital_content.info.yml` référence
  les 5 nodes et 27 paragraphes ;
- 2 paragraphes présents dans `content/paragraph/` ne sont pas déclarés dans
  `default_content:` mais sont rattachés à la page Contact ;
- 1 paragraphe déclaré dans `default_content:` n'est plus rattaché à une node ;
- le catalogue `content_sync/catalog.yml` contient actuellement un seul contenu
  géré : `agence-drupal-belgique` ;
- les scripts et post-updates historiques contiennent encore de la logique
  éditoriale, dont un import `default_content.importer` ;
- le prochain ticket recommandé doit migrer une seule page simple et ses
  paragraphes, sans menus ni prune.

## État Content Sync après merge

Les tickets 60 à 67 ont introduit la base technique attendue :

- catalogue YAML versionné dans `content_sync/catalog.yml` ;
- validation du catalogue via `emerging:content-sync:validate` ;
- table de mapping persistante `emerging_digital_content_sync_mapping` ;
- sync ciblée par identifiant métier ;
- sync globale `--all` ;
- prune prudent limité à `--prune=unpublish` ;
- garde-fous production pour le prune ;
- intégration de `drush emerging:content-sync --all` au déploiement.

Le catalogue actif ne déclare pour l'instant que :

| Identifiant | Type | Bundle | Alias FR | Alias EN |
| --- | --- | --- | --- | --- |
| `agence-drupal-belgique` | `node` | `service` | `/agence-drupal-belgique` | `/drupal-agency-belgium` |

Le système cible reste donc opérationnel, mais il ne couvre pas encore les pages
historiques empaquetées dans `default_content`.

## Dépendances restantes à `default_content`

`default_content` reste présent à plusieurs niveaux :

- `composer.json` requiert `drupal/default_content` avec la contrainte
  `^2.0@alpha` ;
- `composer.lock` installe `drupal/default_content` en version
  `2.0.0-beta1` ;
- `config/sync/core.extension.yml` active le module `default_content` ;
- `web/modules/custom/emerging_digital_content/emerging_digital_content.info.yml`
  déclare `drupal:default_content` ;
- le même fichier `.info.yml` contient encore le bloc `default_content:` avec
  les UUID historiques à importer ;
- `web/modules/custom/emerging_digital_content/emerging_digital_content.install`
  supprime les entités existantes portant les UUID empaquetés avant l'import
  automatique, via `_emerging_digital_content_purge_stale_entities()` ;
- `web/modules/custom/emerging_digital_content/emerging_digital_content.post_update.php`
  contient encore un post-update qui utilise `default_content.importer` ;
- `web/modules/custom/emerging_digital_content/scripts/import_default_content.php`
  utilise aussi `default_content.importer`.

Conclusion : le retrait de `default_content` est hors périmètre tant que les
contenus historiques et les mécanismes d'import n'ont pas été remplacés par des
entrées Content Sync validées et rejouables.

## Inventaire des nodes historiques

Les nodes encore présentes dans
`web/modules/custom/emerging_digital_content/content/node/` sont :

| UUID | Titre | Bundle | Alias | Paragraphes rattachés |
| --- | --- | --- | --- | --- |
| `7b8d9926-e015-457f-9da3-562439b962a7` | Accueil | `page` | `/accueil` | 7 |
| `d6e1876f-8e32-4f75-b6bb-946de5f5afdb` | Services | `page` | `/services` | 5 |
| `3636da02-c8e6-40ea-aa71-54148f029a09` | IA & Drupal | `page` | `/ia-drupal` | 6 |
| `28345cad-8947-4f91-b77e-9251609a5bcc` | Cas clients | `page` | `/cas-clients` | 4 |
| `d962ec05-25ec-4446-b645-0959a57964e7` | Contact | `page` | `/contact` | 6 |

Ces 5 nodes sont toutes listées dans le bloc `default_content:` du module.

## Inventaire des paragraphes historiques

Les paragraphes encore présents dans
`web/modules/custom/emerging_digital_content/content/paragraph/` sont :

| UUID | Bundle | Page rattachée | Libellé ou contenu repère |
| --- | --- | --- | --- |
| `3b376be4-852e-4fa2-85ba-a64ff0fefa9d` | `hero` | Accueil | Drupal pour projets structurés, accessibles et évolutifs |
| `ad8e2138-9355-4951-90dc-354e07847eca` | `text_block` | Accueil | Positionnement éditorial clair pour environnements exigeants |
| `efdbbfe1-aec4-4cbb-be3d-33f3fe5966ac` | `services` | Accueil | Nos services |
| `b73c293b-32b3-426d-a86b-adfed1a386a7` | `ai_features` | Accueil | Ce que l’IA peut faire dans Drupal |
| `614270dd-1d42-4ea9-a7ae-6321952de65b` | `case_clients` | Accueil | Des projets clairs, utiles et durables |
| `33873ed7-004d-4a7f-b69e-b8f64dc4fa9b` | `trust_list` | Accueil | Pourquoi travailler avec nous |
| `88bc3809-2c51-4ec1-93a8-2eed9ce6930c` | `cta` | Accueil | Vous avez un projet Drupal ou un site à moderniser ? |
| `50c9c0ef-85f8-49a3-819c-4aa63ddd1737` | `hero` | Services | Services Drupal pour projets structurés et institutionnels |
| `2eb8e4b6-37d0-47cc-8702-7a850dd94c15` | `text_block` | Services | Votre site doit rester clair, fiable et évolutif. |
| `11c3e2e1-78c9-491d-814c-b204bc2f5338` | `services` | Services | Nos services |
| `2f4f723a-90d5-4a95-84dc-333363d51655` | `text_block` | Services | Pourquoi Drupal pour des projets exigeants |
| `cfe7d994-e099-44b4-a0cc-12c69760288e` | `cta` | Services | Parlons de votre projet et identifions la solution la plus adaptée. |
| `bfa1a9d3-2715-4a04-8758-d14ece990e6e` | `hero` | IA & Drupal | IA utile dans Drupal pour les équipes éditoriales |
| `65be59ce-5a5e-44ec-9c63-b0796b0dd6f9` | `text_block` | IA & Drupal | L’IA ne remplace pas l’expertise métier. |
| `f7bb4bef-3172-4e6b-aadd-3adaaf5aeb29` | `ai_features` | IA & Drupal | Cas d’usage IA dans Drupal |
| `f6e32e97-aba4-4f5f-a8ce-89fb2ad2a83d` | `trust_list` | IA & Drupal | Bénéfices |
| `3d0518cf-cf7a-4cac-a2eb-ee80c1ba63f9` | `text_block` | IA & Drupal | Intégration dans vos processus CMS |
| `f4581518-8acc-4632-8b25-884776b3aeb4` | `cta` | IA & Drupal | Découvrez comment relier vos objectifs éditoriaux à des usages IA concrets. |
| `b878853f-1368-47be-bee9-8cdc1dba826a` | `hero` | Cas clients | Cas clients Drupal sur des contextes structurés |
| `25892a5a-3c09-4f64-86d7-cda8058e1875` | `text_block` | Cas clients | Chaque projet répond à des besoins réels. |
| `83435955-44bb-40df-8e8c-eda848d40301` | `case_clients` | Cas clients | Sans libellé `field_heading` |
| `d36b8734-7d9e-4782-a2e5-efa82d8ecfea` | `cta` | Cas clients | Vous avez un projet similaire ? |
| `dc4e80eb-e338-4dfb-a7b3-ce0f4e1eba92` | `hero` | Contact | Parlons de votre projet |
| `88affed2-d7e1-44bc-8607-28f0111fc123` | `text_block` | Contact | Intro |
| `8236e39f-2bcf-4758-a441-c9f6eb8f75f6` | `text_block` | Contact | Coordonnées |
| `fe7288c1-8611-4fc9-8b3e-fdb6219038d2` | `text_block` | Contact | Informations |
| `855b08da-0ec9-4261-883a-d27f214606e6` | `text_block` | Contact | Formulaire |
| `856cbeed-a7cd-430b-ba72-aca0b79b6022` | `text_block` | Contact | Carte |
| `e8fcb813-8e1a-4739-a80b-f941b34040c7` | `cta` | Non rattaché par les nodes YAML | Sans libellé `field_heading` |

Écarts à traiter lors de la migration :

- `fe7288c1-8611-4fc9-8b3e-fdb6219038d2` et
  `856cbeed-a7cd-430b-ba72-aca0b79b6022` sont utilisés par la node Contact,
  mais absents du bloc `default_content:` ;
- `e8fcb813-8e1a-4739-a80b-f941b34040c7` est présent dans
  `default_content:`, mais n'est rattaché à aucune node YAML auditée.

Ces écarts ne doivent pas être corrigés dans ce ticket. Ils doivent simplement
être pris en compte dans l'ordre de migration et dans les tests du prochain
ticket.

## Post-updates contenant encore de la logique éditoriale

Deux modules contiennent des post-updates éditoriaux ou assimilés.

Dans `web/modules/custom/emerging_digital_content/emerging_digital_content.post_update.php` :

- `emerging_digital_content_post_update_import_default_content()` importe le
  contenu historique avec `default_content.importer` ;
- `emerging_digital_content_post_update_main_navigation_links()` crée ou corrige
  des liens de menu ;
- `emerging_digital_content_post_update_main_navigation_home_link_cleanup()`
  nettoie des liens de menu ;
- `emerging_digital_content_post_update_main_navigation_deduplicate_home_link()`
  et ses variantes dédupliquent des liens de menu ;
- `emerging_digital_content_post_update_fix_multilingual_public_aliases()`
  corrige des alias et traductions ;
- `emerging_digital_content_post_update_contact_page_professional_layout()` et
  ses variantes créent ou réorganisent des paragraphes de la page Contact ;
- `emerging_digital_content_post_update_legal_notice_and_footer_links()` crée ou
  met à jour une page légale et des liens de footer ;
- `emerging_digital_content_post_update_cookie_policy_page()` crée ou met à jour
  la politique de cookies et des liens de footer ;
- `emerging_digital_content_post_update_privacy_policy_and_contact_consent()` et
  sa variante créent ou mettent à jour la politique de confidentialité et des
  textes de consentement ;
- `emerging_digital_content_post_update_backfill_strategic_cta_contact_links()`
  modifie des CTA existants ;
- `emerging_digital_content_post_update_issue_81_editorial_repositioning_live()`
  et sa variante appliquent un repositionnement éditorial ;
- `emerging_digital_content_post_update_issue_25_normalize_fr_source()` et sa
  variante normalisent la langue source ;
- `emerging_digital_content_post_update_issue_25_front_page_system_path_v3()`
  corrige la front page ;
- `emerging_digital_content_post_update_issue_110_language_switcher_alignment()`
  aligne langue, blocs et navigation multilingue.

Dans `web/modules/custom/agency_content_seed/agency_content_seed.post_update.php` :

- `agency_content_seed_post_update_frontpage_paragraphs()` crée ou met à jour la
  page d'accueil et ses paragraphes ;
- `agency_content_seed_post_update_issue_81_editorial_repositioning()` et sa
  variante contiennent aussi une logique de repositionnement éditorial.

Ces post-updates ont une valeur historique, mais ne doivent pas servir de modèle
pour la migration Content Sync. Les intentions éditoriales encore nécessaires
doivent être reprises sous forme de catalogue versionné, avec dry-run et mapping
persistant.

## Scripts utilisant encore `default_content.importer`

Deux emplacements utilisent encore explicitement `default_content.importer` :

- `web/modules/custom/emerging_digital_content/emerging_digital_content.post_update.php`
  dans `emerging_digital_content_post_update_import_default_content()` ;
- `web/modules/custom/emerging_digital_content/scripts/import_default_content.php`.

Le script local `import_default_content.php` appelle aussi
`_emerging_digital_content_purge_stale_entities()` avant l'import. Cette purge
supprime les entités portant les UUID empaquetés et doit rester hors du futur
flux Content Sync.

## Ordre de migration prudent recommandé

L'ordre recommandé est volontairement conservateur :

1. Migrer une seule page historique simple vers `content_sync/`, sans menus,
   sans prune et sans suppression de fichier `default_content`.
2. Commencer par la page Contact ou Cas clients, car elles sont isolées comme
   pages de type `page` et permettent de valider la modélisation de paragraphes.
3. Pour la page Contact, traiter explicitement les deux paragraphes rattachés
   mais absents du bloc `default_content:` : `fe7288c1-...` et `856cbeed-...`.
4. Pour Cas clients, valider le comportement du paragraphe `case_clients` sans
   `field_heading`.
5. Ajouter les identifiants métier de paragraphes dans le catalogue, sans
   reprendre les UUID historiques comme source de vérité.
6. Exécuter `emerging:content-sync:validate` puis `emerging:content-sync --all
   --dry-run` avant toute écriture.
7. Appliquer la sync uniquement en local ou staging, jamais avec prune dans le
   premier ticket de migration.
8. Migrer ensuite IA & Drupal, puis Services, car ces pages portent plus de
   relations éditoriales et de CTA croisés.
9. Migrer Accueil en dernier, car elle agrège davantage de composants et reçoit
   déjà des promotions depuis `agence-drupal-belgique`.
10. Après migration validée de toutes les pages historiques, ouvrir un ticket
   séparé pour auditer les post-updates devenus obsolètes.
11. Ouvrir seulement ensuite un ticket dédié au retrait progressif de
   `default_content` de Composer, de la configuration et du module custom.

## Prochain ticket recommandé

Le prochain ticket devrait être :

> Migrer la page Contact historique vers Content Sync, sans menus et sans prune.

Périmètre recommandé :

- ajouter une entrée `contact` dans `content_sync/catalog.yml` ;
- créer le fichier de contenu `content_sync/node/contact.yml` ;
- modéliser les paragraphes Contact avec des identifiants métier stables ;
- couvrir les paragraphes Intro, Coordonnées, Informations, Formulaire et Carte ;
- vérifier que les paragraphes absents du bloc `default_content:` sont bien
  représentés dans le catalogue cible ;
- ajouter ou ajuster les tests nécessaires au dry-run et à l'idempotence ;
- ne supprimer aucun fichier du dossier `content/` ;
- ne modifier aucun menu ;
- ne pas utiliser `--prune`.

Ce choix permet de tester le cas le plus utile pour l'audit : une page avec des
paragraphes actuellement divergents entre les fichiers YAML et le bloc
`default_content:`, sans toucher à la homepage ni aux mécanismes de promotion.

## Validation

Commandes recommandées pour ce ticket :

```powershell
git status
ddev drush cr
ddev drush updb -y
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync --all --dry-run
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```

Résultat attendu : l'audit doit rester documentaire. Les commandes Content Sync
peuvent lire le catalogue et la base, mais ce ticket ne doit introduire aucune
migration de contenu.

Résultats locaux :

| Commande | Résultat |
| --- | --- |
| `git status` | Branche `feature/ticket-68-default-content-migration-audit`, un seul fichier documentaire ajouté. |
| `ddev drush cr` | Succès. |
| `ddev drush updatedb:status` | Aucune mise à jour base de données requise. |
| `ddev drush updb -y` | Succès, aucune mise à jour en attente. |
| `ddev drush emerging:content-sync:validate` | Succès : 1 contenu trouvé, 1 valide, 0 erreur, menus non touchés. |
| `ddev drush emerging:content-sync --all --dry-run` | Succès : 1 contenu lu, mapping existant `node:9`, aucune écriture, menus non touchés. |
| `ddev composer lint:phpcs` | Succès. |
| `ddev composer lint:phpstan` | Succès : aucune erreur. |
| `ddev composer lint:drupal-check` | Succès : aucune erreur. Avertissement existant sur `drupal_root` déprécié dans la configuration phpstan-drupal. |
