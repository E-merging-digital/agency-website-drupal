# Roadmap durable — capacités externes, Agent Experience et supply chain

Status: **DURABLE_ROADMAP / NO AUTOMATIC IMPLEMENTATION AUTHORITY**.

Issue de conservation: **#846**.

Dernière revue Project Lead: **2026-09-04**.

Architecture de référence:

- `docs/decisions/ADR-003-agent-ready-drupal-capabilities.md`;
- `docs/agent-ready-trajectory.md`;
- `DESIGN.md` et le design system Agency lorsqu'ils sont applicables.

## 1. But de ce document

Ce document conserve les décisions de veille, de gap analysis et d'adoption
sélective de capacités externes utiles à Agency.

Il ne transforme pas automatiquement une découverte en travail READY. Toute
implémentation reste issue-bound, rechargée live et explicitement autorisée.

```text
STATUS = DURABLE_ROADMAP
IMPLEMENTATION_AUTHORITY = NONE_BY_DEFAULT
UPSTREAM_STATE = MUST_BE_RELOADED_LIVE_AT_REENTRY
USE_EXISTING_FIRST = REQUIRED
MINIMUM_NECESSARY = REQUIRED
NEW_MEGA_FRAMEWORK = FORBIDDEN
```

Les versions, licences, mainteneurs et surfaces de confiance mentionnés ici sont
un snapshot daté. Ils doivent être revalidés avant toute mutation.

## 2. Critère directeur

La question n'est jamais:

> Peut-on ajouter cette technologie ?

La question est:

> Cette capacité améliore-t-elle suffisamment Agency pour justifier son coût,
> sa complexité, sa sécurité et sa maintenance, et est-ce le meilleur moyen
> disponible de l'obtenir ?

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

À qualité et adéquation comparables:

```text
Drupal-native
> standard ouvert mature
> composant open source spécialisé
> petite adaptation interne
> développement propriétaire complet
```

## 3. Convergence d'architecture acceptée

Agency doit évoluer vers deux consommateurs de première classe des mêmes contenus
et capacités Drupal autoritatifs:

```text
HUMAN_EXPERIENCE
+
AGENT_EXPERIENCE
```

`Agent Experience` ne signifie pas dupliquer le site pour les agents. Cela
signifie rendre les contenus, capacités, règles et preuves suffisamment
structurés pour être découverts et consommés par des agents sans leur donner une
autorité implicite.

Principes durables:

```text
DISCOVER_BEFORE_MUTATE = REQUIRED
GENERIC_DRUPAL_KNOWLEDGE != PROJECT_COMPATIBILITY
MODEL_CONTEXT = CACHE_NOT_AUTHORITY
PROJECT_INDEX = CACHE_NOT_AUTHORITY
CAPABILITY != AUTHORITY
ACCOUNT_IS_PART_OF_AUTHORITY = YES
LLMS_TXT != AUTHORITY
MODEL != POLICY_ENGINE
TRUSTED_RUNNER != TRUSTED_WORKLOAD
REMOTE_EXTENSION != TRUSTED_EXTENSION
UNKNOWN_EXECUTION_STATE -> RECONCILE_NOT_REPLAY
EXECUTION -> VALIDATION -> RECEIPT
```

Ne pas créer un policy engine, artifact framework, plan framework ou marketplace
abstraction générique uniquement pour encoder ces principes. Étendre d'abord les
primitives Agency existantes.

## 4. Primitives Agency à réutiliser

Les décisions de cette roadmap doivent converger vers l'existant:

- #863: AI / Agent Readiness, machine readability, `llms.txt`, offre commerciale;
- #390: Tool API / MCP / Outside AI, read-only first;
- #352: ForgePilot / Codex, orchestration et optimisation agentique;
- #387: contexte partagé gouverné;
- `AGENTS.md`;
- Tool API et `agency_dev_mcp` existants;
- dispatcher et workflows trusted existants;
- snapshots de capacités et discovery repository/runtime existants;
- candidates, evidence et receipts éditoriaux/deploy existants;
- #998/#962 comme précédent de séparation resolver trusted / writer gouverné.

## 5. Décisions externes — snapshot septembre 2026

### GPT-6 Astra

```text
VERDICT = INTEGRATE_PRINCIPLE / WATCH AVAILABILITY
ASTRA_READY = YES
ASTRA_DEPENDENT = NO
BETTER_MODEL != MORE_AUTHORITY
```

Agency doit pouvoir bénéficier de modèles plus capables sans dépendre d'un modèle
unique pour sa correction, sa gouvernance ou sa sécurité.

### Codex 0.153 — contexte, reconnexion et approbations

```text
CONTEXT_MANAGEMENT = P2_PILOT
RECONNECT_AND_HISTORY = EXTEND_EXISTING
GUARDIAN_PERSISTENCE = DEFENCE_IN_DEPTH
MCP_ACCOUNT_SCOPED_APPROVAL = INTEGRATE_PRINCIPLE
REMOTE_PLUGIN_MARKETPLACE = P3_WATCH
```

La gestion expérimentale du contexte est utile pour réduire les handoffs géants,
mais le contexte modèle reste un cache. GitHub et le repository restent la source
de vérité.

Une approbation MCP doit être interprétée au minimum comme liée à l'identité, au
compte et à la capacité concernée.

### DrupalClaw

```text
WHOLESALE_RUNTIME_ADOPTION = REJECT
DOCKER_SOCKET_AGENT_AUTHORITY = REJECT
SKILLS = P2_ADAPT_EXTERNAL
DRUPAL_STATUS_PATTERN = EXTEND_EXISTING
DRUPAL_INDEX_PATTERN = P2_PILOT
PLANS_AND_FLOWS = INSPIRATION_ONLY
MARKETPLACE_CURRENT_TRUST_MODEL = REJECT
```

DrupalClaw est une bonne source de patterns, skills et idées de discovery. Son
runtime ne doit pas être installé tel quel sur une surface Agency sensible si
cela implique une autorité équivalente au Docker host socket ou des pouvoirs
terminal/DB/Docker trop larges.

Le concept `drupal-index` peut être testé pour les recherches d'architecture et de
blast radius. Un index reste toujours un cache rafraîchissable et staleness-aware.
Il ne peut jamais remplacer l'état live.

### Écosystème Drupal skills

```text
VERDICT = P2_AUDIT / ONE_LOW_RISK_PILOT
```

Préférer les skills Drupal maintenus/upstream aux skills Agency propriétaires
quand ils couvrent le besoin. Toute réutilisation exige provenance, licence,
préconditions, accès requis et comportement vérifiable.

### FlowDrop

```text
FLOWDROP_CORE = P2_PILOT
PORT_SHAPES = INTEGRATE_PRINCIPLE
STATEGRAPH = P2_PILOT
HITL = P2_PILOT
TOOL_PROVIDER = P3_DEFER
```

Question du pilote:

> FlowDrop peut-il remplacer une partie réellement coûteuse de l'orchestration
> custom Agency plutôt que devenir une seconde couche parallèle ?

Le pilote doit rester synthétique, sans PROD, et tester au minimum typed input,
étape déterministe, reprise/state, HITL et receipt synthétique.

`user input != authority`: un mécanisme HITL ne remplace pas les gates Project
Lead ou permissions Drupal/GitHub.

### MCP / Tool API

```text
VERDICT = EXTEND_EXISTING
READ_ONLY_FIRST = REQUIRED
ARBITRARY_DRUSH = REJECT
GENERIC_ENTITY_WRITE = REJECT
PUBLIC_WRITE_MCP = P3
```

Préférer des tools sémantiques bornés exprimant une capacité métier plutôt qu'un
shell Drupal distant.

### `llms.txt`

```text
VERDICT = ALREADY_COVERED
ROLE = DISCOVERY_AND_GUIDANCE_ONLY
AUTHORITY = NONE
```

### Rules as Code

```text
VERDICT = EXTEND_EXISTING
GENERIC_POLICY_ENGINE = NO
```

Encoder les règles récurrentes concrètes dans les workflows, tests et config
existants quand cela réduit réellement l'ambiguïté.

### Semantic artifact typing

```text
VERDICT = INTEGRATE_PRINCIPLE
NEW_GLOBAL_ARTIFACT_FRAMEWORK = NO
```

Réutiliser les patterns candidates/evidence/receipts déjà présents. Créer un
nouveau type durable uniquement lorsqu'un workflow concret le requiert.

### GitHub Actions supply chain

```text
THIRD_PARTY_ACTION_FULL_SHA_PINNING = P1
GITHUB_TOKEN_PERMISSION_AUDIT = P1
```

Les actions tierces référencées par des tags mutables doivent faire l'objet d'une
tranche bornée de pinning vers des commits complets révisés, avec commentaire de
version lisible lorsque utile.

Auditer systématiquement les permissions `GITHUB_TOKEN` et réduire uniquement les
exceptions concrètes. Ne pas refondre le système d'authentification CI.

### Composer supply chain

```text
VERDICT = ALREADY_COVERED_ARCHITECTURALLY
```

Le bon modèle reste: contraintes revues, `composer.lock` versionné, resolver
Composer gouverné, publication séparée et `composer install` depuis le lock.

#962 reste la tâche de maintenance active lorsque le runner requis est disponible.

### Egress / Harden-Runner / StepSecurity

```text
EGRESS_BASELINE = P2_OBSERVE_FIRST
HARDEN_RUNNER = P2_PILOT
DEFAULT_DENY_NOW = NO
```

Premier essai: audit mode sur un job GitHub-hosted non sensible. Observer les
processus et destinations réelles avant d'envisager une allowlist bloquante.

Ne pas créer de dépendance au produit pour les surfaces self-hosted tant que le
modèle de support/licence n'est pas adapté au besoin Agency.

## 6. Roadmap priorisée

### P0 — immédiat

```text
NONE
```

Aucune preuve actuelle ne justifie d'interrompre les travaux produit avec un
nouveau P0 issu de cette veille.

### P1 — prochaine valeur élevée

1. **Supply-chain GitHub Actions**: pinning full SHA des actions tierces + audit
   borné des permissions `GITHUB_TOKEN`.
2. **Agent Experience**: intégrer explicitement machine discovery, capacités
   bornées, identité/compte/autorité et auditabilité dans #863/#390, sans nouvelle
   architecture parallèle.

### P2 — pilotes contrôlés

Un seul pilote P2 peut être actif à la fois.

1. FlowDrop 2.5.x: workflow synthétique typed -> déterministe -> HITL -> receipt.
2. Discovery/index: comparer l'existant Agency à un snapshot `drupal-status` /
   index-style; mesurer gain de scan et exactitude; le live gagne toujours.
3. Un skill Drupal réutilisable read-only ou static-analysis issu d'une source
   maintenue; comparer au prompt géant équivalent.
4. Codex 0.153 context management: tâche read-only longue traversant une transition
   de contexte; l'autorité doit être rechargée de GitHub correctement.
5. Egress/Harden-Runner: observation audit-mode sur CI hosted non sensible.

### P3 — veille

- FlowDrop Tool Provider tant qu'il n'est pas stable/security-covered;
- marketplaces distantes Codex/Drupal tant que source, révision, intégrité et
  capacités ne sont pas vérifiables;
- MCP write public;
- index projet large si le pilote borné n'apporte pas de bénéfice mesurable.

## 7. Candidats historiques à conserver

### Open Design

Candidat historique: `nexu-io/open-design`.

À revalider live pour:

- design local-first et design systems;
- `DESIGN.md`;
- prototypes et landing pages;
- mapping vers SDC gouvernés;
- UI skills;
- images et export;
- séparation agent / design engine / artifact.

Question unique d'un éventuel POC:

> Peut-on partir de la design authority Agency et produire une proposition de page
> réellement exploitable comme composition de composants Drupal SDC gouvernés ?

Aucun POC Open Design n'est READY aujourd'hui.

### Skills croissance, contenu et qualité

Catalogues historiques à revalider, sans présumer de leur état actuel:

- `ComposioHQ/awesome-claude-skills`;
- `github/awesome-copilot`;
- `wshobson/agents`;
- `addyosmani/agent-skills`;
- upstreams Drupal maintenus et alternatives plus récentes.

Capacités d'intérêt:

- lead research;
- source-driven content research;
- brand guidelines;
- browser/visual testing;
- accessibilité;
- UX/frontend;
- performance;
- SEO;
- sécurité/observabilité;
- migration/deprecation/release quality.

### Naturalisation des contenus IA

Candidat conceptuel historique: `blader/humanizer`.

Ne pas importer mécaniquement un humanizer anglophone. Évaluer les concepts pour
réduire structures artificielles, répétitions, emphase, transitions mécaniques,
marketing creux et faux ton conversationnel tout en préservant strictement:

- faits;
- noms;
- chiffres;
- citations;
- niveau de certitude.

Une capacité transverse de review/rewriting/naturalisation appartient
préférentiellement à Preflight si elle ne constitue pas un comportement produit
spécifique à Agency.

## 8. Supply chain et licences

Pour chaque skill, plugin, action, adapter ou dépendance externe envisagé:

```text
LICENSE
MAINTAINER
LAST_ACTIVITY
RELEASE_MODEL
IMMUTABLE_REVISION
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

Avant toute copie de code:

```text
COPY_ALLOWED
MODIFICATION_ALLOWED
ATTRIBUTION_REQUIRED
NOTICE_REQUIRED
REDISTRIBUTION_CONDITIONS
COMPATIBILITY_WITH_AGENCY
```

Pas de `curl | bash`, URL distante -> auto-trust, ou extension non épinglée sur une
surface gouvernée.

## 9. Gap analysis obligatoire

Avant toute issue d'implémentation dérivée de cette roadmap, vérifier live:

- repository et configuration Agency;
- Drupal core/contrib/SDC/Canvas/Drupal AI;
- `AGENTS.md`, ADR et `DESIGN.md`;
- issues/PR et décisions existantes;
- capacités Preflight et ForgePilot;
- upstream, licence et maintenance du candidat.

Décisions possibles:

```text
ALREADY_HAVE
ADOPT_UPSTREAM
ADAPT_UPSTREAM
COPY_BOUNDED_COMPONENT
REIMPLEMENT_CONCEPT
DEFER
REJECT
```

Une nouvelle issue ne doit pas être créée pour une capacité déjà couverte ou
rendue inutile par une primitive existante.

## 10. Ownership

```text
OWNER = AGENCY | PREFLIGHT | FORGEPILOT | SHARED_CONTRACT
```

### Agency

Expérience web, contenu, composants/design system, capacités produit et exposition
machine du produit.

### Preflight

Review, rewriting, conformité, vérification et contrôles transverses.

### ForgePilot

Orchestration, agents, execution governance, permissions, provenance, evidence et
gates d'orchestration.

Invariant:

```text
ForgePilot governs execution.
Agency owns/consumes product capabilities.
```

## 11. Content engine et acquisition — direction à conserver

Pipeline éditorial conceptuel:

```text
RESEARCH
-> SOURCE
-> DRAFT
-> FACT CHECK
-> BRAND CHECK
-> NATURAL LANGUAGE PASS
-> SEO / STRUCTURE
-> DRUPAL STRUCTURED CONTENT
-> PREVIEW
-> HUMAN REVIEW
-> PUBLISH
```

Un modèle n'est jamais une source factuelle implicite.

Une future prospection peut préparer de la recherche et de la qualification, mais
ce document n'autorise aucun envoi. Toute campagne réelle reste séparément
gouvernée et doit respecter le droit applicable, la minimisation des données et
les mécanismes d'opposition.

## 12. Mesure de valeur

Un pilote n'est conservé que s'il apporte une valeur mesurable.

Exemples:

```text
time_to_prototype
manual_corrections
component_reuse_rate
time_to_first_draft
fact_corrections
human_rewrite_rate
scan_time_reduction
stale_index_error_rate
qualified_leads
human_acceptance_rate
```

Ne pas créer de métriques artificielles uniquement pour justifier une technologie.

## 13. Re-entry checklist

Avant tout travail dérivé:

1. reload live GitHub et repository;
2. reload upstream, licence et maintenance;
3. vérifier Drupal/core/contrib/SDC/AI/Canvas et capacités Agency actuelles;
4. revalider ADR-001 et ADR-003;
5. produire le gap analysis;
6. attribuer l'owner;
7. classer valeur/coût/risque;
8. sélectionner au maximum un slice borné;
9. conserver `one issue = one branch = one PR`;
10. aucun runtime ou effet externe sans gate explicite.

## 14. Frontière d'exécution

Par défaut, cette roadmap signifie:

```text
IMPLEMENTATION_AUTHORITY = NONE
NEW_DEPENDENCY = NONE
NEW_RUNTIME_SKILL = NONE
NEW_MCP_SERVER = NONE
NEW_FRAMEWORK = NONE
PREPROD_MUTATION = NONE
PROD_MUTATION = NONE
COMMERCIAL_SEND = NONE
```

Lorsqu'un item P1/P2 est sélectionné, son issue propre et son autorité Project
Lead définissent le scope réel. La roadmap elle-même ne confère aucune permission.
