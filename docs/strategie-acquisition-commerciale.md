# Stratégie d'acquisition commerciale Agency

Status: **DURABLE PRODUCT / GROWTH STRATEGY — NO AUTOMATIC CAMPAIGN OR RUNTIME AUTHORITY**.

Parent: **EPIC #4 — Contenu & acquisition**.

Issue de matérialisation: **#1001**.

Dernière revue Project Lead: **2026-09-04**.

## 1. Rôle de ce document

Ce document relie les décisions de positionnement, SEO, contenu, analytics et
engineering existantes en une stratégie d'acquisition mesurable.

Il ne remplace pas les autorités spécialisées existantes:

- `docs/strategie-positionnement.md` reste la référence pour le hero, la
  hiérarchie Drupal/PHP et le discours homepage;
- `docs/seo/strategie-seo.md`, `docs/seo/architecture-seo.md` et
  `docs/seo/roadmap-contenus.md` restent les références SEO/contenu;
- `docs/analytics.md` reste la référence d'intégration Google Tag / GA4;
- `docs/agent-ready-trajectory.md` et #863 restent les références pour le gate
  AI/Agent Readiness avant campagne significative;
- les documents `docs/operations/` restent autoritatifs pour PROD/PREPROD,
  déploiement et sécurité opérationnelle.

Ce document ajoute uniquement la couche transversale qui manquait:

```text
POSITIONING
+ CONTENT / SEO
+ ENGINEERING PROOF
+ DIAGNOSTIC / OFFER
+ CONVERSION MEASUREMENT
+ OUTBOUND
+ TERRITORY
= ACQUISITION SYSTEM
```

## 2. Baseline live — septembre 2026

Au moment de cette revue:

- la homepage est déjà orientée besoins et reste compréhensible sans connaître
  Drupal;
- Drupal est visible comme expertise principale, aux côtés du PHP sur mesure,
  Symfony, Laravel et autres capacités;
- les pages et contenus Drupal constituent déjà une verticale SEO exploitable;
- le système éditorial FR/EN et les routes de publication gouvernées existent;
- Google Tag / GA4 existe déjà via Drupal et Config Split, avec tracking désactivé
  hors PROD;
- PROD/PREPROD, CI, tests, promotion d'artifact et contrôles avant mutation sont
  des actifs réels du projet;
- #863 porte déjà le gate AI/Agent Readiness avant une campagne commerciale
  significative;
- #846 porte la roadmap Agent Experience / capacités externes et ne doit pas être
  transformé en roadmap marketing concurrente;
- #962 reste la priorité technique quand le runner requis est disponible.

Conséquence:

```text
MASSIVE_HOMEPAGE_REDESIGN = NOT_REQUIRED
NEW_ACQUISITION_PLATFORM = NOT_REQUIRED
REUSE_EXISTING_CONTENT_AND_ROUTES = REQUIRED
```

## 3. Invariant de positionnement

Agency n'est pas un « site Drupal ».

```text
AGENCY
= solutions numériques
+ ingénierie web
+ modernisation
+ intégrations
+ maintenance
+ infrastructure
+ automatisation / IA utile

DRUPAL
= expertise historique profonde
+ verticale d'acquisition experte
+ différenciation forte
+ porte d'entrée commerciale
```

Le visiteur non technique doit d'abord comprendre le problème que nous pouvons
résoudre. La technologie vient ensuite comme preuve et comme choix d'architecture.

Invariant:

> Agency vend des solutions numériques et de l'ingénierie web. Drupal est une
> verticale d'expertise et d'acquisition particulièrement forte, pas le plafond
> commercial d'E-merging Digital.

## 4. Homepage et information architecture

La homepage actuelle satisfait déjà l'orientation générale:

- création / refonte / modernisation;
- PHP sur mesure;
- maintenance;
- IA utile;
- SEO, accessibilité et performance;
- Drupal visible sans devenir l'unique promesse.

Donc:

```text
HOMEPAGE_NOW = PRESERVE
HOMEPAGE_BIG_REWRITE = NO
```

Une évolution de navigation future peut évaluer des regroupements du type
`Solutions`, `Expertise`, `Réalisations`, `Insights`, `À propos`, `Contact`, mais
**aucune arborescence n'est imposée par ce document**. L'existant doit être
réutilisé tant qu'il sert correctement les parcours.

La question n'est pas « pouvons-nous ajouter un menu Expertise ? », mais:

> un prospect trouve-t-il rapidement le parcours correspondant à son intention ?

## 5. Drupal lifecycle — snapshot officiel à revalider

Snapshot Drupal.org vérifié le 2026-09-04:

```text
DRUPAL_7_EOL = 2025-01-05
DRUPAL_8_EOL = 2021-11-17
DRUPAL_9_EOL = 2023-11-01
DRUPAL_10_LAST_MINOR = 10.6.x
DRUPAL_10_EOL = 2026-12-09
DRUPAL_12_PLANNED_RELEASE = WEEK_OF_2026-12-07
CURRENT_MODERNIZATION_LINE = DRUPAL_11
```

Source de vérité à recharger avant toute publication datée:

`https://www.drupal.org/about/core/policies/core-release-cycles/schedule`

Aucune campagne ne doit fabriquer une urgence artificielle.

Le discours doit expliquer:

- état de support réel;
- dette technique;
- compatibilité PHP / Composer / contrib / custom;
- dépréciations et Upgrade Status;
- trajectoire réaliste de modernisation;
- coût et risque de l'inaction;
- stratégie de test et déploiement.

Une migration sérieuse ne se résume pas à `composer update`.

## 6. Priorité géographique

Ordre durable:

```text
P1 = WALLONIE
P1 = BRUXELLES
P2 = LUXEMBOURG
P3 = FRANCE
```

Pour la France, la première exploration peut prioriser:

1. Grand Est;
2. Hauts-de-France;
3. Paris / Île-de-France.

L'élargissement dépend des résultats réels.

Mesurer séparément par territoire:

```text
qualified_accounts
conversations
requested_diagnostics
paid_audits
proposals
wins
care_contracts
```

Ne pas extrapoler un taux belge au Luxembourg ou à la France sans preuve.

## 7. Funnel Drupal cible

Le funnel commercial de référence est:

```text
SEARCH / CONTENT / LINKEDIN / EMAIL / ADS / DIRECT OUTREACH
        ↓
ARTICLE OU LANDING PAGE PERTINENTE
        ↓
DIAGNOSTIC FAIBLE FRICTION
        ↓
AUDIT PAYANT
        ↓
MIGRATION / MODERNISATION
        ↓
MAINTENANCE / CARE
        ↓
RELATION LONG TERME
```

Une campagne ne doit pas renvoyer mécaniquement vers la homepage.

Invariant:

```text
INTENT
→ CONTENT
→ LANDING
→ CTA
→ QUALIFICATION
```

## 8. Offre Drupal

### 8.1 Diagnostic initial

Première version volontairement simple.

Données possibles:

- URL;
- organisation;
- nom;
- email;
- besoin / commentaire.

```text
COMPLEX_AUTOMATED_SCANNER = NOT_FIRST_STEP
SEMI_MANUAL_DIAGNOSTIC = PREFERRED_MVP
```

Objectif: apprendre ce qui aide réellement un prospect avant d'automatiser.

### 8.2 Audit lifecycle / modernisation

L'audit payant peut couvrir selon besoin:

- Drupal Core;
- PHP;
- Composer;
- contrib;
- custom;
- thème;
- dépréciations;
- Upgrade Status;
- sécurité;
- analyse statique;
- tests;
- infrastructure;
- déploiement;
- PREPROD;
- estimation et plan d'action.

Le livrable doit être exploitable même si le client ne commande pas ensuite la
migration.

### 8.3 Migration / modernisation

Pas de prix unique artificiel.

Classer le besoin selon:

- version de départ;
- custom code;
- contrib;
- modèle de contenu;
- intégrations;
- volumétrie;
- infrastructure;
- exigences de continuité;
- niveau de tests existant;
- besoin de refonte fonctionnelle ou graphique.

### 8.4 Maintenance / Care

La migration doit autant que possible ouvrir une relation durable:

- updates et sécurité;
- monitoring;
- sauvegardes;
- tests;
- maintenance corrective;
- évolutions;
- préparation proactive aux versions futures;
- reporting;
- SLA lorsque pertinent.

## 9. Drupal comme verticale, pas comme homepage entière

Les pages Drupal existantes / prévues restent une verticale cohérente.

Les aliases exacts restent gouvernés par les documents SEO et la réalité Drupal;
ce document ne crée pas de nouvelle route.

La verticale doit couvrir les intentions telles que:

- agence / expertise Drupal;
- création / refonte;
- migration;
- audit;
- maintenance;
- infrastructure / exploitation;
- modernisation de versions;
- IA appliquée à Drupal lorsqu'elle apporte une valeur réelle.

Le prospect Drupal peut entrer directement par cette verticale sans que tout le
site public adopte un branding « agence Drupal ».

## 10. Architecture éditoriale

Le contenu est organisé par clusters, pas par volume de publication.

### A. Drupal & modernisation

Priorités typiques:

- Drupal 10: préparer l'EOL du 9 décembre 2026;
- migration Drupal 10 → 11;
- préparer une plateforme pour Drupal 12 sans promettre un chemin simpliste;
- Drupal 7/8/9/10: stratégie de modernisation;
- audit Drupal;
- dette technique;
- Composer / PHP / contrib / custom;
- maintenance et coût d'une migration.

### B. Engineering / infrastructure

Contenu utile aussi hors Drupal:

- rôle d'une vraie PREPROD;
- limites d'une simple CI verte;
- déploiement sûr;
- rollback;
- monitoring;
- environnements reproductibles;
- tests automatiques;
- isolation des environnements;
- données PROD → PREPROD avec sanitisation.

### C. Solutions numériques / décideurs

- quand moderniser une plateforme;
- CMS ou développement sur mesure;
- dette technique;
- coût total de possession;
- maintenance interne ou externe;
- choisir un partenaire technique;
- faire évoluer plutôt que reconstruire systématiquement.

### D. IA & automatisation

Seulement des usages réels:

- workflows;
- traitement documentaire;
- intégration IA dans une plateforme web;
- sécurité;
- gouvernance;
- validation humaine;
- agents lorsque leur valeur est prouvée.

```text
AI_WASHING = FORBIDDEN
```

## 11. Contenu = preuve

Un contenu expert doit rechercher au moins une combinaison de:

- expérience réelle;
- décision réelle;
- problème réel;
- solution réelle;
- compromis;
- mesure réelle;
- test réel;
- limite réelle;
- source officielle.

Structure de preuve utile:

```text
PROBLÈME
→ HYPOTHÈSE
→ TEST
→ DÉFAUT OU CONTRAINTE
→ CORRECTION / DÉCISION
→ VALIDATION
→ ENSEIGNEMENT
```

Interdiction absolue d'inventer:

- client;
- projet;
- incident;
- métrique;
- résultat;
- témoignage;
- expérience.

## 12. Transformer l'ingénierie Agency en preuve commerciale

Les capacités réelles du projet constituent un actif commercial:

```text
DEVELOPMENT
→ AUTOMATED TESTS
→ REVIEW
→ PREPRODUCTION
→ VALIDATION
→ PRODUCTION
→ MONITORING
```

Communication publique: traduire la pratique technique en bénéfice.

Exemples acceptables:

- « les changements sont validés avant la production »;
- « le même candidat est vérifié avant promotion »;
- « les environnements de validation isolent les effets externes »;
- « les migrations sont testées avant activation »;
- « rollback et contrôles post-déploiement font partie du processus ».

Ne jamais publier:

- secrets;
- tokens;
- credentials;
- IP privées;
- hostnames sensibles;
- commandes permettant un accès privilégié;
- données personnelles;
- architecture d'attaque exploitable.

## 13. Système éditorial durable

Réutiliser le modèle éditorial et les routes existantes avant d'ajouter des
champs ou workflows.

Capacités à évaluer seulement si un contenu commercial réel les exige:

- titre / slug / résumé / corps;
- auteur / dates;
- image et social preview;
- catégorie / tags / cluster;
- title/meta/canonical/OpenGraph;
- structured data;
- sources;
- CTA;
- related content;
- FAQ si pertinente;
- FR/EN;
- statut éditorial.

L'IA peut aider la recherche, l'outline, la rédaction, le SEO, les liens, les
résumés et les variantes de diffusion. Elle ne possède pas l'autorité finale de
publication commerciale importante.

## 14. Analytics et mesure

La capacité GA4 / Google Tag existe déjà. Le gap commercial n'est donc pas
« installer des analytics », mais définir des événements métier utiles.

Candidats à cadrer avant implémentation:

```text
view_drupal_landing
request_diagnostic
request_audit
submit_contact
book_call
qualified_lead
proposal_created
won_opportunity
```

Les noms exacts seront décidés par le ticket d'implémentation analytics.

Mesurer un funnel plutôt qu'un volume de pages vues:

```text
SOURCE
→ LANDING
→ CTA
→ FORM / CONTACT
→ DIAGNOSTIC
→ AUDIT
→ PROPOSAL
→ SALE
→ CARE
```

Principes:

- privacy by design;
- minimisation;
- ne pas mettre de données personnelles inutiles dans les événements;
- ne pas inventer d'attribution commerciale parfaite;
- ne pas confondre trafic et acquisition.

## 15. Outbound pilote

Ne pas commencer par une campagne massive.

Première cohorte de travail:

```text
~150 comptes
territoire = Wallonie + Bruxelles
qualification = forte
contact = humain / contrôlé
objectif = apprendre
```

Modèle de données candidat, à réduire au nécessaire avant implémentation:

```text
company
domain
country
region
industry
company_size
drupal_detected
drupal_version_or_estimate
version_confidence
lifecycle_status
website_importance
technical_score
commercial_score
contact_method
last_contact
status
notes
```

Le score aide l'humain; il ne décide pas seul.

Signaux positifs possibles:

- territoire prioritaire;
- version EOL ou proche de l'EOL;
- Drupal confirmé;
- plateforme importante;
- multi-site;
- dette visible;
- investissement digital;
- complexité justifiant un partenaire senior.

Réduire la priorité si:

- micro-site sans enjeu;
- domaine abandonné;
- Drupal non confirmé;
- modernisation récente évidente;
- absence d'adéquation commerciale plausible.

## 16. Sources de prospection

Évaluer plusieurs sources au lieu de dépendre d'un fournisseur unique:

- données publiques;
- moteurs de recherche;
- annuaires d'entreprises;
- signaux technologiques publics;
- Wappalyzer / BuiltWith ou équivalents selon conditions d'usage;
- BCE / sources nationales appropriées;
- LinkedIn lorsque légalement et techniquement approprié;
- enrichment providers seulement si utile.

Toute source doit être évaluée pour:

- licence / conditions d'utilisation;
- provenance;
- qualité;
- fraîcheur;
- minimisation;
- droit de conservation;
- coût;
- dépendance fournisseur.

## 17. Privacy / conformité outbound

Aucune campagne n'est autorisée par ce document.

Avant toute prospection réelle, vérifier séparément pour Belgique, Luxembourg et
France:

- RGPD;
- ePrivacy et règles nationales de communications commerciales;
- base juridique;
- minimisation;
- provenance des coordonnées;
- information des personnes lorsque requise;
- opt-out;
- rétention;
- sécurité des données;
- droits des personnes.

```text
AUTOMATION != LEGAL_BASIS
PUBLIC_DATA != FREE_FOR_ANY_USE
```

Une validation juridique spécifique peut être nécessaire avant une campagne.

## 18. Première hypothèse de cohorte

L'hypothèse:

```text
150 comptes qualifiés
→ conversations
→ diagnostics
→ audits
→ migrations
→ maintenance
```

sert uniquement à construire le système de mesure.

Ce n'est pas une prévision financière.

Après chaque cohorte:

```text
MEASURE
→ LEARN
→ RECALIBRATE
```

## 19. Ordre de réalisation

### NOW

1. conserver la homepage actuelle tant qu'un défaut commercial mesuré ne justifie
   pas de changement;
2. graver la présente stratégie sous EPIC #4;
3. réutiliser les pages, contenus, routes éditoriales et GA4 déjà présents;
4. terminer/reprendre #962 lorsque son runner est disponible;
5. préparer les prochains travaux commerciaux sans lancer de campagne massive;
6. traiter #863 avant la prochaine campagne significative.

### NEXT

Première tranche produit recommandée:

**Drupal Lifecycle Diagnostic MVP**.

Contenu:

- réutiliser/améliorer les landing pages Drupal audit/migration existantes;
- aligner un CTA diagnostic simple;
- diagnostic semi-manuel;
- préciser l'audit payant et le chemin migration → care;
- ajouter seulement les événements de conversion nécessaires;
- vérifier le parcours FR/EN;
- utiliser un contenu Drupal lifecycle factuel et daté;
- aucune prospection massive dans cette tranche.

Puis:

- produire/renforcer un contenu Engineering/Infrastructure transformant les
  pratiques Agency en preuve commerciale sûre;
- préparer la première cohorte Wallonie/Bruxelles;
- exécuter le gate #863 avant campagne significative.

### LATER

- pilote outbound Wallonie/Bruxelles;
- Luxembourg après apprentissage belge;
- France après apprentissage, d'abord régions ciblées;
- automatisation de détection / scoring / enrichissement seulement si le pilote
  manuel prouve sa valeur;
- nurturing/reporting seulement après données réelles.

### NOT NOW

```text
MASSIVE_SITE_REDESIGN = NO
DRUPAL_ONLY_HOMEPAGE = NO
COMPLEX_DRUPAL_SCANNER = NO
50_GENERIC_AI_ARTICLES = NO
MASS_OUTBOUND = NO
MULTI_COUNTRY_LAUNCH_AT_ONCE = NO
RIGID_MIGRATION_PRICE = NO
PUBLIC_SENSITIVE_INFRA_DETAILS = NO
UNVERIFIED_CASE_STUDIES = NO
AUTOMATED_CONTACT_WITHOUT_COMPLIANCE = NO
```

## 20. Recommended first delivery slice

Après cette tranche documentaire, le premier slice commercial à implémenter doit
répondre à une question simple:

> Peut-on convertir une intention Drupal lifecycle réelle en demande qualifiée
> avec les capacités déjà présentes, sans construire de scanner ni de CRM
> propriétaire ?

Definition of Done conceptuelle:

```text
DRUPAL_LIFECYCLE_LANDING = CLEAR
DIAGNOSTIC_CTA = CLEAR
DIAGNOSTIC = SEMI_MANUAL
PAID_AUDIT_PATH = EXPLICIT
MIGRATION_TO_CARE_PATH = EXPLICIT
FR_EN = PASS
CONVERSION_EVENTS = MINIMUM_NECESSARY
AI_AGENT_READINESS_GATE = PASS_BEFORE_SIGNIFICANT_CAMPAIGN
NO_COMPLEX_SCANNER = YES
NO_MASS_OUTBOUND = YES
```

Si ce slice ne génère pas de signal commercial, ne pas automatiser davantage.

## 21. Anti-loop / anti-overengineering

```text
USE_EXISTING_FIRST = REQUIRED
MINIMUM_NECESSARY = REQUIRED
OBJECTIF_COMMERCIAL > COMPLETUDE_ARCHITECTURALE
PROOF_OF_DEMAND > AUTOMATION_FIRST
SEMI_MANUAL_BEFORE_SCANNER = REQUIRED
ONE_NEW_SLICE_AT_A_TIME = REQUIRED
NON_BLOCKING_WARNING = DO_NOT_CREATE_WORK_BY_DEFAULT
WHEN_REQUESTED_OUTCOME_IS_PROVEN = STOP
```

Ce document ne donne aucune autorité pour une campagne, une collecte de données,
une modification analytics runtime, une refonte homepage, un scanner ou une
mutation PROD/PREPROD.