# Audit de convergence FutureAi vers Drupal AI

Statut : **DECISION DE CONVERGENCE**  
Issue : #388  
Parent stratégique : #32  
Date : 2026-08-15

## 1. Objet

Le module `emerging_digital_chatbot` contient une couche `FutureAi` construite
avant l'adoption de la doctrine `docs/drupal-ai-architecture.md`. Cette couche
prépare un mode IA serveur mais ne l'active pas : le chatbot public conserve son
mode déterministe et `future_ai.enabled` doit rester `false` tant qu'un ticket
d'activation distinct n'a pas prouvé la nouvelle trajectoire.

Le but de cet audit n'est pas de supprimer FutureAi. Il sépare :

- la **valeur métier E-merging Digital**, qui doit rester sous contrôle du
  projet ;
- l'**infrastructure IA générique**, qui doit converger vers Drupal AI lorsque
  l'upstream stable couvre le besoin ;
- les capacités upstream encore immatures, pour lesquelles le projet doit
  attendre plutôt que construire un second framework parallèle.

Aucun changement de comportement, de provider, de secret, de configuration
runtime ou de parcours public n'est autorisé par ce document.

## 2. État courant vérifié

La pile verrouillée au moment de l'audit est notamment :

- Drupal Core `11.4.5` ;
- `drupal/ai 1.4.6` ;
- `drupal/ai_provider_openai 1.2.4` ;
- `drupal/key 1.22.0`.

La couche actuelle câble :

```text
endpoint / payload public
        |
        v
ChatbotPayloadSanitizer
        |
        v
FutureAiOrchestrator
   |        |         |           |
   |        |         |           +-- FutureAiMonitoring
   |        |         +-------------- PublicAiContextProvider
   |        |                           +-- PublicContextBuilder
   |        +------------------------ FutureAiEnvironmentGuard
   |
   +-- FutureAiProviderRegistry
          +-- OpenAiResponsesGateway (verrouillé : isEnabled() = false)
          +-- MockFutureAiProviderGateway
          +-- NullFutureAiProviderGateway

échec / blocage
        |
        v
NullFutureAiGateway
        |
        v
FutureAiResponse + status/reason contrôlés
```

`QualificationEngine` reste séparé de cette pile : il construit l'arbre local
déterministe de qualification et ses CTA internes.

## 3. Doctrine de décision

Les verdicts utilisés sont :

- **KEEP** : valeur métier ou frontière de sécurité propre au site à conserver ;
- **CONVERGE** : conserver le besoin, mais migrer progressivement le mécanisme
  vers une primitive Drupal AI ;
- **REPLACE** : abstraction générique déjà suffisamment couverte par Drupal AI,
  à remplacer après preuve de parité ;
- **DEPRECATE LATER** : garder temporairement pour compatibilité/fallback, puis
  retirer uniquement après migration prouvée ;
- **WAIT FOR UPSTREAM** : ne pas généraliser le custom et attendre une primitive
  upstream suffisamment stable.

Règle permanente :

```text
infrastructure générique couverte par upstream stable
-> REPLACE / CONVERGE

politique, sécurité et UX propres au site
-> KEEP comme adapter/policy

upstream immature
-> WAIT FOR UPSTREAM
-> geler l'extension générique custom
```

## 4. Cartographie Current -> Target

| Primitive actuelle | Responsabilité actuelle | Verdict | Cible | Action immédiate |
| --- | --- | --- | --- | --- |
| `FutureAiProviderGatewayInterface` | Abstraction de provider custom | **REPLACE** | Provider abstraction + operation types Drupal AI | Geler toute nouvelle implémentation provider custom |
| `FutureAiProviderRegistry` | Registre/tagged services + sélection du provider | **REPLACE** | `ai_provider` / provider manager Drupal AI et provider/modèle par operation type | Ne plus ajouter de provider id, alias ou registry custom |
| `OpenAiResponsesGateway` | HTTP Guzzle direct, auth, payload Responses, parsing provider | **DEPRECATE LATER** | `ai_provider_openai` derrière Drupal AI | Rester verrouillé à `isEnabled() = false`; ne pas devenir la route d'activation |
| `FutureAiOrchestrator` | Orchestration provider, key, contexte, fallback, monitoring | **CONVERGE** | Adapter métier mince au-dessus de Drupal AI + Guardrails | Geler toute nouvelle infrastructure provider/retrieval/telemetry dans cette classe |
| `FutureAiEnvironmentGuard` | Kill switch, provider support, clé, autorisation d'appel externe | **CONVERGE** | Politique projet + état/usabilité du provider Drupal AI | KEEP du kill switch explicite; remplacer à terme registry/key/provider probing custom |
| `FutureAiMonitoring` | Compteurs cache succès/blocage/erreur/fallback | **CONVERGE** | AI Observability pour la télémétrie provider; compteur métier minimal si encore utile | Ne pas étendre la télémétrie générique; propriétaire : #389 |
| `PublicAiContextProvider` | Contrat de contexte public et note de prompt | **CONVERGE** | Adapter projet vers contexte partagé quand upstream mature | Conserver le contrat public; propriétaire mécanisme partagé : #387 |
| `PublicContextBuilder` | Allowlist pages/champs, publié/traduit, sanitation, budget | **KEEP + CONVERGE** | Politique projet conservée; mécanisme de sélection/retrieval réévaluable avec AI Context | Ne pas transformer ce builder en framework générique de contexte |
| `FutureAiResponse` | Contrat HTTP public sûr et indépendant du payload provider | **KEEP** | DTO/adaptateur public stable | Conserver pendant toute la migration |
| `FutureAiResponseStatus` / `FutureAiResponseReason` | Vocabulaire contrôlé de succès/blocage/fallback | **KEEP puis DEPRECATE LATER partiel** | Statuts métier stables; retirer seulement les détails provider devenus inutiles | Ne pas exposer les erreurs/provider payloads bruts |
| `FutureAiGatewayInterface` | Frontière locale `payload -> FutureAiResponse` | **KEEP** | Interface de use case chatbot, éventuellement renommée après convergence | Ne pas la transformer en interface de provider |
| `NullFutureAiGateway` | Fallback déterministe sans appel externe | **KEEP** | Fallback local permanent | Préserver comme voie fail-closed |
| `MockFutureAiProviderGateway` | Test seam provider custom | **DEPRECATE LATER** | Test double derrière l'adapter Drupal AI | Conserver jusqu'à preuve de parité des tests |
| `NullFutureAiProviderGateway` | Provider custom explicitement indisponible | **DEPRECATE LATER** | Provider indisponible géré via Drupal AI + fallback local | Retirer avec le registry custom, pas avant |
| `ChatbotPayloadSanitizer` | Minimisation et sanitation de l'entrée visiteur | **KEEP** | Frontière de validation projet, complémentaire aux Guardrails | Conserver même après migration provider |
| Sanitizer de sortie / règles commerciales dans `OpenAiResponsesGateway` | Nettoyage du texte et blocage prix/devis/promesses | **CONVERGE** | Guardrails/policy projet au-dessus de Drupal AI, sans dépendre d'OpenAI | Extraire seulement lors de la migration, avec tests de parité |
| `QualificationEngine` | Qualification déterministe + CTA internes | **KEEP** | Logique métier locale | Ne pas remplacer par un agent/LLM |

## 5. Provider : remplacer le mini-framework custom

### Constat

`FutureAiProviderGatewayInterface`, `FutureAiProviderRegistry` et
`OpenAiResponsesGateway` reproduisent des responsabilités génériques :

- découverte/sélection d'un provider ;
- vérification de disponibilité ;
- transport HTTP et authentification ;
- adaptation des inputs/outputs ;
- parsing d'une réponse spécifique fournisseur.

Drupal AI fournit précisément une abstraction provider avec des operation types
et un provider manager. Le code consommateur doit demander une capacité et non
connaître l'API HTTP d'OpenAI.

### Décision

La prochaine activation du chatbot IA **ne doit pas** consister à faire passer
`OpenAiResponsesGateway::isEnabled()` à `true`.

La migration cible est :

```text
FutureAiGatewayInterface (use case local)
        |
        v
adapter chatbot Drupal AI
        |
        v
Drupal AI operation type
        |
        v
ai_provider / ai_provider_openai
```

Le nouvel adapter doit retourner `FutureAiResponse`, afin que le frontend et le
fallback restent stables pendant la migration.

Si une capacité spécifique de la Responses API n'est pas disponible par
l'abstraction stable utilisée par le projet, le verdict est **WAIT**, pas
"réactiver le gateway HTTP direct".

## 6. Orchestration : garder les décisions métier, retirer le plumbing

`FutureAiOrchestrator` contient deux catégories de responsabilités.

### À conserver

- mode `guide` vs futur mode IA ;
- feature flag `future_ai.enabled` ;
- blocage des entrées déjà classées sensibles ;
- refus d'un message vide ;
- fallback public FR/EN ;
- mapping vers un contrat HTTP contrôlé ;
- exigence d'un contexte public prêt avant une réponse qui prétend utiliser ce
  contexte.

### À faire converger

- sélection du provider ;
- résolution directe des secrets pour les transmettre au gateway ;
- vérification générique du provider ;
- appel provider ;
- télémétrie générique provider ;
- mécanismes génériques de contexte quand #387 fournit une cible stable.

Après convergence, l'orchestrateur peut survivre comme **policy/use-case
adapter** très mince. Il ne doit plus être un framework IA parallèle.

## 7. Environment Guard : conserver le kill switch, absorber le reste

La règle `allow_external_ai` / variable d'environnement est une politique locale
utile : un environnement peut interdire explicitement les appels externes même
si un provider est configuré. Cette défense en profondeur peut rester.

En revanche, le code qui :

- connaît les ids de providers custom ;
- inspecte plusieurs noms possibles de clé provider ;
- résout une clé pour la transmettre à un gateway HTTP custom ;
- décide si le provider custom est enregistré ;

doit disparaître avec le registry/gateway custom. L'adapter doit s'appuyer sur
l'usabilité et la configuration du provider Drupal AI sans lire/exposer le
secret au use case.

## 8. Guardrails et sanitation : deux couches complémentaires

#379 établit la baseline Guardrails Drupal AI pour les appels qui passent par
l'abstraction upstream.

Cela **ne remplace pas** `ChatbotPayloadSanitizer` : la minimisation des champs,
la validation d'URL, le filtrage de données personnelles/sensibles et le
contrôle FR/EN appartiennent à la frontière HTTP publique du site et doivent
avoir lieu avant tout appel IA.

Les règles actuellement enfermées dans `OpenAiResponsesGateway` (nettoyage de
sortie, refus de prix/devis/promesses commerciales) sont des politiques projet,
pas des fonctions OpenAI. Lors de la migration provider, elles doivent devenir
une couche provider-agnostic testée au-dessus de Drupal AI ou un Guardrail
custom si le modèle Guardrails stable correspond réellement au besoin.

Aucune extraction n'est demandée dans #388.

## 9. Contexte : conserver la politique, attendre le mécanisme partagé

`PublicContextBuilder` contient une valeur sécurité/métier réelle :

- seuls des chemins explicitement autorisés ;
- seulement des nodes publiés et traduits ;
- allowlist de champs publics ;
- exclusion de routes sensibles ;
- suppression de scripts, emails, téléphones et formes usuelles de secrets ;
- budget maximal de contexte.

Cette politique doit rester même si le stockage/sélection du contexte change.

En août 2026, **Context Control Center (`ai_context`) est encore en beta et ne
possède aucune release stable supportée**. #387 est donc le propriétaire de la
convergence vers un contexte partagé.

Décision #388 :

```text
PublicContextBuilder policy
-> KEEP

framework générique de contexte/retrieval supplémentaire dans FutureAi
-> interdit

Context Control Center / AI Context
-> WAIT FOR UPSTREAM via #387
```

Le chatbot peut conserver son contexte public borné actuel tant que cette
attente ne change pas son comportement public.

## 10. Observabilité : ne plus étendre les compteurs génériques

`FutureAiMonitoring` compte localement succès, blocages, erreurs provider et
fallbacks. Cette information reste utile tant que le flux FutureAi existe, mais
elle ne couvre pas correctement les futurs besoins transversaux : provider,
modèle, latence, tokens, coût, rate limits et provenance.

AI Observability est précisément destiné à observer les appels effectués via
Drupal AI et sait exposer notamment durée/provider/token usage selon les données
disponibles.

Décision :

- conserver `FutureAiMonitoring` tel quel tant que FutureAi est inactif/legacy ;
- **ne pas** lui ajouter tokens, coût, tracing, prompts ou dashboards ;
- faire de #389 le propriétaire de l'observabilité transversale ;
- après preuve de parité, ne garder éventuellement qu'un compteur métier local
  de fallback/qualification si celui-ci apporte encore une valeur distincte.

## 11. Agents : ne pas agentifier un parcours déterministe

Le chatbot actuel qualifie et oriente. `QualificationEngine` produit un arbre
local ordonné et des CTA internes : aucune boucle de raisonnement ou chaîne de
tools n'est nécessaire pour ce comportement.

AI Agents ne doit donc pas remplacer `QualificationEngine`.

Un agent deviendrait pertinent uniquement avec un nouveau besoin explicitement
agentique, par exemple plusieurs étapes dynamiques et des tools bornés. Ce besoin
nécessiterait un ticket distinct, permissions explicites et preuve de sécurité.

Pour une action déterministe et répétable, conserver une primitive déterministe
est préférable à un agent.

## 12. Tool API / MCP

La trajectoire Drupal remplace progressivement les Function Call plugins par un
Tool API, mais la documentation upstream indique encore que cette API est en
développement et susceptible de changer.

Pour le chatbot public :

```text
Tool API / MCP en production
-> WAIT FOR UPSTREAM
```

#388 n'installe rien et n'ajoute aucun tool. Le chantier de browser validation
#400 peut utiliser Playwright MCP comme surface d'exécution **privée** ; cela ne
change pas la doctrine du chatbot public.

## 13. Contrat de réponse et fallback

`FutureAiResponse` et `FutureAiGatewayInterface` sont différents du provider
framework custom : ils forment une frontière de use case locale et protègent le
frontend contre les détails d'un fournisseur.

Ils doivent survivre à la première migration :

```text
frontend public
     ^
     |
FutureAiResponse
     ^
     |
FutureAiGatewayInterface
     ^
     |
adapter Drupal AI
```

Les statuts/reasons purement techniques pourront être réduits plus tard, mais
pas avant preuve qu'aucun test, endpoint ou frontend ne dépend d'eux.

`NullFutureAiGateway` reste le fallback déterministe, y compris si Drupal AI est
indisponible.

## 14. Plan de migration borné et réversible

### Étape A — gel immédiat

Dès acceptation de #388 :

- aucun nouveau `FutureAiProviderGatewayInterface` ;
- aucun nouveau provider id/registry custom ;
- aucune extension de `OpenAiResponsesGateway` ;
- aucune nouvelle télémétrie générique dans `FutureAiMonitoring` ;
- aucun framework générique de contexte dans FutureAi ;
- `future_ai.enabled` reste `false`.

### Étape B — baseline Guardrails

Terminer #379. Aucun lien de dépendance n'est créé entre #388 et l'import
runtime de #379 ; #388 reste un audit documentation-only.

### Étape C — adapter provider Drupal AI

Dans un ticket futur borné :

1. créer un adapter derrière `FutureAiGatewayInterface` utilisant Drupal AI ;
2. conserver `FutureAiResponse` et `NullFutureAiGateway` ;
3. prouver FR/EN, timeout/error/fallback et absence de fuite provider ;
4. prouver que l'appel traverse les Guardrails ;
5. garder l'ancien gateway verrouillé pendant la preuve.

Rollback : rétablir l'alias vers le fallback local ; aucun besoin de réactiver
le gateway OpenAI direct.

### Étape D — contexte

Laisser #387 décider `ADOPT / WAIT / ADAPTER MINIMAL` pour Context Control
Center. Si l'upstream devient stable et couvre le contrat, adapter la politique
publique existante sans perdre allowlists/sanitation/provenance.

### Étape E — observabilité

Laisser #389 prouver la baseline AI Observability privacy-first. Ensuite
réduire `FutureAiMonitoring` à la valeur métier réellement non couverte.

### Étape F — retirement

Seulement après preuves de parité :

- supprimer `OpenAiResponsesGateway` ;
- supprimer `FutureAiProviderRegistry` ;
- supprimer `FutureAiProviderGatewayInterface` et ses provider doubles ;
- simplifier `FutureAiEnvironmentGuard` ;
- réduire les statuts/reasons obsolètes ;
- retirer les services correspondants.

Chaque suppression doit être un ticket/PR borné. Aucun big-bang.

## 15. Freeze map

| Zone | Peut encore évoluer avant convergence ? |
| --- | --- |
| Qualification métier / CTA / fallback local | **Oui**, seulement pour un besoin produit explicite |
| Sanitation/minimisation du payload public | **Oui**, pour sécurité/correctness |
| Contrat HTTP public | **Oui**, uniquement avec compatibilité explicite |
| Provider registry/gateway custom | **Non** |
| HTTP OpenAI direct | **Non** |
| Generic provider readiness/key plumbing | **Non** |
| Monitoring générique provider | **Non** |
| Framework générique de contexte custom | **Non** |
| Agent/tool framework custom | **Non** |

## 16. Critères avant toute suppression

Aucun composant FutureAi ne doit être retiré avant une preuve qui couvre au
minimum :

- mode guide inchangé lorsque l'IA est désactivée ;
- `future_ai.enabled=false` fail-closed ;
- FR et EN ;
- sanitation des entrées ;
- refus des données sensibles ;
- contexte public seulement ;
- provider indisponible ;
- timeout/erreur ;
- fallback déterministe ;
- contrat JSON public ;
- Guardrails effectifs sur la route Drupal AI ;
- aucun secret dans réponse/log/config versionnée ;
- tests existants verts.

## 17. Verdict final

La couche FutureAi n'est pas à jeter : elle contient plusieurs politiques de
sécurité et contrats métier utiles. En revanche, **son mini-framework provider
et une partie de son plumbing doivent être considérés comme legacy gelé**.

La cible est :

```text
valeur métier E-merging Digital
  qualification
  sanitation
  public-context policy
  response/fallback contract
  explicit environment kill switch
             |
             v
adapter projet mince
             |
             v
Drupal AI
  provider abstraction
  Guardrails
  Observability
  context upstream lorsqu'il est stable
  Agents/Tools seulement si un vrai besoin agentique apparaît
```

Cette convergence réduit la maintenance et permet aux futurs usages IA du site
de partager les mêmes primitives sans coupler le chatbot à OpenAI.

## 18. Sources upstream vérifiées

Sources officielles consultées pendant l'audit :

- Drupal AI documentation : `https://project.pages.drupalcode.org/ai/`
- Drupal AI provider abstraction / operation types : documentation développeur
  du projet AI ;
- AI Observability : `https://project.pages.drupalcode.org/ai/1.2.x/modules/ai_observability/`
- Context Control Center : `https://www.drupal.org/project/ai_context`
- Tool API : `https://project.pages.drupalcode.org/orchestration/modules/tool/`

État Context Control Center vérifié le 2026-08-15 : dernière release supportée
par le projet = `1.0.0-beta2`; aucune release stable supportée.
