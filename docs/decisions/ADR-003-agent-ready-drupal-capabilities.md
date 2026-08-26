# ADR-003 — Agent-Ready Drupal Capabilities

- **Statut : ACCEPTED**
- **Date : 2026-08-26**
- **Issue : #844**
- **Complète : ADR-001 — Governed AI Experience**
- **Supersède : aucune décision**

## 1. Décision

Agency est conçu comme une plateforme Drupal **agent-ready** : humains, Drupal AI,
Codex et futurs agents doivent progressivement utiliser les **mêmes primitives
Drupal gouvernées** plutôt que créer une seconde application ou une seconde
logique métier réservée à l'IA.

Le modèle durable est :

```text
Visiteur humain
      |
      v
Drupal / domaine
contenu + entités + SDC + règles + workflows
      |
      +-------------------+-------------------+
      |                   |                   |
     UI/admin        structured API       agent adapters
                                              |
                                  +-----------+-----------+
                                  |           |           |
                              Drupal AI      MCP        WebMCP
                                  |           |           |
                                  +-----------+-----------+
                                              |
                                        Codex / agents
```

**Drupal/domain est la source de vérité.** API, MCP, WebMCP et browser automation
sont des surfaces d'accès ou des adaptateurs possibles ; ils ne deviennent pas
une autorité métier parallèle.

Cette décision complète ADR-001. `COMPOSE BEFORE CREATE`, le design system et les
SDC gouvernés restent autoritatifs pour la composition visuelle.

## 2. Hard gate opérationnel

L'inscription de cette décision est immédiate. Son implémentation runtime ne
l'est pas.

```text
AGENT_READY_ARCHITECTURE_MEMORY = ACTIVE_NOW
AGENT_READY_RUNTIME_IMPLEMENTATION = BLOCKED_UNTIL_PREPROD_TO_PROD_HEALTHY
CURRENT_OPERATIONAL_PRIORITY = PREPROD_TO_PROD_STABILIZATION
```

Tant que le programme PREPROD -> PROD courant n'est pas terminé avec preuves et
PROD saine, cette ADR **n'autorise aucune nouvelle implémentation Agent-Ready** :

- pas de WebMCP runtime ;
- pas de serveur MCP complet ;
- pas de nouvel endpoint agent ;
- pas de capability de mutation ;
- pas de nouveau framework agent ;
- pas de refonte du back-office ;
- pas de modification du chemin #816 / #834 ni des corrections Ops qui le
  débloquent.

Toute exception avant cette frontière exige une décision Project Lead distincte,
un besoin bloquant démontré et une issue dédiée ; la valeur par défaut est
`DEFER`.

## 3. Drupal reste autoritatif

L'autorité métier reste dans Drupal, notamment pour :

- contenu et entités ;
- configuration ;
- permissions et rôles ;
- entity access ;
- workflows, modération et états éditoriaux ;
- validation ;
- SDC et composants admis ;
- design tokens et design system ;
- publication ;
- cache et invalidation ;
- routing ;
- traductions et révisions.

Un agent, un tool, un MCP server, WebMCP ou du code navigateur ne doit jamais
devenir une seconde source de vérité.

Principe :

```text
client may request
server decides
```

Les décisions de sécurité et de validation importantes restent server-side et
ne reposent jamais uniquement sur JavaScript, un enregistrement WebMCP, un
prompt ou un modèle IA.

## 4. SDC-first et design-system-first

Pour les interfaces composées :

```text
agent-generated arbitrary markup < governed SDC composition
agent creativity < design system constraints
```

Un agent ne doit pas inventer par défaut :

- structures HTML arbitraires ;
- classes CSS ;
- composants ;
- couleurs ;
- espacements ;
- variantes ;
- comportements JavaScript.

Il doit composer avec les primitives approuvées conformément à ADR-001 et
`DESIGN.md`.

Les composants doivent progressivement être suffisamment auto-descriptifs pour
être compris par Drupal, les développeurs, les éditeurs, Drupal AI, les systèmes
de contexte, Codex et futurs adaptateurs. Avant de créer un format propriétaire,
il faut réutiliser les métadonnées Drupal/SDC natives disponibles.

Un contrat machine-readable de composant pourra exposer, lorsque les primitives
standard le permettent : identité, purpose, props, slots, champs requis ou
optionnels, variantes, contraintes, règles de design, guidance de contenu,
accessibilité et références d'usage.

## 5. Structured-first

Lorsqu'une capacité métier Agency doit devenir consommable par un agent, l'ordre
de préférence est :

```text
Drupal service / domain
        |
structured data / API
        |
MCP / WebMCP adapters
        |
generic browser automation
```

Browser automation reste utile pour la vérification visuelle, les workflows sans
interface structurée et les services externes, mais **browser != domain
authority**. Il ne doit pas devenir l'API métier officieuse lorsque Drupal expose
une primitive structurée adaptée.

MCP et WebMCP ne s'opposent pas : ils peuvent être deux adaptateurs vers les
mêmes services et contrôles Drupal.

- MCP est pertinent pour Codex, ForgePilot, automation backend et accès
  machine-to-machine.
- WebMCP est pertinent pour le navigateur authentifié, le contexte de page et
  certaines interactions back-office.

Le domaine doit survivre à leur remplacement ou évolution.

## 6. Read-only-first et niveaux d'autorité

La progression cible est :

```text
L0  capability discovery
L1  read-only inspection
L2  draft preparation
L3  governed internal mutation
L4  publication / external effect
```

Les premières capacités à étudier après stabilisation doivent être read-only :

- catalogue de composants ;
- métadonnées SDC ;
- design rules ;
- structure de page ;
- content requirements ;
- validation ;
- draft status ;
- publication readiness.

Premier candidat privilégié : **exposer le catalogue SDC gouverné comme capacité
agent-readable read-only**, en réutilisant les métadonnées existantes.

## 7. Draft n'est pas publish

```text
agent can compose != agent can publish
draft creation != publication authorization
```

Un agent pourra éventuellement proposer, préparer, composer ou modifier un
brouillon sans obtenir automatiquement le droit de publier.

Drupal permissions, entity access, workflow, moderation et validation restent
les autorités.

## 8. Same permissions

```text
agent capability != extra authority
```

Une capability agent doit respecter les mêmes contrôles server-side que
l'opération humaine équivalente. La visibilité d'un tool n'est jamais une
autorisation.

Réutiliser autant que possible : permissions Drupal, rôles, entity access,
workflow access, moderation, validation, CSRF/session rules et authorization
server-side.

## 9. Drupal AI, contexte et ForgePilot

Drupal AI reste une capability intégrée à Drupal, pas une architecture
concurrente. Les primitives Drupal AI existantes doivent être évaluées avant tout
custom.

Les mécanismes de contexte tels que Context Control Center peuvent fournir le
contexte explicite nécessaire (architecture, design, composants, branding,
contenu, contraintes, permissions, workflow) si leur rôle live le justifie. Ils
ne deviennent pas un second domaine métier.

Principe :

```text
context should be explicit not guessed
```

La séparation future avec ForgePilot est :

```text
ForgePilot = governed orchestration / control plane
Agency     = domain authority
API/MCP    = preferred machine interface when appropriate
WebMCP     = browser-context adapter when appropriate
```

Agency ne dépend pas de ForgePilot pour fonctionner.

## 10. Site public et positionnement commercial

Agent-readiness du back-office ne remplace aucune primitive du web public.
Continuer à privilégier HTML sémantique, accessibilité, structured data,
Schema.org lorsque pertinent, métadonnées, contenu de qualité, performance, SEO,
URLs stables et crawlability.

Le visiteur humain reste prioritaire.

L'architecture technique ne transforme pas Agency en offre commerciale Drupal
uniquement. Drupal peut disposer de parcours et landing pages spécialisées, mais
les primitives Agent-Ready doivent pouvoir servir tous les futurs services
Agency.

## 11. Standards over custom

Avant toute future implémentation :

1. vérifier Drupal core ;
2. vérifier les modules contrib pertinents ;
3. vérifier SDC ;
4. vérifier Drupal AI et primitives associées ;
5. vérifier le mécanisme de contexte gouverné pertinent ;
6. vérifier les API standards ;
7. vérifier MCP/WebMCP standards au moment de l'implémentation ;
8. seulement ensuite envisager du custom.

Cette ADR n'autorise pas la création de frameworks génériques tels que
`AgencyAgentFramework`, `UniversalAgentGateway`, `AgentOrchestrator`,
`AIPageEngine`, `PromptToPageEngine` ou `UniversalToolRegistry` sans gap réel,
documenté et prouvé.

```text
standard primitive > project-specific framework
```

## 12. Testabilité et audit futurs

Toute future capability read-only doit prouver au minimum : permissions,
absence de mutation, output structuré, invalid input, missing entity, absence de
fuite de données sensibles et déterminisme raisonnable.

Une capability mutante doit en plus prouver : exact target, workflow,
authorization, audit, idempotency lorsque possible, modèle CSRF/session,
concurrence, frontière draft/publish et rollback/recovery lorsque pertinent.

Pour toute mutation initiée par agent, l'audit doit permettre d'établir au
minimum :

```text
WHO
WHAT
WHEN
ON_WHAT
AUTHORITY
RESULT
```

et, lorsque pertinent : agent/tool identity, request/correlation id,
content/entity id, revision id et workflow transition. Réutiliser les primitives
Drupal existantes avant de créer un second système d'audit.

## 13. Non-goals

Cette décision n'impose pas immédiatement :

- WebMCP en production ;
- un serveur MCP complet ;
- une refonte du back-office ;
- une autonomie de publication ;
- de la génération libre HTML/CSS ;
- un framework agent propriétaire ;
- une nouvelle couche métier parallèle ;
- une modification du chemin PREPROD/PROD courant.

## 14. Test de durabilité

Cette décision doit rester cohérente si WebMCP disparaît, MCP évolue, Codex est
remplacé, Drupal AI change ou un autre fournisseur IA est utilisé.

Ce qui doit survivre est :

```text
Drupal domain
+ governed SDC
+ explicit design system
+ structured capabilities
+ same permissions
+ controlled mutations
```

Un développeur ou un agent disposant seulement du repository doit pouvoir
comprendre : **les humains et les agents travaillent sur les mêmes primitives
Drupal ; les adaptateurs changent, l'autorité du domaine ne change pas.**
