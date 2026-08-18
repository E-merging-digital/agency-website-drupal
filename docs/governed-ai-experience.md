# Architecture — Governed AI Experience

Statut : **AUTHORITATIVE TARGET ARCHITECTURE**  
ADR : `docs/decisions/0001-governed-ai-experience.md`  
Design contract : `DESIGN.md`  
Owner : #518

## 1. Objectif

Agency doit démontrer une façon Drupal-native de produire des expériences assistées par IA sans abandonner les garanties qui font la valeur de Drupal : composants structurés, permissions, révisions, workflows, accessibilité, validation et publication gouvernée.

La cible n’est pas un générateur propriétaire de pages. La cible est un système dans lequel l’IA comprend un brief et **compose avec un catalogue approuvé**.

## 2. Architecture cible

```text
Figma
  |
  v
DESIGN.md / design tokens
  |
  v
Design System
  |
  v
SDC / Canvas Component Library
  |
  +------------------------+
  |                        |
  v                        v
Drupal AI Context       Canvas AI
  |                        |
  +-----------+------------+
              |
              v
        Page candidate
              |
              v
       Automated checks
              |
              v
         Human review
              |
              v
           Publish
```

La future frontière avec Preflight est :

```text
Agency
= production / composition de l’expérience

Preflight
= validation / preuves / gouvernance indépendante
```

## 3. Sources de vérité

Ordre fonctionnel :

1. `docs/decisions/0001-governed-ai-experience.md` — décision stratégique et invariants ;
2. `DESIGN.md` — contrat du design system et règles d’admission des primitives ;
3. `docs/drupal-ai-architecture.md` — doctrine Drupal AI upstream-first ;
4. `docs/drupal-ai-context-architecture.md` — contrat de contexte partagé et gouverné ;
5. `docs/browser-validation.md` — preuve navigateur indépendante ;
6. implémentation Drupal, thème, composants et configuration versionnée.

`AGENTS.md` doit forcer la découverte de ces documents avant tout travail frontend/Canvas/AI page building.

## 4. Snapshot upstream — 18 août 2026

Ce snapshot sert à expliquer les choix de cette architecture. Il doit être revalidé avant une adoption ou une dépendance importante.

### Drupal Canvas

État observé : Canvas 1.8.0 est une release stable couverte par la Drupal Security Team et compatible Drupal `^11.3`.

Référence : https://www.drupal.org/project/canvas

Verdict architectural : **USE DRUPAL** pour le moteur de composition visuelle lorsqu’un ticket d’adoption borné devient actionnable.

### SDC

Single-Directory Components est fourni par Drupal core et constitue la primitive Drupal naturelle pour des composants explicites et réutilisables.

Références :

- https://www.drupal.org/project/sdc
- https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components

Verdict : **USE DRUPAL**.

### Canvas AI / génération de pages

L’upstream Canvas AI sait déjà travailler avec la liste des composants disponibles et poursuit activement des scénarios de génération/édition de pages, intégration au design system, références de design et contexte.

Références de trajectoire :

- https://www.drupal.org/project/canvas/issues/3533079
- https://www.drupal.org/project/canvas/issues/3541783
- https://www.drupal.org/about/starshot/initiatives/ai/blog/the-future-of-ai-powered-web-creation-is-people-first-not-prompt-first

L’upstream continue toutefois d’évoluer sur plusieurs capacités et limitations. Agency ne doit donc pas supposer qu’un scénario particulier est stable simplement parce que Canvas lui-même l’est.

Verdict : **USE DRUPAL / EXTEND DRUPAL après preuve bornée**, avec revalidation de la capacité exacte au moment du ticket.

### Context Control Center

État observé : `ai_context` 1.0.0-beta2, sans release stable supportée.

Référence : https://www.drupal.org/project/ai_context

Verdict production : **DEFER / EXPERIMENTAL**.

Verdict architecture : suivre dès maintenant `docs/drupal-ai-context-architecture.md` et préparer les sources existantes à une future adoption, sans reconstruire CCC en custom.

### AI Playwright

La trajectoire #456 reste expérimentale/dev-only et distincte du Playwright CI Agency.

Verdict : **DEFER / EXPERIMENTAL** comme « yeux de l’agent » ; **ne jamais remplacer la preuve indépendante**.

## 5. Responsabilités par couche

### 5.1 Figma

Rôle : exprimer l’intention visuelle.

Inclut :

- maquettes ;
- variables/tokens ;
- composants de référence ;
- variantes ;
- interactions ;
- états responsive.

N’inclut pas : l’autorité exclusive sur le contrat technique exécutable.

Une future automatisation Figma doit d’abord démontrer qu’elle préserve les tokens, contrats SDC, variantes, accessibilité et processus d’admission.

### 5.2 `DESIGN.md` et tokens

Rôle : rendre les intentions de design suffisamment explicites pour les humains et agents qui implémentent ou composent l’expérience.

Le fichier contient les règles de système de design, pas les prompts ou personas éditoriaux.

### 5.3 Design system

Rôle : décider quelles primitives et variantes sont légitimes.

Le design system est plus large que l’outil de page building. Il possède :

- les tokens ;
- les composants ;
- les variantes ;
- les règles d’usage ;
- les critères d’accessibilité ;
- les règles d’admission ;
- la trajectoire de migration.

### 5.4 SDC / Canvas component library

Rôle : fournir les primitives exécutables et inspectables par Drupal.

Objectif #519 : inventorier le thème actuel, classifier les primitives et migrer un petit vertical slice représentatif vers SDC.

La migration doit rester incrémentale : Paragraphs/Twig n’est pas supprimé par principe.

### 5.5 Drupal AI Context

Rôle : fournir à l’IA le contexte nécessaire et seulement le contexte nécessaire.

Catégories décrites dans `docs/drupal-ai-context-architecture.md` : marque, voix, audiences, qualité web, gouvernance IA, terminologie, direction visuelle et contexte propre au use case.

Le design contract ne doit pas recopier ce contexte sémantique.

### 5.6 Canvas AI

Rôle cible :

- comprendre le brief ;
- rechercher les composants disponibles ;
- sélectionner les primitives et variantes ;
- remplir les props ;
- organiser les slots ;
- proposer une structure ;
- générer ou adapter le contenu dans les limites du contexte autorisé.

Canvas AI ne décide pas seul d’admettre un nouveau composant.

### 5.7 Page candidate

Une sortie IA est une **candidate**, pas un contenu automatiquement publiable.

Elle doit conserver :

- la traçabilité des composants utilisés ;
- des données conformes aux contrats ;
- le statut Drupal approprié ;
- la possibilité de revue et correction ;
- la capacité de rollback/revision lorsque le workflow le prévoit.

### 5.8 Automated checks

La preuve est indépendante de la génération.

Agency dispose déjà d’une chaîne Playwright qui couvre :

- vrai Chromium ;
- desktop/mobile ;
- DOM ;
- console ;
- erreurs page ;
- réseau same-origin ;
- screenshots et traces.

Le catalogue SDC ajoute une dimension : vérifier que les primitives utilisées sont approuvées et conformes à `DESIGN.md`.

Les prochaines validations pertinentes sont :

- admission composant ;
- accessibilité de base ;
- comportement responsive ;
- interactions ;
- conformité du DOM ;
- visual regression seulement lorsqu’elle est stable et utile ;
- liens/formulaires ;
- règles de marque/contenu selon le use case.

### 5.9 Human review et publication

L’IA propose. Les validations donnent des preuves. Un humain conserve la décision de publication par défaut.

Cette règle complète la doctrine `AI proposes -> Drupal governs -> human approves publication` déjà portée par `docs/drupal-ai-architecture.md`.

## 6. Workflow COMPOSE BEFORE CREATE

```text
brief
-> resolve context
-> list approved components
-> search existing variants
-> compose candidate
-> validate contracts
-> render in browser
-> automated checks
-> human review
-> publish
```

Si aucun composant ne couvre un besoin :

```text
gap detected
-> stop page-generation path for that primitive
-> document exact need
-> design component
-> implement component
-> validate component
-> human approval
-> admit to catalogue
-> resume composition
```

Le page-building agent ne doit pas posséder un raccourci « generate arbitrary HTML/CSS ».

## 7. Matrice de décision

| Capacité | Classification actuelle | Décision |
| --- | --- | --- |
| SDC | `USE DRUPAL` | primitive standard pour les composants gouvernés |
| Canvas visual builder | `USE DRUPAL` | adopter par ticket borné après baseline #519 |
| Canvas AI page building | `USE DRUPAL / EXTEND DRUPAL` | évaluer la capacité exacte, ne pas réimplémenter par défaut |
| Design-system awareness Canvas AI | `USE/EXTEND` | suivre upstream ; adapter seulement les gaps Agency |
| Context Control Center | `DEFER / EXPERIMENTAL` | contrat maintenant, production lorsque stable et prouvé |
| Figma comme intention visuelle | `USE` | aucune intégration technique custom requise par #518 |
| automatisation Figma -> Drupal | `DEFER / EXPERIMENTAL` | attendre un besoin/gap concret |
| Playwright CI Agency | `BUILD IN AGENCY` existant | preuve indépendante spécifique au projet à conserver |
| AI Playwright #456/#516 | `DEFER / EXPERIMENTAL` | outil d’auto-inspection dev-only ; jamais unique gate |
| Paragraphs/Twig existants | `KEEP / MIGRATE INCREMENTALLY` | pas de big-bang ; vertical slices SDC |
| moteur custom prompt -> page | `DO NOT BUILD` | supersédé par cette architecture |
| framework générique de contexte custom | `DO NOT BUILD` | attendre/étendre Drupal AI Context |
| générateur custom de composants IA | `DEFER` | seulement si un gap upstream démontré le justifie |

## 8. Comment décider d’un développement Agency

Avant tout code lié à Canvas/AI/page generation :

1. revalider la release et la documentation Drupal core concernée ;
2. revalider Drupal Canvas ;
3. revalider Drupal AI ;
4. chercher les modules contrib officiels/pertinents ;
5. vérifier sécurité et maturité ;
6. tester la capacité réelle si la documentation est insuffisante ;
7. écrire le gap en une phrase testable ;
8. classer `USE / EXTEND / BUILD / DEFER` ;
9. seulement alors ouvrir un chantier custom.

Exemple de gap valide :

> Canvas expose les composants approuvés mais ne fournit pas la règle projet permettant d’exclure automatiquement les composants non admis selon notre catalogue.

Exemple de faux gap :

> Nous savons déjà générer du HTML avec un LLM et ce serait plus rapide à coder.

## 9. Backlog réorienté

### NOW — #518

Matérialiser la décision, le design contract, les pointeurs `AGENTS.md` et la roadmap.

### NEXT — #519

Premier vertical slice :

- inventaire Paragraphs/Twig ;
- classification des composants existants ;
- 2–3 primitives SDC représentatives ;
- props/slots/variants ;
- admission ;
- Playwright ;
- responsive ;
- accessibilité de base ;
- préparation à Canvas.

### AFTER #519 — ne créer que les tickets justifiés par preuve

Ordre logique :

1. intégration Canvas sur le catalogue approuvé ;
2. validation du modèle d’admission/exposition des composants ;
3. exploitation Drupal AI/Canvas AI sur une composition bornée ;
4. réévaluation de Context Control Center ;
5. mapping Figma/tokens seulement si un workflow réel le justifie ;
6. expérimentation contrôlée de génération de pages ;
7. extension des preuves : accessibilité et visual regression lorsque les cas deviennent suffisamment stables.

Aucun ticket générique « construire un page builder Agency » ne doit être créé.

## 10. Reclassification de #456 / #516

#456 et #516 restent utiles, mais leur rôle est clarifié :

```text
AI Playwright
= inspection interne par l’agent pendant une boucle dev/proof

Playwright Agency
= validation indépendante autoritative
```

Ils ne sont pas le moteur de Governed AI Experience et ne bloquent pas #519.

## 11. Fonctionnalités custom devenues inutiles ou interdites

Cette architecture rend inutiles par principe :

- un nouveau moteur custom `prompt -> page` ;
- un générateur libre de CSS/HTML de landing page ;
- un catalogue de composants IA parallèle aux composants Drupal ;
- un framework de contexte générique Agency parallèle à Drupal AI Context ;
- une logique Canvas réimplémentée dans `FutureAi`.

Les couches custom existantes ne sont pas supprimées automatiquement. Toute suppression ou convergence conserve son propre owner et ses preuves de parité.

## 12. Future intégration Preflight

Agency doit produire des preuves structurées que Preflight pourra vérifier indépendamment :

- commit/PR exact ;
- catalogue de composants utilisé ;
- composants/variantes présents ;
- résultat Playwright ;
- captures/traces ;
- contrôles accessibilité ;
- violations de design contract ;
- validations éditoriales/brand lorsque pertinentes.

Preflight pourra ensuite :

- reproduire certains contrôles ;
- vérifier les preuves ;
- appliquer des policies indépendantes ;
- émettre un verdict de gouvernance.

Il ne doit pas devenir une seconde implémentation du page builder.

## 13. Conditions minimales avant une preuve de page generation

Une expérimentation de génération de page n’est actionnable que si :

- un catalogue SDC approuvé existe ;
- les primitives du test possèdent des contrats props/slots/variants ;
- le design contract est appliqué ;
- Canvas fonctionne avec ces primitives ;
- la capacité Canvas AI exacte est revalidée ;
- le contexte nécessaire est explicitement sélectionné ;
- le résultat reste une candidate non publiée automatiquement ;
- Playwright indépendant peut valider le rendu ;
- un humain peut revoir et refuser la sortie ;
- le test prouve qu’aucun HTML/CSS arbitraire ne contourne le catalogue.

## 14. Règle de maintenance

Les informations temporelles sur Drupal/Canvas/AI doivent être revalidées avant décision. Les invariants stratégiques restent valides jusqu’à un nouvel ADR explicite.

Une conversation ChatGPT, un prompt, un handoff ou une préférence d’agent ne peut pas remplacer cette architecture.
