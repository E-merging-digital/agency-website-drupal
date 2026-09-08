# Architecture SEO cible — état live

Issue de rebaseline : #1115  
Roadmap initiale : #286  
Rebaseline : 2026-09-08

## Principe directeur

L’architecture SEO doit élargir l’acquisition sans réduire Agency à Drupal.

```text
BUSINESS = portes d’entrée compréhensibles sans choix technologique préalable
EXPERTISE = profondeur Drupal / PHP / architecture / qualité
IA = usages utiles, encadrés et validés humainement
```

Une page business part du besoin. Une page expertise explique quand sa
technologie est pertinente. Une page IA cadre les limites, la confidentialité,
la validation humaine et la mesure.

## Gouvernance éditoriale actuelle

Les contenus ordinaires sont editor-owned dans Drupal.

```text
CONTENT_SYNC_FOR_NEW_ORDINARY_MARKETING_CONTENT = NO_BY_DEFAULT
PREPROD_RENDER = REQUIRED
HUMAN_APPROVAL = REQUIRED
FR_EN = DEFAULT
PROD_PUBLICATION = GOVERNED_SEPARATE_STEP
```

Les anciens champs de statut liés à la création via Content Sync ne sont plus
une instruction d’implémentation.

## Famille Business

| Page | Alias FR | Alias EN | Statut | Rôle |
| --- | --- | --- | --- | --- |
| Agence web Belgique | `/agence-web-belgique` | `/web-agency-belgium` | DELIVERED | Porte d’entrée agence web senior |
| Agence web Liège | `/agence-web-liege` | `/web-agency-liege` | DELIVERED | Acquisition locale |
| Création site web professionnel | `/creation-site-web-professionnel` | `/professional-website-creation` | DELIVERED | Besoin de création sans technologie imposée |
| Refonte site internet | `/refonte-site-internet` | `/website-redesign` | DELIVERED | Modernisation / continuité / SEO |
| Site web PME | `/site-web-pme` | `/sme-website` | DELIVERED | Besoins PME |
| Audit site web | `/audit-site-web` | `/website-audit` | NEXT_P1 | Diagnostic généraliste et orientation |
| Site web ASBL | `/site-web-asbl` | `/non-profit-website` | PLANNED_P1 | Accessibilité / équipe réduite / publication |
| Site web institutionnel | `/site-web-institutionnel` | `/institutional-website` | PLANNED_P1 | Gouvernance / multilingue / validation |
| Développement web sur mesure | `/developpement-web-sur-mesure` | `/custom-web-development` | PLANNED_P2 | Besoins applicatifs / API / intégrations |

## Famille Expertise Drupal / qualité

Ces pages restent des actifs structurants et doivent être reliées depuis les
portes d’entrée business lorsque le besoin devient technique.

| Page | Alias FR | Rôle |
| --- | --- | --- |
| Agence Drupal Belgique | `/agence-drupal-belgique` | Expertise Drupal senior |
| Création site Drupal | `/creation-site-drupal` | Création lorsque Drupal est pertinent |
| Refonte site Drupal | `/refonte-site-drupal` | Refonte d’un socle Drupal |
| Migration Drupal | `/migration-drupal` | Trajectoire de migration |
| Maintenance Drupal | `/maintenance-drupal` | Continuité et care |
| Audit Drupal | `/audit-drupal` | Audit technique approfondi |
| Accessibilité, SEO et optimisation | `/accessibilite-seo-optimisation` | Qualité web / accessibilité / performance |
| Drupal 2027 | `/drupal-2027` selon langue | Lifecycle / diagnostic / orientation |

Le funnel lifecycle existe déjà via #1007 / #1010 et alimente #1009.

## Famille PHP / Symfony / Laravel

| Page cible | Alias FR | Alias EN | Statut |
| --- | --- | --- | --- |
| Développement PHP sur mesure | `/developpement-php-sur-mesure` | `/custom-php-development` | PLANNED_P2 |
| Développement Symfony | `/developpement-symfony` | `/symfony-development` | PLANNED_P2 |
| Développement Laravel | `/developpement-laravel` | `/laravel-development` | PLANNED_P2 |
| Drupal / Symfony / Laravel : comment choisir | à cadrer | à cadrer | PLANNED_P2 |

Rôle : prouver une capacité d’arbitrage technique sans créer artificiellement
trois offres massives.

## Famille IA encadrée

| Page / guide | Alias FR | Alias EN | Statut |
| --- | --- | --- | --- |
| IA pour PME | `/ia-pour-pme` | `/ai-for-smes` | DELIVERED |
| IA intégrée | `/ia-integree` | `/integrated-ai` | DELIVERED |
| IA & Drupal | `/ia-drupal` | route EN existante à préserver | DELIVERED |
| Automatisation IA | `/automatisation-ia` | `/ai-automation` | PLANNED_P2 |
| Chatbot IA encadré | `/chatbot-ia` | `/ai-chatbot` | PLANNED_P2 |
| IA + SEO / GEO / LLM SEO | à cadrer | à cadrer | PLANNED_P2_P3 |

## Preuve commerciale Engineering / Infrastructure

Cette capacité n’est pas une nouvelle famille technologique : elle doit servir de
preuve transversale pour les pages business et Drupal.

Angles publics sûrs :

- PREPROD avant PROD ;
- CI et tests ;
- publication / déploiement contrôlé ;
- sauvegarde et rollback ;
- validation humaine ;
- anonymisation et séparation des données lorsque pertinent.

Le message doit traduire ces pratiques en réduction du risque et continuité
métier. Aucun secret ou détail d’exploitation sensible ne doit être publié.

## Maillage cible prioritaire

| Hub | Liens prioritaires |
| --- | --- |
| `/agence-web-belgique` | création, refonte, audit site web, développement sur mesure, IA pour PME, contact |
| `/creation-site-web-professionnel` | création Drupal, site PME, site ASBL, qualité web, contact |
| `/refonte-site-internet` | refonte Drupal, audit site web, audit Drupal, migration Drupal, contact |
| `/audit-site-web` | audit Drupal, qualité web, refonte, IA pour PME, contact |
| `/site-web-pme` | création, refonte, IA pour PME, maintenance, contact |
| `/site-web-asbl` | qualité web, création, IA utile, maintenance, contact |
| `/site-web-institutionnel` | audit Drupal, refonte Drupal, qualité web, maintenance, contact |
| `/developpement-web-sur-mesure` | PHP, Symfony, Laravel, Drupal, contact |
| `/ia-pour-pme` | IA intégrée, IA Drupal, automatisation, chatbot, contact |

Ne jamais créer un lien vers une route non publiée sans que le ticket courant
materialise aussi un chemin sûr et valide.

## Contenus de preuve / guides

Backlog restant à revalider avant matérialisation :

- Drupal ou WordPress pour PME/ASBL ;
- Drupal / Symfony / Laravel : comment choisir ;
- IA pour PME : cas utiles et limites ;
- SEO technique avant refonte ;
- Site ASBL accessible ;
- Préparer un audit site web exploitable ;
- LLM SEO / GEO pour Drupal.

La checklist refonte possède déjà #401 et ne doit pas être dupliquée sans
rechargement live.

## Prévention de cannibalisation

- `audit-site-web` = diagnostic généraliste ; `audit-drupal` = audit Drupal
  approfondi.
- `creation-site-web-professionnel` = besoin business ; `creation-site-drupal` =
  solution Drupal lorsque justifiée.
- `refonte-site-internet` = modernisation générale ; `refonte-site-drupal` =
  socle Drupal identifié.
- `developpement-web-sur-mesure` = besoin applicatif ; les pages PHP / Symfony /
  Laravel expliquent ensuite le choix d’architecture.
- `ia-pour-pme` = problèmes et usages ; `ia-drupal` / `ia-integree` = intégration
  technique contextualisée.

## Conversion

Le maillage doit pousser vers une action cohérente avec le niveau de maturité :

```text
INFORMATIONAL_CONTENT
-> RELEVANT_BUSINESS_PAGE
-> LOW_FRICTION_DIAGNOSTIC_OR_CONTACT
-> PAID_AUDIT_IF_JUSTIFIED
-> IMPLEMENTATION
-> MAINTENANCE_CARE
```

Ne pas pousser directement une migration ou une refonte lorsqu’un diagnostic
suffit.

## Ordre de matérialisation actuel

```text
1 = AUDIT_SITE_WEB_FR_EN
2 = ENGINEERING_INFRASTRUCTURE_PROOF_PUBLIC_SAFE
3 = SITE_WEB_ASBL_AND_INSTITUTIONNEL
4 = CUSTOM_WEB_PHP_SYMFONY_LARAVEL
5 = AI_AUTOMATION_CHATBOT_GEO
6 = ARTICLES_AND_LONG_TAIL
```

#1009 continue en parallèle au rythme des interactions humaines réelles. Les
premiers signaux commerciaux peuvent modifier cet ordre.