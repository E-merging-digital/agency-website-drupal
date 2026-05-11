# Ticket 75 - Audit `default_content` restant et nettoyage progressif

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/215

## Objectif

Ce document inventorie les mecanismes historiques lies a `default_content`
encore presents apres la migration progressive vers le Content Sync custom.

Le ticket ne supprime aucun ancien fichier de contenu, ne modifie aucun menu,
ne change pas `system.site:page.front`, ne modifie pas le script de
deploiement production et ne touche pas aux workflows GitHub Actions.

## Resume

Constats principaux :

- `default_content` reste une dependance structurelle du projet, mais le flux
  editorial actif passe maintenant par Content Sync pour les contenus migres ;
- le dossier historique
  `web/modules/custom/emerging_digital_content/content/` contient encore 5
  nodes et 29 paragraphes YAML ;
- le sous-dossier demande
  `web/modules/custom/emerging_digital_content/content/default_content/`
  n'existe pas ;
- le bloc `default_content:` de `emerging_digital_content.info.yml` declare
  encore 5 nodes et 27 paragraphes ;
- les 5 nodes historiques sont maintenant declares dans
  `content_sync/catalog.yml` avec leur `legacy_uuid` ;
- 26 des 29 paragraphes historiques sont repris par `legacy_uuid` dans les
  fichiers Content Sync ;
- les 3 paragraphes historiques non repris par `legacy_uuid` dans Content Sync
  sont `856cbeed-a7cd-430b-ba72-aca0b79b6022`,
  `fe7288c1-8611-4fc9-8b3e-fdb6219038d2` et
  `e8fcb813-8e1a-4739-a80b-f941b34040c7` ;
- le deploy production execute deja `drush emerging:content-sync --all` et ne
  reference pas `default_content` ;
- aucun retrait agressif n'est recommande dans ce ticket.

## Dependances historiques restantes

`default_content` reste present a plusieurs niveaux :

| Emplacement | Role actuel | Action recommandee |
| --- | --- | --- |
| `composer.json` | Requiert `drupal/default_content` avec `^2.0@alpha`. | Conserver jusqu'au ticket de retrait dedie. |
| `composer.lock` | Verrouille `drupal/default_content` en `2.0.0-beta1`. | Conserver tant que le module reste actif. |
| `config/sync/core.extension.yml` | Active le module `default_content`. | Ne pas retirer avant validation staging et prod. |
| `web/modules/custom/emerging_digital_content/emerging_digital_content.info.yml` | Declare la dependance `drupal:default_content` et le bloc `default_content:`. | Dernier element a nettoyer, apres suppression des imports historiques. |
| `web/modules/custom/emerging_digital_content/emerging_digital_content.install` | Purge les entites portant les UUID empaquetes avant import initial et cree la table de mapping Content Sync. | Conserver ; la purge doit etre retiree seulement avec l'ancien import. |
| `web/modules/custom/emerging_digital_content/emerging_digital_content.post_update.php` | Contient l'import `default_content.importer` et de nombreux correctifs historiques. | Voir section post-updates. |
| `web/modules/custom/emerging_digital_content/scripts/import_default_content.php` | Script local d'import manuel via `default_content.importer`. | Candidat a suppression apres retrait complet de `default_content`. |
| `web/modules/custom/emerging_digital_content/tests/src/Kernel/ContentSyncManagerTargetedWriteTest.php` | Charge `default_content` car le module custom en depend encore. | Retirer seulement quand la dependance du module disparait. |

Les references restantes dans `ContentSyncManager` sont des commentaires et une
compatibilite volontaire via `legacy_uuid` pour retrouver les entites migrees.
Elles ne declenchent pas `default_content.importer`.

## Inventaire des fichiers historiques

### Dossiers

| Chemin | Etat |
| --- | --- |
| `web/modules/custom/emerging_digital_content/content/node/` | 5 fichiers YAML historiques. |
| `web/modules/custom/emerging_digital_content/content/paragraph/` | 29 fichiers YAML historiques. |
| `web/modules/custom/emerging_digital_content/content/default_content/` | Absent. |
| `web/modules/custom/emerging_digital_content/content_sync/` | Catalogue Content Sync actif. |

### Nodes historiques

| UUID | Contenu | Alias historique | Etat Content Sync |
| --- | --- | --- | --- |
| `7b8d9926-e015-457f-9da3-562439b962a7` | Accueil | `/accueil` | Migre : `homepage`. |
| `d6e1876f-8e32-4f75-b6bb-946de5f5afdb` | Services | `/services` | Migre : `services`. |
| `3636da02-c8e6-40ea-aa71-54148f029a09` | IA & Drupal | `/ia-drupal` | Migre : `ia-drupal`. |
| `28345cad-8947-4f91-b77e-9251609a5bcc` | Cas clients | `/cas-clients` | Migre : `cas-clients`. |
| `d962ec05-25ec-4446-b645-0959a57964e7` | Contact | `/contact` | Migre : `contact`. |

Ces 5 UUID sont encore declares dans le bloc `default_content:` et sont aussi
utilises comme `legacy_uuid` dans `content_sync/catalog.yml`.

### Paragraphes historiques

Le dossier `content/paragraph/` contient 29 fichiers :

- 27 paragraphes sont declares dans le bloc `default_content:` ;
- 2 paragraphes sont presents dans le dossier et rattaches a Contact, mais ne
  sont pas declares dans `default_content:` :
  `fe7288c1-8611-4fc9-8b3e-fdb6219038d2` et
  `856cbeed-a7cd-430b-ba72-aca0b79b6022` ;
- 1 paragraphe est declare dans `default_content:`, mais n'est rattache a
  aucune node YAML historique :
  `e8fcb813-8e1a-4739-a80b-f941b34040c7`.

Content Sync reprend 26 paragraphes historiques par `legacy_uuid`. Les trois
ecarts a conserver dans l'audit sont :

| UUID | Etat historique | Etat Content Sync |
| --- | --- | --- |
| `fe7288c1-8611-4fc9-8b3e-fdb6219038d2` | Rattache a Contact, absent du bloc `default_content:`. | Non repris ; Contact utilise une definition plus recente. |
| `856cbeed-a7cd-430b-ba72-aca0b79b6022` | Rattache a Contact, absent du bloc `default_content:`. | Non repris ; Contact utilise une definition plus recente. |
| `e8fcb813-8e1a-4739-a80b-f941b34040c7` | Declare dans `default_content:`, non rattache aux nodes YAML. | Non repris ; candidat obsolete probable. |

Ces ecarts ne doivent pas etre corriges par suppression directe : ils doivent
etre confirmes sur une base staging avant tout retrait.

## Couverture Content Sync actuelle

`content_sync/catalog.yml` declare 9 contenus :

| Identifiant | Bundle | Alias FR | Alias EN | Origine |
| --- | --- | --- | --- | --- |
| `agence-drupal-belgique` | `service` | `/agence-drupal-belgique` | `/drupal-agency-belgium` | Nouveau contenu Content Sync. |
| `services` | `page` | `/services` | `/services` | Ancien node `default_content`. |
| `ia-drupal` | `page` | `/ia-drupal` | `/ai-drupal` | Ancien node `default_content`. |
| `cas-clients` | `page` | `/cas-clients` | `/case-studies` | Ancien node `default_content`. |
| `contact` | `page` | `/contact` | `/contact` | Ancien node `default_content`, avec composants repris/normalises. |
| `mentions-legales` | `page` | `/mentions-legales` | `/legal-notices` | Page legale creee historiquement par post-update. |
| `politique-confidentialite` | `page` | `/politique-de-confidentialite` | `/privacy-policy` | Page legale creee historiquement par post-update. |
| `politique-cookies` | `page` | `/politique-de-cookies` | `/cookie-policy` | Page legale creee historiquement par post-update. |
| `homepage` | `page` | `/accueil` | `/home` | Ancien node `default_content`. |

Les aliases FR/EN sont declares dans le catalogue et appliques par Content Sync.
Les menus restent explicitement hors scope du synchroniseur.

## Post-updates historiques

Les post-updates suivants sont des candidats a audit de suppression future, mais
pas a suppression immediate :

| Post-update | Risque si suppression prematuree |
| --- | --- |
| `emerging_digital_content_post_update_import_default_content()` | Import initial historique et remise a zero front page ; a retirer seulement quand `default_content` est retire. |
| `emerging_digital_content_post_update_main_navigation_links()` et variantes de cleanup/dedup | Touchent les menus ; a conserver tant que tous les environnements n'ont pas l'historique applique. |
| `emerging_digital_content_post_update_fix_multilingual_public_aliases()` | Corrige aliases/traductions ; suppression prematuree risquee pour environnements anciens. |
| `emerging_digital_content_post_update_contact_page_professional_layout()` et v2/v3/v4 | Genere la version live de Contact, dont certains UUID ne sont pas les anciens fichiers YAML. |
| `emerging_digital_content_post_update_legal_notice_and_footer_links()` | Cree/ajuste pages legales et liens footer. |
| `emerging_digital_content_post_update_cookie_policy_page()` | Cree/ajuste politique cookies et liens footer. |
| `emerging_digital_content_post_update_privacy_policy_and_contact_consent()` et rerun | Cree/ajuste confidentialite et consentements Contact. |
| `emerging_digital_content_post_update_backfill_strategic_cta_contact_links()` | Met a jour des CTA existants. |
| `emerging_digital_content_post_update_issue_81_editorial_repositioning_live()` et v2 | Repositionnement editorial historique. |
| `emerging_digital_content_post_update_issue_25_normalize_fr_source()` et v2 | Normalisation de langue source. |
| `emerging_digital_content_post_update_issue_25_front_page_system_path_v3()` | Corrige la front page ; ne pas modifier dans ce ticket. |
| `emerging_digital_content_post_update_issue_110_language_switcher_alignment()` | Ajuste langue, blocs et navigation multilingue. |

Regle de nettoyage : ne retirer un post-update que dans un ticket dedie, apres
confirmation que tous les environnements cibles l'ont execute ou qu'une
strategie d'installation neuve equivalente existe.

## Fichiers obsoletes potentiels

Potentiellement obsoletes, mais a conserver pour l'instant :

- `web/modules/custom/emerging_digital_content/content/node/*.yml`, car les 5
  nodes historiques sont couverts par Content Sync ;
- `web/modules/custom/emerging_digital_content/content/paragraph/*.yml`, car la
  majorite des paragraphes sont couverts par Content Sync, avec 3 ecarts a
  verifier avant suppression ;
- le bloc `default_content:` dans
  `web/modules/custom/emerging_digital_content/emerging_digital_content.info.yml` ;
- `web/modules/custom/emerging_digital_content/scripts/import_default_content.php` ;
- les sections de `docs/content/default-content.md` qui decrivent encore
  `default_content` comme flux d'initialisation principal.

Non obsoletes a ce stade :

- `legacy_uuid` dans Content Sync, qui protege les reprises idempotentes et les
  mappings persistants ;
- `emerging_digital_content_update_11001()`, qui installe la table de mapping ;
- les tests Content Sync qui verifient aliases, traductions, mappings et
  idempotence.

## Garde-fou ajoute

Le validateur Content Sync verifie maintenant que chaque `legacy_uuid` declare
dans le catalogue ou dans les composants :

- est une chaine UUID valide ;
- n'est pas reutilise par un autre contenu ou composant du catalogue.

Ce garde-fou protege les reprises depuis les UUID historiques, evite les
collisions de mapping et ne modifie aucun contenu Drupal.

## Strategie de nettoyage progressif

1. Conserver `default_content` tant que le dernier ticket de retrait n'est pas
   ouvert et valide en staging.
2. Continuer a faire de Content Sync la source editoriale active pour les
   contenus deja migres.
3. Verifier sur staging que les mappings existent pour les 9 contenus du
   catalogue et que les aliases FR/EN pointent vers les bons nodes.
4. Auditer les 3 paragraphes historiques non repris par `legacy_uuid` dans
   Content Sync avant toute suppression de fichier.
5. Deplacer la documentation `docs/content/default-content.md` vers un statut
   "historique" dans un ticket documentaire separe.
6. Dans un ticket dedie, retirer ou neutraliser le script
   `web/modules/custom/emerging_digital_content/scripts/import_default_content.php`
   apres validation que plus aucun runbook actif ne l'utilise.
7. Dans un autre ticket, retirer les post-updates historiques devenus inutiles,
   uniquement apres verification de l'etat `key_value` des post-updates sur les
   environnements cibles.
8. Retirer ensuite le bloc `default_content:` et la dependance
   `drupal:default_content` du module custom.
9. Retirer `default_content` de `core.extension.yml`, Composer et du lock dans
   un ticket final, avec validation complete Content Sync, aliases,
   traductions, mappings et menus.

## Validation locale

Commandes demandees :

```powershell
git diff --check
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync --all --dry-run
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```

Resultats :

| Commande | Resultat |
| --- | --- |
| `git diff --check` | OK. |
| `ddev drush emerging:content-sync:validate` | OK : 9 contenus trouves, 9 valides, 0 erreur, menus non touches. |
| `ddev drush emerging:content-sync --all --dry-run` | OK : 9 rapports de contenu, mappings existants, aucune ecriture, menus non touches. |
| `ddev composer lint:phpcs` | OK. |
| `ddev composer lint:phpstan` | OK : aucune erreur. |
| `ddev composer lint:drupal-check` | OK : aucune erreur. Avertissement existant sur le parametre `drupal_root` de phpstan-drupal. |

Verification complementaire :

| Commande | Resultat |
| --- | --- |
| `ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/emerging_digital_content/tests/src/Unit/ContentSyncCatalogValidatorTest.php` | OK : 1 test, 4 assertions. Avertissements non bloquants : repertoire `sites/simpletest/browser_output` non inscriptible et 1 deprecation PHPUnit. |
