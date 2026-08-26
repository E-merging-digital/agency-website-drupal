# Backlog durable — audit externe et adoption de capacités

Status: **DURABLE_DISCOVERY_BACKLOG / NOT READY / NO IMPLEMENTATION AUTHORITY**.

Issue de conservation: **#846**.

Architecture de référence:

- `docs/decisions/ADR-003-agent-ready-drupal-capabilities.md`;
- `docs/agent-ready-trajectory.md`;
- `DESIGN.md` et le design system Agency lorsqu'ils sont applicables.

## 1. Pourquoi ce document existe

Ce document conserve durablement un programme de veille, de gap analysis et
d'adoption sélective de capacités externes potentiellement utiles à Agency.

Il ne constitue **ni une analyse live des upstreams, ni une roadmap active, ni une
autorisation d'implémentation**. Les projets, versions, licences, mainteneurs,
standards Drupal et risques cités ici devront être rechargés et réévalués au
moment de la reprise.

Le but est de rendre impossible l'oubli des questions et critères utiles sans
perturber la priorité opérationnelle actuelle.

```text
STATUS = DURABLE_DISCOVERY_BACKLOG
CURRENT_PRIORITY = PREPROD_TO_PROD_STABILIZATION
IMPLEMENTATION_AUTHORITY = NONE
READY_WORK_CREATED = NO
ISSUES_TO_CREATE_NOW = NONE
UPSTREAM_STATE = MUST_BE_RELOADED_LIVE_AT_REENTRY
```

Cette documentation n'autorise aucune modification de #816, #834, #842/#843 ou
d'un autre chemin PREPROD/PROD actif.

## 2. Critère directeur

La question n'est jamais:

> Peut-on ajouter cette technologie ?

La question est:

> Cette capacité améliore-t-elle suffisamment Agency pour justifier son coût,
> sa complexité, sa sécurité et sa maintenance, et est-ce le meilleur moyen
> disponible de l'obtenir ?

L'objectif est le maximum de valeur avec le minimum de code propriétaire et de
dépendances irréversibles.

Ordre de préférence durable:

```text
USE EXISTING AGENCY/DRUPAL CAPABILITY
        ↓
USE SAFE UPSTREAM
        ↓
ADAPT SMALL UPSTREAM COMPONENT
        ↓
REIMPLEMENT MINIMAL CAPABILITY
        ↓
REJECT
```

Pas de NIH par principe. Pas de dépendance externe par facilité.

À qualité et adéquation comparables:

```text
Drupal-native
> standard ouvert mature
> composant open source spécialisé
> petite adaptation interne
> développement propriétaire complet
```

## 3. Principes produit à préserver

Agency reste le site et la plateforme démontrant et exploitant le savoir-faire
E-merging Digital pour les besoins Web au sens large.

Le site public ne doit pas donner l'impression que l'entreprise ne fait que
Drupal. Les visiteurs non techniques doivent comprendre l'offre sans connaître
les choix techniques internes.

Drupal reste cependant une expertise différenciante pouvant bénéficier de
parcours, landing pages, contenus et campagnes spécifiques: migrations, upgrades,
legacy, versions non supportées, architecture, sécurité, performances,
maintenance, infrastructure, automatisation et IA appliquée à Drupal.

L'architecture privilégiée reste:

```text
Drupal
+ SDC / composants gouvernés
+ design system / DESIGN.md
+ primitives Drupal et Drupal AI lorsque pertinentes
+ contexte explicite gouverné lorsque pertinent
+ contenu structuré
+ automation gouvernée
```

Agency ne doit pas reconstruire un moteur propriétaire `prompt -> page` si les
primitives Drupal, SDC ou des composants ouverts bien conçus couvrent le besoin.

## 4. Candidats externes à recharger live lors de la reprise

Les références ci-dessous sont des **candidats d'audit**, pas des dépendances
acceptées.

### 4.1 Open Design

Candidat principal de design assisté:

`nexu-io/open-design`

Questions à réévaluer live:

- architecture local-first;
- utilisation d'agents de coding existants;
- compatibilité Codex / Claude / Cursor / autres;
- `DESIGN.md`;
- design systems et templates;
- UI skills;
- génération de prototypes, landing pages et dashboards;
- manipulation/génération d'images;
- export HTML;
- plugins, skills, manifests et adapters;
- séparation agent / design engine / artifact;
- possibilité de réutiliser une partie du tooling sans adopter l'application
  entière.

Question centrale:

> Open Design peut-il éviter à Agency de développer elle-même une partie
> importante de son tooling de conception assistée par IA ?

Options à comparer:

1. intégration directe;
2. outil externe de conception;
3. source de concepts/code;
4. adaptation de composants bornés;
5. aucune intégration.

Pipeline conceptuel à évaluer, sans présumer du runtime:

```text
brief
  ↓
brand
  ↓
DESIGN.md
  ↓
design system
  ↓
AI design proposal
  ↓
prototype
  ↓
mapping vers composants autorisés
  ↓
Drupal SDC
  ↓
Drupal structures/content
  ↓
preview
  ↓
validation
  ↓
publication gouvernée
```

### 4.2 Skills utiles à la croissance et à la qualité

Catalogues/candidats de recherche à recharger live:

- `ComposioHQ/awesome-claude-skills`;
- `github/awesome-copilot`;
- `wshobson/agents`;
- `addyosmani/agent-skills`;
- upstreams réels derrière chaque skill pertinent;
- alternatives plus récentes, plus sûres ou plus adaptées existant au moment de
  l'audit.

Capacités à examiner notamment:

- lead research;
- content research / source-driven writing;
- competitive ads research;
- brand guidelines;
- webapp/browser testing;
- visual testing;
- accessibility;
- frontend/UX;
- performance;
- SEO;
- security;
- observability;
- migration/deprecation/release quality;
- skill creation lorsqu'elle appartient réellement au bon projet.

Des noms historiques tels que `lead-research-assistant`,
`content-research-writer`, `competitive-ads-extractor`, `brand-guidelines`,
`webapp-testing` et `skill-creator` sont des points de départ de recherche, pas
des choix acceptés.

Le cycle produit potentiel reste:

```text
marché cible
  ↓
entreprises potentielles
  ↓
qualification
  ↓
technologies / situation
  ↓
problème probable
  ↓
opportunité
  ↓
angle commercial
  ↓
contenu pertinent
  ↓
landing page
  ↓
communication
  ↓
lead
  ↓
suivi
```

Zones commerciales envisagées en priorité lors de la phase d'acquisition:

1. Wallonie;
2. Bruxelles;
3. Luxembourg;
4. France.

Cette mécanique doit rester réutilisable pour d'autres offres Web, pas seulement
Drupal.

### 4.3 Naturalisation des contenus IA

Candidat de référence conceptuelle à recharger live:

`blader/humanizer`

Ne pas importer mécaniquement un humanizer anglophone.

Évaluer les concepts permettant de réduire les tics d'écriture IA tout en
préservant strictement:

- faits;
- noms;
- chiffres;
- citations;
- sens et niveau de certitude.

Défauts stylistiques potentiels à traiter:

- structures artificielles;
- répétitions;
- emphase excessive;
- titres inutiles;
- transitions mécaniques;
- langage marketing creux;
- faux ton conversationnel;
- formulations génériques.

Capacité éventuelle à évaluer, nom non contractuel:

`natural-french-writing`

Profils possibles:

```text
français naturel
français Belgique
article expert
article vulgarisé
page commerciale
landing page
LinkedIn
email professionnel
case study
communication institutionnelle
```

Invariant:

> La naturalisation ne peut jamais inventer, supprimer ou modifier un fait pour
> simplement rendre un texte plus humain.

Avant toute implémentation, vérifier Agency, Drupal AI, Prompt Manager, Preflight
et les capacités partagées existantes. Une capacité transverse de review,
rewriting ou conformité appartient préférentiellement à Preflight plutôt qu'à
une duplication Agency.

## 5. Recherche au-delà des candidats nommés

La reprise du chantier ne doit jamais se limiter aux repositories cités.

Pour chaque besoin, rechercher live:

- Drupal core;
- modules Drupal contrib;
- Drupal AI et initiatives associées;
- Drupal Canvas et primitives successeures;
- Context Control Center ou mécanismes successeurs;
- SDC et outils associés;
- standards émergents;
- projets open source spécialisés plus matures;
- API ou bibliothèques mieux adaptées.

Les conclusions d'août 2026 ne sont pas autoritatives au moment de la reprise.

## 6. Supply chain et sécurité

Les Agent Skills, prompts opératoires, plugins et adapters doivent être traités
comme une supply chain potentiellement hostile, même lorsqu'ils sont décrits dans
un fichier Markdown.

Pour tout composant envisagé, l'audit doit couvrir au minimum:

```text
LICENSE
MAINTAINER
LAST_ACTIVITY
RELEASE_MODEL
DEPENDENCIES
NETWORK_ACCESS
FILESYSTEM_ACCESS
PROCESS_EXECUTION
SECRET_ACCESS
EXTERNAL_SERVICES
TELEMETRY
DATA_EXFILTRATION_RISK
PRIVILEGE_REQUIREMENTS
UPDATE_RISK
PROMPT_INJECTION_SURFACE
TRANSITIVE_DEPENDENCIES
```

Pas de `curl | bash` ni d'installation distante non épinglée dans un workflow
gouverné.

Toute réutilisation doit pouvoir être:

- versionnée;
- pinnée;
- auditée;
- reproductible;
- supprimée;
- mise à jour explicitement.

## 7. Licences et droit de copie

Avant toute copie de code, déterminer réellement pour l'upstream et les fichiers
concernés:

```text
LICENSE
COPY_ALLOWED
MODIFICATION_ALLOWED
ATTRIBUTION_REQUIRED
NOTICE_REQUIRED
REDISTRIBUTION_CONDITIONS
COMPATIBILITY_WITH_AGENCY
```

Une licence annoncée à la racine ne dispense pas de vérifier les composants
vendored ou dépendances externes individuellement.

Si une capacité est utile mais que la copie est juridiquement ou techniquement
peu souhaitable, réimplémenter le **concept**, pas le code.

## 8. Gap analysis obligatoire avant roadmap

Avant de créer une issue d'implémentation, confronter les capacités externes à
l'état live réel d'Agency et de Drupal.

Vérifier réellement:

- code Agency;
- modules custom;
- Composer;
- configuration Drupal;
- documentation et ADR;
- `DESIGN.md`;
- design system et SDC;
- roadmap/backlogs;
- issues ouvertes/fermées;
- PR ouvertes/mergées;
- décisions acceptées.

Tableau minimal:

| Capability externe | Besoin Agency | Agency possède déjà ? | Drupal possède déjà ? | Upstream mature ? | Valeur | Risque | Décision |
| --- | --- | --- | --- | --- | --- | --- | --- |
| À remplir lors de l'audit live |  |  |  |  |  |  |  |

Décisions autorisées:

```text
ALREADY_HAVE
ADOPT_UPSTREAM
ADAPT_UPSTREAM
COPY_BOUNDED_COMPONENT
REIMPLEMENT_CONCEPT
DEFER
REJECT
```

Une nouvelle issue ne doit pas être créée pour une capacité déjà implémentée,
couverte par une issue existante, prévue sous un autre nom ou rendue inutile par
une primitive Drupal existante.

## 9. Frontière Agency / Preflight / ForgePilot

### Agency

Possède préférentiellement:

- expérience Web et site public;
- composants et design system;
- contenu et pages;
- génération/assemblage UI gouverné;
- capacités marketing/business lorsqu'elles appartiennent au produit Agency.

### Preflight

Possède préférentiellement:

- review;
- rewriting gouverné;
- conformité;
- vérification;
- contrôles;
- naturalisation générique si elle devient transverse.

### ForgePilot

Possède préférentiellement:

- orchestration;
- capabilities et skills comme mécanismes d'exécution;
- execution governance;
- agents;
- provenance;
- permissions;
- evidence;
- gates;
- adapters d'orchestration.

Invariant:

```text
ForgePilot governs execution.
Agency owns/consumes product capabilities.
```

Une capacité utile à Agency ne doit pas nécessairement être implémentée dans
Agency. Déterminer l'owner réel à partir du besoin live.

Valeurs possibles:

```text
OWNER = AGENCY | PREFLIGHT | FORGEPILOT | SHARED_CONTRACT
```

## 10. Open Design — forme du seul POC envisagé si l'audit reste positif

Ne jamais passer directement de discovery à intégration générale.

Question unique d'un éventuel POC:

> Peut-on partir de la design authority d'Agency et produire une proposition de
> page réellement exploitable comme composition de composants Drupal SDC
> gouvernés ?

Exemple de slice, uniquement après autorisation future:

```text
Agency DESIGN.md
+ tokens
+ brand rules
+ catalogue SDC autorisé
+ brief landing page
        ↓
Open Design ou alternative retenue
        ↓
prototype structuré
        ↓
mapping déterministe
        ↓
composants SDC
        ↓
preview Drupal
```

Critères possibles:

- respect réel du design system;
- aucune invention de composant interdit;
- mapping compréhensible;
- responsive;
- accessible;
- éditable dans Drupal;
- pas de HTML monolithique ingérable;
- reproductible;
- compatible avec le workflow Agency;
- avantage mesurable par rapport à l'approche existante.

Un POC réussi ne devient pas production automatiquement.

## 11. Content engine — architecture à évaluer

Pipeline cible à réévaluer quand le moment sera venu:

```text
RESEARCH
  ↓
SOURCE
  ↓
DRAFT
  ↓
FACT CHECK
  ↓
BRAND CHECK
  ↓
NATURAL LANGUAGE PASS
  ↓
SEO / STRUCTURE
  ↓
DRUPAL STRUCTURED CONTENT
  ↓
PREVIEW
  ↓
HUMAN REVIEW
  ↓
PUBLISH
```

Les faits importants doivent conserver leur provenance. Un modèle IA n'est pas
une source factuelle implicite.

Le pipeline doit pouvoir servir articles, landing pages, études de cas, pages
service, LinkedIn, newsletters, campagnes et emails commerciaux.

Réutiliser Preflight pour les contrôles transverses de review/compliance au lieu
de les dupliquer.

## 12. Prospection — automation n'est pas spam

Une future capacité de lead research peut préparer des données structurées telles
que:

```text
COMPANY
WEBSITE
SECTOR
LOCATION
TECHNOLOGY_SIGNALS
MIGRATION_SIGNALS
BUSINESS_NEED_EVIDENCE
FIT_SCORE
CONTACT_HYPOTHESIS
PERSONALIZATION_ANGLE
CONTENT_MATCH
```

Toute campagne réelle doit être séparément gouvernée et respecter au moment de
l'exécution les règles applicables: RGPD, ePrivacy, droit belge, droit des marchés
ciblés, provenance des données, minimisation, opt-out, anti-spam et politique
Agency.

Ce backlog n'autorise aucun envoi réel.

## 13. Mesure de valeur

Toute capability retenue doit avoir un résultat mesurable et utile.

Exemples à réévaluer:

Open Design / design assisté:

```text
time_to_prototype
manual_corrections
design_system_violations
component_reuse_rate
```

Content:

```text
time_to_first_draft
fact_corrections
human_rewrite_rate
publication_rate
```

Prospection:

```text
qualified_leads
human_acceptance_rate
response_rate
conversion_rate
```

Natural writing:

```text
human_rewrite_delta
factual_preservation
style_acceptance
```

Ne pas retenir de métriques artificielles ou impossibles à mesurer correctement.

## 14. Séquence durable de reprise

Ce backlog est subordonné aux gates opérationnels existants.

Séquence de principe:

```text
PREPROD -> PROD stabilization
        ↓
PROD healthy with evidence
        ↓
bounded Agent-Ready / SDC read-only trajectory
        ↓
external capability discovery when product-relevant
        ↓
live upstream + Drupal gap analysis
        ↓
license + supply-chain audit
        ↓
ownership decision
        ↓
ONE bounded POC if justified
        ↓
evidence
        ↓
productization decision
        ↓
content / SEO / landing / technical proof
        ↓
commercial acquisition
```

Ce schéma n'impose pas d'attendre la totalité d'une architecture Agent-Ready
théorique avant tout travail commercial. Il impose que toute reprise soit
rebaselinée par le Project Lead contre les priorités et dépendances live, et que
ce backlog ne puisse jamais préempter une stabilisation opérationnelle active.

## 15. Re-entry checklist

Avant toute analyse ou issue dérivée de ce document:

1. reload live GitHub et repository Agency;
2. reload les upstreams et leurs licences/maintenance;
3. vérifier Drupal core/contrib/SDC/Drupal AI/Canvas/context primitives actuels;
4. revalider ADR-001 et ADR-003;
5. vérifier l'état réel des capacités Agency, Preflight et ForgePilot;
6. produire le gap analysis avant toute roadmap;
7. attribuer l'owner architectural;
8. classer valeur / coût / risque / dépendances;
9. créer au maximum **un** premier slice borné si le Project Lead l'autorise;
10. conserver `one issue = one branch = one PR`;
11. aucun runtime ou effet externe sans gate spécifique.

Priorités possibles lors de la future analyse:

```text
P0 = nécessaire maintenant
P1 = forte valeur prochainement
P2 = utile ensuite
P3 = veille
REJECT = inutile / mauvais fit
```

Le simple fait d'être présent dans ce document ne confère **aucune priorité**.

## 16. Mutation boundary de ce backlog

Par défaut, ce document signifie:

```text
OPEN_DESIGN_RUNTIME = NOT_AUTHORIZED
SKILL_INSTALLATION = NOT_AUTHORIZED
MCP_WEBMCP_CHANGE = NOT_AUTHORIZED
COMPOSER_CHANGE = NOT_AUTHORIZED
PREPROD_MUTATION = NOT_AUTHORIZED
PROD_MUTATION = NOT_AUTHORIZED
COMMERCIAL_SEND = NOT_AUTHORIZED
```

Il existe pour conserver une direction de recherche, pas pour contourner les
gates Project Lead ou transformer une veille en développement automatique.
