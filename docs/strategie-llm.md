# Strategie LLM

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/317

Date : 2026-05-23

## Objectif

La strategie LLM du site sert a rendre les contenus publics importants plus
lisibles par les moteurs LLM et les agents d'exploration, sans ajouter de
chatbot, sans appel OpenAI, sans tracking et sans exposer de contenu qui n'est
pas destine a l'indexation.

Le fichier `/llms.txt` est un sommaire editorial public. Il ne remplace ni le
sitemap XML, ni les metatags, ni les donnees structurees. Il doit rester
coherent avec la strategie SEO, les aliases bilingues et les pages publiees.

## Implementation actuelle

- Le module contrib `drupal/llms_txt` est installe et actif.
- La route publique expose `/llms.txt` avec un contenu Markdown et un
  `Content-Type: text/markdown`.
- Le contenu principal est versionne dans `config/sync/llms_txt.settings.yml`.
- Une traduction de configuration anglaise existe dans
  `config/sync/language/en/llms_txt.settings.yml`.
- Le test fonctionnel
  `web/modules/custom/agency_project_tests/tests/src/Functional/LlmsTxtEndpointTest.php`
  verifie que l'endpoint public rend le Markdown configure.

Le module lit la configuration `llms_txt.settings:content`, remplace les tokens
Drupal et ajoute les sections `llms_txt_section` publiees si elles existent et
si leur acces est autorise. La strategie projet ne depend pas de generation IA.

## Coherence SEO

La configuration Simple Sitemap utilise `https://emergingdigital.be` comme
`base_url`, active le sitemap hreflang et conserve `skip_untranslated: true`.
Les bundles publics `node.page`, `node.service`, `node.case_client`,
`node.article` et `node.ai_feature` sont indexes par Simple Sitemap.

Les contenus geres par Content Sync declarent des aliases neutres dans le
catalogue, puis Drupal expose les URLs publiques avec prefixe de langue :

| Langue | Racine publique | Role |
| --- | --- | --- |
| FR | `/fr` | Racine publique francophone |
| EN | `/en` | Racine publique anglophone |

Le fichier `/llms.txt` reference donc les URLs publiques absolues avec `/fr` et
`/en`, tout en conservant le sitemap public comme source exhaustive pour les
pages indexables.

## Contextes LLM autorises

Les moteurs LLM peuvent utiliser comme contexte :

- le positionnement public de l'agence ;
- les pages services publiees ;
- les hubs publics `Services`, `IA & Drupal` et `Cas clients` ;
- les cas clients publics et anonymises lorsqu'ils sont publies ;
- les pages de contact publiques pour orienter une demande ;
- les contenus du sitemap public qui ne sont pas bloques par `robots.txt`.

Les themes autorises sont :

- Drupal durable et maintenable ;
- creation, refonte, migration, maintenance et audit Drupal ;
- performance, accessibilite, SEO technique et optimisation ;
- IA editoriale encadree dans Drupal ;
- accompagnement de PME, ASBL et institutions ;
- cas clients publics.

## Pages prioritaires

Les pages prioritaires dans `/llms.txt` sont volontairement selectives :

| Role | FR | EN |
| --- | --- | --- |
| Services | `/fr/services` | `/en/services` |
| Agence Drupal | `/fr/agence-drupal-belgique` | `/en/drupal-agency-belgium` |
| Creation Drupal | `/fr/creation-site-drupal` | `/en/drupal-website-creation` |
| Refonte Drupal | `/fr/refonte-site-drupal` | `/en/drupal-website-redesign` |
| Migration Drupal | `/fr/migration-drupal` | `/en/drupal-migration` |
| Maintenance Drupal | `/fr/maintenance-drupal` | `/en/drupal-maintenance` |
| Audit Drupal | `/fr/audit-drupal` | `/en/drupal-audit` |
| IA & Drupal | `/fr/ia-drupal` | `/en/ai-drupal` |
| IA integree | `/fr/ia-integree` | `/en/integrated-ai` |
| Qualite web | `/fr/accessibilite-seo-optimisation` | `/en/ai-accessibility-seo-optimization` |
| Cas clients | `/fr/cas-clients` | `/en/case-studies` |
| Contact | `/fr/contact` | `/en/contact` |

La homepage reste accessible via les racines publiques `/fr` et `/en`. Le
ticket ne modifie pas la homepage, ses aliases Content Sync ni
`system.site:page.front`.

## Contenus exclus

Les contenus suivants ne doivent pas etre utilises comme contexte LLM :

- pages non publiees, brouillons, revisions et apercus ;
- interfaces d'administration Drupal ;
- comptes utilisateur, formulaires internes, soumissions et confirmations ;
- resultats de recherche internes ;
- fichiers techniques, endpoints systeme, logs et pages de debug ;
- secrets, cles, tokens et informations non publiques ;
- donnees personnelles ;
- contenus absents du sitemap public ou bloques par `robots.txt` ;
- pages legales comme contexte commercial, sauf question specifique sur les
  conditions, la confidentialite ou les cookies.

## Regles de maintenance

- Mettre a jour `/llms.txt` uniquement quand une page publique strategique est
  ajoutee, renommee ou retiree.
- Conserver des URLs absolues avec le bon prefixe `/fr` ou `/en`.
- Ne pas lister de page non publiee ou hors sitemap public.
- Ne pas ajouter de contenu genere par IA sans validation humaine.
- Ne pas ajouter de chatbot, de tracking ou d'appel a une API LLM pour cette
  strategie.
- Verifier `git diff --check`, les linters projet et le test fonctionnel
  `/llms.txt` apres modification de la configuration.

## Limites

`/llms.txt` donne une orientation aux moteurs LLM, mais ne garantit pas qu'ils
respecteront toutes les consignes. La protection effective des contenus repose
sur les controles Drupal, `robots.txt`, le sitemap public, les statuts de
publication et les permissions.
