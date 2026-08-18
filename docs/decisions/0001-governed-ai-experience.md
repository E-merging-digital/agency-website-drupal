# ADR-0001 — Governed AI Experience et « COMPOSE BEFORE CREATE »

Statut : **ACCEPTED**  
Date : **2026-08-18**  
Owner : **#518**  
Supersède : aucune décision antérieure  
Peut être supersédé uniquement par : **un nouvel ADR explicitement ACCEPTED**

## Contexte

Agency possède déjà une doctrine Drupal AI upstream-first dans `docs/drupal-ai-architecture.md`, un contrat de contexte gouverné dans `docs/drupal-ai-context-architecture.md`, une validation navigateur réelle dans `docs/browser-validation.md`, ainsi qu’un thème Drupal classique fondé sur des tokens CSS, Paragraphs et templates Twig.

Au moment de cette décision :

- Drupal Canvas dispose d’une release stable couverte par la politique de sécurité Drupal et compatible avec Drupal 11.3+ ;
- Single-Directory Components (SDC) est une primitive Drupal core ;
- Canvas AI sait déjà raisonner sur des composants disponibles et l’initiative Drupal AI poursuit activement la génération de pages à partir de composants, design systems et contexte ;
- Context Control Center (`ai_context`) reste en beta et ne possède pas encore de release stable supportée ;
- Agency possède une chaîne Playwright indépendante et reproductible qui doit rester autoritative pour la preuve navigateur ;
- #456 évalue séparément AI Playwright comme capacité d’auto-inspection de l’agent, et non comme remplacement de la preuve indépendante.

Références upstream à revalider lors de toute adoption importante :

- https://www.drupal.org/project/canvas
- https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components
- https://www.drupal.org/project/ai_context
- https://www.drupal.org/about/starshot/initiatives/ai/blog/the-future-of-ai-powered-web-creation-is-people-first-not-prompt-first

Les états de maturité ci-dessus sont un snapshot de décision du 18 août 2026, pas une permission permanente d’installer une version donnée.

## Décision

Agency devient une **plateforme Drupal AI/Canvas de création d’expériences gouvernées**, fondée sur un design system explicite et des composants approuvés.

Agency **ne doit pas** devenir un moteur propriétaire :

```text
prompt
-> HTML/CSS arbitraire
-> page libre
```

La trajectoire autoritative est :

```text
Figma / identité visuelle
-> DESIGN.md + design tokens
-> design system
-> SDC / Canvas components approuvés
-> Drupal AI Context + Canvas AI
-> composition gouvernée
-> validations automatiques
-> révision humaine
-> publication Drupal
```

Principe central :

> L’IA ne définit pas librement l’interface. Elle compose avec les primitives approuvées du système de design.

## Invariant architectural : COMPOSE BEFORE CREATE

Pour toute demande de page, section ou expérience :

1. inventorier les composants approuvés disponibles ;
2. tenter une composition avec ces composants ;
3. utiliser leurs props, slots et variantes existantes ;
4. ne proposer une nouvelle primitive que si le besoin ne peut raisonnablement être satisfait autrement ;
5. documenter le gap exact ;
6. concevoir et implémenter la primitive hors du flux de génération de page ;
7. faire passer la primitive par les validations d’admission ;
8. l’ajouter seulement ensuite au catalogue autorisé ;
9. reprendre la composition avec la primitive désormais approuvée.

Un agent de page building ne doit jamais contourner ce processus en générant du markup ou du CSS arbitraire.

## Responsabilités des couches

### Figma

Figma porte principalement l’intention visuelle : maquettes, variables, composants de référence, interactions et variantes.

Figma n’est pas la seule source de vérité technique. Les contrats exécutables et les contraintes d’implémentation restent versionnés dans le repository.

### `DESIGN.md`

`DESIGN.md` est le contrat durable entre designers, développeurs, agents de développement et système de composants.

Il définit notamment :

- design tokens et leur usage ;
- typographie, espaces, couleurs et responsive ;
- règles de composants et variantes ;
- conventions SDC ;
- accessibilité ;
- règles CSS ;
- critères de réutilisation ;
- critères autorisant ou interdisant la création d’un nouveau composant.

`DESIGN.md` ne doit pas dupliquer le tone of voice, les audiences, la terminologie ou les règles éditoriales qui appartiennent à `docs/drupal-ai-context-architecture.md` et, lorsqu’il sera mature, au Context Control Center.

### SDC / Canvas components

Les composants Drupal approuvés sont les **primitives exécutables autorisées**.

Les pages générées ou assistées par IA doivent être des compositions de ces primitives autant que possible. Les props, slots et variantes doivent être explicites et validables.

### Drupal AI / Canvas AI

Drupal AI / Canvas AI est le moteur privilégié pour comprendre un brief, choisir des composants, composer une structure de page, remplir les propriétés et proposer du contenu.

Agency ne développe un moteur concurrent que si un gap upstream réel, actuel et démontré subsiste après audit.

### Contexte IA gouverné

Le contexte de marque, audiences, terminologie, contraintes éditoriales, accessibilité, localisation, conformité et campagnes suit le contrat de `docs/drupal-ai-context-architecture.md`.

Tant que Context Control Center n’a pas de release stable adaptée, Agency conserve ce contrat et ses sources de vérité existantes sans reconstruire un framework générique parallèle.

### Validation

Toute génération significative doit pouvoir être prouvée par une chaîne indépendante comprenant, selon le périmètre :

- navigateur réel ;
- Playwright ;
- desktop et mobile ;
- DOM/HTML pertinent ;
- console et erreurs page ;
- réseau same-origin ;
- accessibilité ;
- conformité au catalogue de composants et à `DESIGN.md` ;
- régression visuelle lorsqu’elle apporte une preuve utile ;
- liens, formulaires et comportements ;
- règles éditoriales et de marque lorsque le contenu est généré.

La validation automatique ne remplace pas la révision humaine avant publication.

## Règle de décision : upstream first

Avant toute nouvelle fonctionnalité AI/page-builder, l’agent doit revalider les capacités actuelles de Drupal core, Drupal Canvas, Drupal AI et des modules contrib pertinents.

Chaque décision est classée :

- `USE DRUPAL`
- `EXTEND DRUPAL`
- `BUILD IN AGENCY`
- `DEFER / EXPERIMENTAL`

Ordre de préférence :

```text
USE DRUPAL
> EXTEND DRUPAL
> BUILD IN AGENCY
```

`BUILD IN AGENCY` exige un gap documenté et une justification produit. Une préférence d’implémentation ou l’habitude ne constitue pas un gap.

## Frontière Agency / Preflight

La séparation cible est explicite :

```text
Agency
= production et composition de l’expérience

Preflight
= validation, preuves et gouvernance indépendante
```

Agency doit déjà produire des preuves vérifiables et des sorties structurées. Une future intégration Preflight devra consommer ces preuves ou exécuter ses propres contrôles indépendants sans devenir le moteur de composition d’Agency.

## Conséquences

### Ce qui est désormais interdit par défaut

- un page builder propriétaire `prompt -> HTML/CSS/page` ;
- la génération libre de CSS ou de markup destinée à contourner le design system ;
- un framework générique de contexte parallèle à Drupal AI/Context Control Center ;
- une nouvelle primitive créée silencieusement pendant un flux de génération de page ;
- l’utilisation d’AI Playwright comme seule preuve d’une modification produite par le même agent ;
- l’adoption d’une dépendance alpha/beta en production sans exception et preuve dédiées.

### Ce qui reste autorisé

- faire évoluer le thème actuel par vertical slices ;
- conserver Paragraphs/Twig tant qu’une migration SDC n’apporte pas encore de valeur démontrée ;
- construire une extension Agency bornée lorsque Drupal ne couvre réellement pas le besoin ;
- expérimenter une capacité upstream immature en DDEV/dev-only avec nettoyage et preuve ;
- maintenir une validation Playwright spécifique au produit Agency.

### Existing custom AI

Cette décision ne supprime pas automatiquement `FutureAi` ni `agency_ai_translation`. Leur convergence reste gouvernée par leurs tickets dédiés. Elle interdit toutefois d’étendre ces couches pour en faire un moteur générique de page building, de composants ou de contexte si Drupal fournit déjà la primitive adéquate.

## Roadmap résultante

Séquence de référence :

```text
#518  architecture durable
-> #519 DESIGN.md appliqué + catalogue SDC gouverné + baseline Canvas
-> admission Playwright/accessibilité des primitives
-> intégration Canvas sur le catalogue approuvé
-> exploitation Drupal AI / Canvas AI
-> contexte de marque gouverné
-> expérimentation contrôlée de page generation
```

Les étapes postérieures à #519 ne justifient une nouvelle issue que lorsqu’un gap concret est suffisamment défini. Ne pas créer à l’avance une forêt de tickets spéculatifs.

## Règle de supersession

Cet ADR est `ACCEPTED` et autoritatif.

Il ne peut être remplacé par une conversation, un handoff, un prompt ou une préférence d’agent. Une évolution substantielle exige un nouvel ADR qui :

1. référence explicitement ADR-0001 ;
2. explique ce qui change et pourquoi ;
3. porte le statut `ACCEPTED` ;
4. indique explicitement qu’il supersède tout ou partie d’ADR-0001.
