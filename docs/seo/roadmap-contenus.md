# Roadmap contenus SEO et acquisition

Issue de rebaseline : #1115  
Parent : #4 — Contenu, acquisition & market positioning  
Roadmap initiale : #286 — Ticket 110  
Rebaseline : 2026-09-08

## Objectif

Cette roadmap décrit la file de travail commerciale et éditoriale réellement
utile après les livraisons réalisées depuis mai 2026.

Elle ne doit pas servir à recréer des contenus déjà livrés ni à réintroduire les
anciens mécanismes Content Sync pour les contenus ordinaires.

```text
PRIMARY_GOAL = QUALIFIED_LEADS_AND_REVENUE
AGENCY_POSITIONING = SOLUTIONS_NUMERIQUES + INGENIERIE_WEB
DRUPAL = EXPERT_ACQUISITION_VERTICAL
WALLONIE_BRUXELLES = P1
LUXEMBOURG = LATER_P2
FRANCE_TARGETED = LATER_P3
USE_EXISTING_FIRST = REQUIRED
MINIMUM_NECESSARY = REQUIRED
```

## Modèle éditorial actuel

`AGENTS.md` est autoritatif.

Les contenus ordinaires historiquement pilotés par Content Sync ont été libérés
et sont désormais editor-owned dans Drupal. Pour une nouvelle page commerciale,
un article ou une mise à jour éditoriale ordinaire :

```text
NEW_ORDINARY_CONTENT_IN_CONTENT_SYNC = NO_BY_DEFAULT
EDITOR_OWNED_DRUPAL = DEFAULT
FR_EN = DEFAULT_FOR_PUBLIC_COMMERCIAL_CONTENT
PREPROD_RENDER_REQUIRED_BEFORE_PROD = REQUIRED
HUMAN_APPROVAL_REQUIRED_BEFORE_PROD = REQUIRED
AGENT_SELF_APPROVAL = FORBIDDEN
```

Toute nouvelle admission dans Governed Content exige un besoin séparé et
explicitement justifié. Les anciens exemples basés sur `catalog.yml`, payloads
Content Sync et `emerging:content-sync` ne sont plus le modèle par défaut.

## Livraisons déjà réalisées

Ne pas recréer ces tranches.

| Capacité / contenu | État | Référence |
| --- | --- | --- |
| Audit SEO stratégique et positionnement | DELIVERED | #286 |
| Homepage repositionnée business / IA / expertise | DELIVERED | #288 |
| Agence web Belgique FR/EN | DELIVERED | #290 |
| Agence web Liège FR/EN | DELIVERED | #290 |
| Création site web professionnel FR/EN | DELIVERED | #290 |
| Refonte site internet FR/EN | DELIVERED | #290 |
| Site web PME FR/EN | DELIVERED | #290 |
| IA pour PME FR/EN | DELIVERED | #290 |
| Page Services : hiérarchie / CTA | DELIVERED | #291 |
| Cas clients : repositionnement business | DELIVERED | #293 |
| Drupal Lifecycle Diagnostic MVP | DELIVERED | #1007 |
| Landing Drupal 2027 + parcours diagnostic | DELIVERED | #1010 |
| Master email Drupal 2027 FR/EN | DELIVERED | Brand #30/#31 |
| Master HTML email brandé accessible | DELIVERED | Brand #32/#33 |
| Première activation commerciale Wallonie / Bruxelles | HUMAN_GATE | #1009 |

## Travail actif indépendant du gate humain #1009

#1009 attend une première interaction réelle IFAPME, puis EVS, puis le parcours
Innoviris event-led. Ce gate ne bloque pas le travail SEO, éditorial ou de preuve
commerciale qui ne nécessite aucun contact prospect.

```text
#1009_NEXT_OWNER = JONATHAN
#1009_NEXT_GATE = FIRST_REAL_HUMAN_INTERACTION_RESULT
AGENT_SEND = NO
ROADMAP_WORK_CAN_CONTINUE = YES
```

## P1 — prochaines tranches commerciales

### 1. Audit site web FR/EN

Alias cibles :

- FR : `/audit-site-web`
- EN : `/website-audit`

Rôle : créer une porte d’entrée généraliste avant le choix technologique et
orienter vers Drupal, refonte, performance, accessibilité, SEO ou IA selon le
besoin réel.

Le contenu doit distinguer clairement :

- diagnostic initial ;
- audit généraliste ;
- audit Drupal approfondi ;
- backlog priorisé ;
- décision de poursuivre ou non un chantier.

Liens prioritaires :

- `/audit-drupal`
- `/accessibilite-seo-optimisation`
- `/refonte-site-internet`
- `/ia-pour-pme`
- `/contact`

### 2. Preuve Engineering / Infrastructure publique et sûre

Transformer des pratiques déjà réelles en preuve commerciale compréhensible :

- PREPROD avant PROD ;
- tests et CI ;
- rollback / sauvegarde ;
- validation humaine ;
- déploiements contrôlés ;
- anonymisation / protection des données lorsque pertinent.

Interdits : secrets, hostnames privés non nécessaires, chemins exploitables,
credentials, détails de sécurité actionnables ou données client.

Objectif commercial : rassurer un décideur sur la maîtrise du risque, pas
publier un manuel d’exploitation.

### 3. Pages par public

#### Site web ASBL

- FR : `/site-web-asbl`
- EN : `/non-profit-website`

Angle : accessibilité, équipe éditoriale réduite, formulaires, événements,
clarté, maintenance et budget.

#### Site web institutionnel

- FR : `/site-web-institutionnel`
- EN : `/institutional-website`

Angle : gouvernance, multilingue, accessibilité, sécurité, validation,
continuité et durée de vie.

## P2 — élargir la preuve d’expertise au-delà de Drupal

### Cluster développement web / PHP

Ordre recommandé :

1. `/developpement-web-sur-mesure` / `/custom-web-development`
2. `/developpement-php-sur-mesure` / `/custom-php-development`
3. `/developpement-symfony` / `/symfony-development`
4. `/developpement-laravel` / `/laravel-development`
5. guide Drupal / Symfony / Laravel : comment choisir ?

Principes :

- partir du besoin métier ;
- montrer les critères d’arbitrage ;
- ne pas transformer une compétence mobilisable en fausse offre massive ;
- conserver Drupal comme expertise forte, pas comme réponse automatique.

### Cluster IA encadrée

Ordre recommandé :

1. `/automatisation-ia` / `/ai-automation`
2. `/chatbot-ia` / `/ai-chatbot`
3. guide IA + SEO / GEO / LLM SEO

Principes :

- diagnostic avant automatisation ;
- validation humaine ;
- RGPD / confidentialité ;
- pas de ROI garanti ;
- pas de discours gadget ;
- distinguer automatisation, assistant et chatbot.

## P3 — articles et preuves longue traîne

Backlog utile restant, à revalider avant chaque ticket :

1. Drupal ou WordPress pour une PME/ASBL : comment choisir ?
2. Drupal, Symfony ou Laravel : quelle base pour votre projet web ?
3. IA pour PME : cas utiles et limites à respecter
4. SEO technique avant refonte : les points à vérifier
5. Site web ASBL accessible : priorités avant design
6. Comment préparer un audit site web exploitable
7. LLM SEO / GEO : ce qui est utile pour un site Drupal

La checklist avant refonte possède déjà son chantier éditorial #401 ; ne pas
créer un doublon sans recharger son état live.

Chaque article doit :

- répondre à une intention réelle ;
- contenir une checklist, un tableau ou un cadre de décision utile ;
- lier vers une page transactionnelle pertinente ;
- éviter le remplissage marketing ;
- rester factuel et ne jamais inventer client, résultat ou métrique ;
- suivre le workflow PREPROD -> revue humaine -> PROD.

## WordPress — frontière commerciale

WordPress reste autorisé pour :

- audit ;
- reprise d’existant ;
- optimisation ;
- maintenance ;
- migration ;
- montée en maturité technique.

```text
WORDPRESS_GENERALIST_CREATION_OFFER = NO
LOW_COST_POSITIONING = NO
```

Les futurs contenus WordPress doivent ramener vers le besoin, l’audit et les
options d’architecture plutôt que vers une guerre de CMS.

## Mesure de conversion

Réutiliser GA4 / Google Tag existant. Ne pas créer une seconde stack analytics.

Priorité après premiers signaux commerciaux : mesurer uniquement les étapes
métier réellement utiles, sans PII :

```text
LANDING_ENGAGEMENT
DIAGNOSTIC_REQUEST
QUALIFIED_CONVERSATION
AUDIT_CONVERSATION
PAID_AUDIT
QUALIFIED_OPPORTUNITY
```

Ne pas inventer d’attribution lorsque le signal n’est pas prouvé.

## Expansion géographique

Seulement après apprentissage Wallonie / Bruxelles :

```text
P2_GEOGRAPHY = LUXEMBOURG
P3_GEOGRAPHY = FRANCE_TARGETED
FRANCE_FIRST = GRAND_EST + HAUTS_DE_FRANCE + ILE_DE_FRANCE_EVALUATION
MULTI_COUNTRY_SIMULTANEOUS = NO
```

## Template de futur ticket contenu

Un futur ticket doit au minimum définir :

```text
INTENTION =
AUDIENCE =
FR_ALIAS =
EN_ALIAS =
COMMERCIAL_ROLE =
INTERNAL_LINKS =
FACTUAL_SOURCES =
EDITORIAL_OWNER = DRUPAL
PREPROD_RENDER = REQUIRED
HUMAN_APPROVAL = REQUIRED
PROD_PUBLICATION = SEPARATE_GOVERNED_STEP
```

Contraintes par défaut :

- pas de menu sauf besoin explicite ;
- pas de changement `system.site:page.front` ;
- pas de Twig, workflow ou backend sans besoin matériel ;
- pas de réadmission Content Sync par habitude ;
- FR/EN par défaut ;
- maillage localisé ;
- responsive et accessibilité ;
- aucune donnée ou preuve inventée.

## Ordre Project Lead actuel

```text
ACTIVE = #1009 / HUMAN_GATE
NEXT_1 = AUDIT_SITE_WEB_FR_EN
NEXT_2 = ENGINEERING_INFRASTRUCTURE_PROOF_PUBLIC_SAFE
NEXT_3 = SITE_WEB_ASBL_AND_INSTITUTIONNEL
NEXT_4 = CUSTOM_WEB_PHP_SYMFONY_LARAVEL
NEXT_5 = AI_AUTOMATION_CHATBOT_GEO
NEXT_6 = ARTICLES_LONG_TAIL
LATER = CONVERSION_MEASUREMENT_REFINEMENT
LATER = LUXEMBOURG
LATER = FRANCE_TARGETED
```

La priorité peut évoluer avec les premiers retours commerciaux réels de #1009.
Un signal de marché réel prime sur l’ordre théorique de cette roadmap.