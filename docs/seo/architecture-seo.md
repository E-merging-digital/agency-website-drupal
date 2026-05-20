# Architecture SEO cible

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/284

Date : 2026-05-20

## Principe directeur

L'architecture cible doit elargir l'acquisition sans casser le socle existant.
Les pages Drupal restent importantes, mais elles doivent etre reliees a des hubs
plus comprehensibles pour des prospects non techniques :

- agence web ;
- creation/refonte ;
- site PME / ASBL / institution ;
- developpement web sur mesure ;
- PHP / Symfony / Laravel ;
- IA encadree ;
- qualite web : SEO, accessibilite, performance.

Cette architecture est editoriale. Elle ne demande aucune modification de menu
dans ce ticket.

## Etat actuel

Pages Content Sync importantes deja presentes :

| Page | Alias FR | Type | Role actuel |
| --- | --- | --- | --- |
| Accueil | `/accueil` | `page` | Positionnement Drupal, PME/ASBL, IA utile |
| Services | `/services` | `page` | Hub services Drupal |
| Agence Drupal Belgique | `/agence-drupal-belgique` | `service` | Page pilier Drupal |
| Creation site Drupal | `/creation-site-drupal` | `service` | Acquisition creation Drupal |
| Refonte site Drupal | `/refonte-site-drupal` | `service` | Acquisition refonte Drupal |
| Migration Drupal | `/migration-drupal` | `service` | Acquisition migration |
| Maintenance Drupal | `/maintenance-drupal` | `service` | Acquisition maintenance |
| Audit Drupal | `/audit-drupal` | `service` | Offre diagnostic |
| Accessibilite, SEO et optimisation | `/accessibilite-seo-optimisation` | `service` | Qualite web Drupal |
| IA integree | `/ia-integree` | `service` | Offre IA dans Drupal |
| IA & Drupal | `/ia-drupal` | `page` | Hub IA et cas d'usage |
| Cas clients | `/cas-clients` | `page` | Preuves et cas |
| Equipe | `/equipe` | `page` | Expertise humaine + IA |
| Contact | `/contact` | `page` | Conversion |

Constat : le socle Drupal est avance. Le manque principal concerne les portes
d'entree non Drupal.

## Arborescence recommandee

### Niveau 1 - Hubs business

| Page cible | Alias FR recommande | Alias EN recommande | Type recommande | Statut |
| --- | --- | --- | --- | --- |
| Agence web senior Belgique | `/agence-web-belgique` | `/web-agency-belgium` | `service` ou `page` | A creer |
| Creation site web professionnel | `/creation-site-web-professionnel` | `/professional-website-creation` | `service` | A creer |
| Refonte site internet | `/refonte-site-internet` | `/website-redesign` | `service` | A creer |
| Developpement web sur mesure | `/developpement-web-sur-mesure` | `/custom-web-development` | `service` | A creer |
| Audit site web | `/audit-site-web` | `/website-audit` | `service` | A creer |

Role : capter les recherches qui ne mentionnent pas encore Drupal.

### Niveau 2 - Publics

| Page cible | Alias FR recommande | Alias EN recommande | Type recommande | Statut |
| --- | --- | --- | --- | --- |
| Site web PME | `/site-web-pme` | `/sme-website` | `service` | A creer |
| Site web ASBL | `/site-web-asbl` | `/non-profit-website` | `service` | A creer |
| Site web institutionnel | `/site-web-institutionnel` | `/institutional-website` | `service` | A creer |

Role : qualifier les besoins, les contraintes et les CTA selon le public.

### Niveau 3 - Expertise Drupal existante

| Page | Alias FR | Action |
| --- | --- | --- |
| Agence Drupal Belgique | `/agence-drupal-belgique` | Renforcer comme page expertise, pas comme seule page pilier du site |
| Creation site Drupal | `/creation-site-drupal` | Relier depuis creation site web professionnel |
| Refonte site Drupal | `/refonte-site-drupal` | Relier depuis refonte site internet |
| Migration Drupal | `/migration-drupal` | Relier depuis audit site web et maintenance |
| Maintenance Drupal | `/maintenance-drupal` | Relier depuis site PME/ASBL/institution |
| Audit Drupal | `/audit-drupal` | Relier depuis audit site web et pages Drupal |

Role : convertir les prospects dont le besoin devient clairement Drupal.

### Niveau 4 - PHP, Symfony, Laravel

| Page cible | Alias FR recommande | Alias EN recommande | Type recommande | Statut |
| --- | --- | --- | --- | --- |
| Developpement PHP sur mesure | `/developpement-php-sur-mesure` | `/custom-php-development` | `service` | A creer |
| Developpement Symfony | `/developpement-symfony` | `/symfony-development` | `service` | A creer |
| Developpement Laravel | `/developpement-laravel` | `/laravel-development` | `service` | A creer |
| Drupal, Symfony ou Laravel | `/drupal-symfony-laravel` | `/drupal-symfony-laravel` | `article` ou `page` | A creer |

Role : prouver que l'agence sait arbitrer au-dela du CMS. Ces pages ne doivent
pas faire croire a trois offres massives si les preuves ne suivent pas. Elles
doivent mettre l'accent sur l'analyse du besoin, les integrations et la
maintenabilite.

### Niveau 5 - IA encadree

| Page cible | Alias FR recommande | Alias EN recommande | Type recommande | Statut |
| --- | --- | --- | --- | --- |
| IA pour PME | `/ia-pour-pme` | `/ai-for-smes` | `service` | A creer |
| Automatisation IA | `/automatisation-ia` | `/ai-automation` | `service` | A creer |
| Chatbot IA encadre | `/chatbot-ia` | `/ai-chatbot` | `service` ou `article` | A creer plus tard |
| IA et SEO | `/ia-seo` | `/ai-seo` | `article` ou `service` | A creer plus tard |
| LLM SEO / GEO | `/llm-seo-geo` | `/llm-seo-geo` | `article` ou `service` | A creer plus tard |

Role : capter la demande IA sans modifier le chatbot public ni promettre une
automatisation hors controle.

### Niveau 6 - Guides et articles

| Contenu | Format | Role |
| --- | --- | --- |
| Drupal ou WordPress pour une PME/ASBL | Article | Comparatif decisionnel |
| Drupal, Symfony ou Laravel : comment choisir | Article | Pont PHP/framework/CMS |
| Checklist avant refonte site internet | Article | Lead vers audit/refonte |
| IA pour PME : cas utiles et limites | Article | Preparer page IA pour PME |
| SEO technique avant refonte | Article | Lead vers audit/site web |
| Site ASBL accessible : priorites | Article | Lead vers site web ASBL |

## Maillage interne cible

### Regles generales

- Chaque page generaliste doit lier vers une page expertise technique.
- Chaque page technique doit lier vers une page business plus comprehensible.
- Chaque page public cible doit lier vers creation/refonte, maintenance, IA et
  contact.
- Les pages IA doivent toujours rappeler validation humaine, limites et cadre.
- Les pages comparatives doivent ramener vers audit ou contact, pas vers une
  conclusion unique forcee.

### Hubs et liens prioritaires

| Hub | Liens sortants prioritaires |
| --- | --- |
| `/agence-web-belgique` | `/creation-site-web-professionnel`, `/refonte-site-internet`, `/developpement-web-sur-mesure`, `/ia-pour-pme`, `/contact` |
| `/creation-site-web-professionnel` | `/creation-site-drupal`, `/site-web-pme`, `/site-web-asbl`, `/accessibilite-seo-optimisation`, `/contact` |
| `/refonte-site-internet` | `/refonte-site-drupal`, `/audit-site-web`, `/audit-drupal`, `/migration-drupal`, `/contact` |
| `/developpement-web-sur-mesure` | `/developpement-php-sur-mesure`, `/developpement-symfony`, `/developpement-laravel`, `/agence-drupal-belgique` |
| `/developpement-php-sur-mesure` | `/developpement-symfony`, `/developpement-laravel`, `/agence-drupal-belgique`, `/audit-site-web` |
| `/ia-pour-pme` | `/ia-integree`, `/ia-drupal`, `/automatisation-ia`, `/chatbot-ia`, `/contact` |
| `/site-web-asbl` | `/accessibilite-seo-optimisation`, `/creation-site-drupal`, `/ia-pour-pme`, `/maintenance-drupal` |
| `/site-web-institutionnel` | `/audit-drupal`, `/refonte-site-drupal`, `/accessibilite-seo-optimisation`, `/maintenance-drupal` |

### Liens entrants a ajouter dans les futurs contenus

Les futurs tickets Content Sync devront ajouter des liens depuis :

- `/services` vers les nouvelles pages generalistes uniquement si elles sont
  ajoutees comme services.
- `/agence-drupal-belgique` vers `/agence-web-belgique` et
  `/developpement-php-sur-mesure` lorsque ces pages existeront.
- `/ia-drupal` vers `/ia-pour-pme` et `/automatisation-ia` lorsque ces pages
  existeront.
- `/accessibilite-seo-optimisation` vers `/audit-site-web` lorsque la page
  existera.

Important : ne pas modifier les menus dans les tickets de contenu sauf demande
explicite.

## Choix de content type

Recommandation par type de page :

| Type de contenu SEO | Bundle recommande | Justification |
| --- | --- | --- |
| Landing page service commerciale | `service` | Meta description via `field_short_description`, Schema.org WebPage, sitemap, blueprint existant |
| Hub compose avec plusieurs sections | `page` | Paragraphes disponibles via `field_home_components` |
| Guide / comparatif / article | `article` si le type est pret, sinon ticket technique ou page dediee | Eviter de melanger articles et services |
| Cas d'usage IA detaille | `ai_feature` si lie a IA Drupal | Type deja prevu pour les fonctionnalites IA |

Pour la premiere vague, privilegier `service` afin de rester proche du blueprint
`agence-drupal-belgique`.

## Structure type d'une landing page `service`

Champs attendus :

- `field_short_description` : 150 a 170 caracteres environ, unique par langue.
- `field_detailed_description` : HTML structure avec H2, listes, CTA et liens.
- aliases FR/EN explicites dans `catalog.yml`.
- promotions vers `/services` uniquement si la page doit apparaitre dans la
  grille.
- promotion homepage seulement si la priorite commerciale est explicite.

Structure editoriale :

1. Introduction courte orientee probleme.
2. H2 "Quand cette approche est pertinente".
3. H2 "Ce que nous prenons en charge".
4. H2 "Notre methode".
5. H2 "Livrables ou resultats attendus".
6. Paragraphe "Limites / quand choisir autre chose".
7. 2 a 4 liens internes.
8. CTA vers `/contact`.

## Prevention de cannibalisation

| Risque | Prevention |
| --- | --- |
| `/creation-site-web-professionnel` concurrence `/creation-site-drupal` | La page generaliste explique le besoin ; la page Drupal detaille la solution CMS |
| `/refonte-site-internet` concurrence `/refonte-site-drupal` | La page generaliste parle audit, SEO, parcours ; la page Drupal parle migration, contenu et technique Drupal |
| `/ia-pour-pme` concurrence `/ia-drupal` | La page PME parle usages metier ; la page Drupal parle integration CMS |
| `/developpement-web-sur-mesure` concurrence `/developpement-php-sur-mesure` | La premiere parle besoin business ; la seconde parle socle technique PHP |
| `/agence-web-belgique` concurrence `/agence-drupal-belgique` | La premiere est porte d'entree ; la seconde est preuve d'expertise |

## Priorite architecturale

Ordre recommande :

1. Creer les portes generalistes : agence web, creation, refonte, audit site.
2. Creer les pages publics : PME, ASBL, institution.
3. Creer le cluster PHP : PHP sur mesure, Symfony, Laravel.
4. Creer le cluster IA generaliste : IA PME, automatisation IA.
5. Publier les guides comparatifs pour soutenir le maillage.
6. Ajuster les pages Drupal existantes pour pointer vers les nouveaux hubs.

Cette sequence evite de diluer le site trop vite et donne a chaque nouveau
contenu un role clair dans le cocon semantique.
