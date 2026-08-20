# Governed AI Experience — architecture cible

Statut : **SOURCE D’ARCHITECTURE ACTIVE**  
Décision : `docs/decisions/ADR-001-governed-ai-experience.md`  
Issue : #518  
Parents : #3, #32  
Date de cadrage : 2026-08-18  
Dernière revalidation upstream : 2026-08-20

## 1. Mission

Agency doit démontrer qu’un site Drupal peut devenir une plateforme de création
d’expériences assistée par IA **sans abandonner le design system, la structure,
les permissions, les révisions ni la validation humaine**.

Le produit cible n’est pas un générateur de pages libre. Le produit cible est un
système de **composition gouvernée**.

```text
brief
-> contexte autorisé
-> catalogue de composants approuvés
-> composition candidate
-> preuves automatiques
-> revue humaine
-> publication Drupal
```

L’invariant de décision est : **COMPOSE BEFORE CREATE**.

## 2. Architecture

```text
Figma
  |
  v
DESIGN.md / tokens
  |
  v
Design System
  |
  v
SDC / Canvas Component Library
  |
  +---------------------+
  |                     |
  v                     v
Drupal AI Context    Canvas AI
  |                     |
  +----------+----------+
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

La future frontière inter-projets est :

```text
Agency
  produit / compose / prévisualise
          |
          v
preuves et candidat structurés
          |
          v
Preflight
  vérifie indépendamment / gouverne les preuves
```

Preflight ne doit pas être requis pour rendre une page dans Agency. Il constitue
une future couche indépendante de vérification et de gouvernance.

## 3. État réel du repository au 20 août 2026

### Déjà présent

- Drupal 11.4.x ;
- Drupal AI stable `1.4.6` dans le projet ;
- thème custom `emerging_digital` ;
- Paragraphs et templates Twig explicites ;
- tokens CSS dans `web/themes/custom/emerging_digital/css/base.css` ;
- catalogue SDC gouverné et admission machine-readable issus de #519 ;
- Hero, CTA et Trust list admis pour composition ;
- Drupal Canvas `1.10.1` verrouillé par Composer et configuration Canvas/Media versionnée ;
- allowlist Canvas dérivée du catalogue Agency approuvé ;
- Canvas Page de preuve core-native `/canvas-governed-sdc-baseline` ;
- validation Playwright réelle desktop/mobile ;
- Playwright MCP et Chrome DevTools MCP disponibles sur le runner ;
- preuve DEV-ONLY de `drupal/ai_playwright` ;
- contrat de contexte partagé dans `docs/drupal-ai-context-architecture.md` ;
- #530 / PR #533 matérialise un pilote Canvas AI borné avec `ai_agents 1.3.4`,
  en attente de la preuve provider-backed et de la revue humaine finale.

### Pas encore présent comme capacité production

- intégration technique Figma ;
- Context Control Center en production ;
- génération de pages Canvas AI admise comme capacité produit ;
- runtime Outside AI/MCP production.

Depuis la revalidation du 20 août 2026, Context Control Center `1.0.0-beta3` est
suffisamment aligné avec le contrat Agency pour un **pilote DDEV/local sous
#387**, mais il ne possède toujours aucune release stable supportée. Ce pilote
ne doit donc jamais être présenté comme une capacité production déjà livrée.

## 4. Classification upstream-first

Avant tout chantier, revalider les sources Drupal officielles et appliquer la
classification suivante.

| Capacité | Classification cible | Décision Agency |
| --- | --- | --- |
| Single Directory Components | `USE DRUPAL` | API de composants cible ; aucune réimplémentation custom de framework de composants. |
| Drupal Canvas — composition visuelle | `USE DRUPAL` | baseline #526 prouvée avec les composants Agency approuvés. |
| Canvas AI — page building | `USE DRUPAL` via pilote borné #530 | moteur IA privilégié ; garder Page Builder + composants existants ; ne pas élargir le scope parce que l'upstream ajoute d'autres actions. |
| Métadonnées design system pour Canvas AI | `EXTEND DRUPAL` si nécessaire | compléter les métadonnées des composants Agency, pas créer un format parallèle générique. |
| Context Control Center | `PROMOTE TO PILOT / PREPARE FOR RC` en DDEV ; `WAIT FOR STABLE` en production | suivre #387 ; tester beta3 sur un scope minimal ; ne pas reconstruire CCC dans Agency et ne pas migrer aveuglément les sources de vérité. |
| Drupal AI | `USE DRUPAL` | abstraction IA par défaut selon `docs/drupal-ai-architecture.md`. |
| AI Playwright | `DEFER / EXPERIMENTAL` en production ; usage DEV-ONLY prouvé | auto-inspection complémentaire ; `browser_preview` publié reste la capacité admise, jamais gate unique. |
| Playwright Test indépendant Agency | `BUILD IN AGENCY` justifié | preuve indépendante spécifique au projet ; futur point d’intégration avec Preflight. |
| Tool API / MCP | `PROMOTE TO PILOT LOCAL` read-only ; `WAIT FOR STABLE` en production | suivre #390 ; STDIO, least privilege, zéro write/secret ; ne pas créer de framework MCP custom. |
| Figma -> code/page automatique | `DEFER / EXPERIMENTAL` | Figma fournit l’intention ; ne pas créer de pipeline custom tant qu’un gap Drupal réel n’est pas prouvé. |
| Générateur propriétaire prompt -> HTML/CSS | **interdit par ADR-001** | ne pas construire. |

La classification n’est pas un snapshot de versions. Une dépendance ou une
activation production exige toujours une revalidation de maturité, compatibilité
et security advisory coverage.

## 5. Flux — COMPOSE BEFORE CREATE

Pour une demande de page :

```text
brief
-> identifier audience/contexte autorisé
-> interroger le catalogue de composants approuvés
-> choisir props/slots/variantes existantes
-> produire la composition
-> valider
```

Si la composition ne couvre pas raisonnablement le besoin :

```text
gap explicite
-> issue de nouvelle primitive
-> design / contrat
-> implémentation SDC
-> tests composant
-> accessibilité / responsive
-> browser proof
-> revue humaine
-> admission au catalogue
-> seulement ensuite composition de page
```

### Règle de refus

Une IA ne peut pas considérer « je peux générer du HTML/CSS » comme une raison
suffisante pour créer une primitive. La question est : **le besoin fonctionnel
ou visuel ne peut-il réellement pas être couvert par le catalogue et ses
variantes ?**

## 6. Catalogue de composants gouverné

Le catalogue d’admission est matérialisé depuis #519 dans
`docs/design-system/component-catalog.yml`. Il reste dérivable du code et ne
devient pas une seconde implémentation des composants.

Un composant admis devra au minimum avoir :

- identifiant stable ;
- source SDC ;
- rôle fonctionnel ;
- props/slots contractuels ;
- variantes autorisées ;
- dépendances de tokens ;
- contraintes responsive ;
- contraintes accessibilité ;
- statut d’admission ;
- preuve de validation ;
- règle de dépréciation/migration si nécessaire.

États logiques recommandés :

```text
candidate -> approved -> deprecated -> retired
```

Seuls les composants `approved` sont disponibles à une composition IA destinée
à produire un candidat publiable.

## 7. Figma et identité visuelle

Figma peut décrire :

- la maquette ;
- les variables et décisions visuelles ;
- des composants de référence ;
- les états/variantes ;
- les interactions ;
- les intentions responsive.

Le passage Figma -> Drupal ne doit pas être un export aveugle de markup.

La traduction vers le runtime suit :

```text
Figma
-> décision/token/variant identifiée
-> DESIGN.md ou token canonique
-> composant SDC
-> validation Drupal/Playwright
```

Une future intégration Figma MCP ou Canvas AI peut accélérer cette boucle, mais
elle doit alimenter le même contrat, pas créer une deuxième voie de production.

## 8. DESIGN.md et contexte IA

`DESIGN.md` possède les règles **visuelles et d’implémentation frontend**.

Il ne possède pas :

- le positionnement commercial ;
- le tone of voice éditorial ;
- les personas/audiences ;
- les règles de conformité métier ;
- les règles propres au chatbot ;
- les prompts provider.

Ces éléments suivent `docs/drupal-ai-context-architecture.md`, les sources SEO et
les entités Drupal correspondantes.

La composition de page peut consommer les deux familles :

```text
contrat visuel / composant
+
contexte éditorial gouverné
```

sans les fusionner en un fichier monolithique.

## 9. Candidat de page et publication

Une page générée ou fortement assistée par IA est toujours un **candidat**.

Le moteur de composition doit préférer des données structurées : identifiants de
composants, props, slots, ordre, contexte d’entité et métadonnées. Le markup final
est produit par les primitives Drupal approuvées.

Le cycle cible est :

```text
AI proposes composition
-> Drupal materializes a draft/revision candidate
-> automated evidence
-> human reviews
-> Drupal publishes
```

Pas d’auto-publication implicite.

## 10. Validation automatique

Pour toute génération importante, sélectionner les contrôles pertinents :

### Technique

- schémas SDC / props ;
- rendu Drupal sans erreur ;
- configuration propre ;
- tests PHP/JS concernés.

### Navigateur

- Chromium réel ;
- desktop/mobile et viewports pertinents ;
- DOM/sémantique ;
- erreurs console/page ;
- erreurs réseau same-origin ;
- interactions ;
- liens ;
- formulaires ;
- captures et traces.

Source : `docs/browser-validation.md`.

### Accessibilité

La trajectoire doit aller au-delà des simples locators sémantiques existants :

- structure des titres ;
- nom accessible ;
- clavier/focus ;
- contrastes ;
- formulaires ;
- absence de débordement ;
- contrôles automatisables pertinents ;
- revue humaine lorsque l’automatisation ne suffit pas.

Cible de conception : WCAG 2.2 AA, sauf exigence de ticket plus stricte.

### Design system

Avec le catalogue matérialisé par #519 :

- aucun composant non approuvé dans le candidat ;
- props/variantes conformes ;
- usage des tokens ;
- absence de CSS ad hoc créé par le page builder ;
- régression visuelle lorsque la surface le justifie.

### Éditorial / marque

Selon le use case :

- langue ;
- terminologie ;
- règles de marque ;
- assertions factuelles ;
- SEO/AEO ;
- conformité ;
- absence d’auto-publication.

Ces contrôles consomment le contexte gouverné, pas `DESIGN.md` seul.

## 11. Rôle d’AI Playwright

Le pilote #456 a démontré l’intérêt de donner des « yeux » navigateur à un agent
Drupal dans un environnement DDEV borné.

Classification stratégique :

```text
AI Playwright
= boucle d’auto-inspection / diagnostic DEV-ONLY

Playwright Test Agency
= preuve indépendante reproductible
```

#516 reste expérimental même si les gates composants/Canvas sont désormais
satisfaits. Il doit opérer uniquement sur des primitives autorisées et ne doit
pas anticiper comme disponibles des tools upstream non publiés.

## 12. Roadmap de convergence

État architectural revalidé :

1. **#518 — architecture durable** : TERMINÉ — ADR, `DESIGN.md`, règles agents.
2. **#519 — design system exécutable** : TERMINÉ — catalogue SDC gouverné.
3. **Validation** : EN PLACE — Browser Validation indépendante, responsive et
   admission des composants ; continuer à renforcer seulement sur besoin réel.
4. **#526 / Canvas** : TERMINÉ — baseline Canvas `1.10.1` sur les SDC approuvés.
5. **#530 / Canvas AI** : ACTIF — pilote borné Page Builder + composants
   existants ; ne pas ajouter edit/move/delete/Component Agent au scope.
6. **#387 / contexte** : PILOT DDEV autorisé avec CCC beta3 ; production reste
   `WAIT FOR STABLE` ; commencer par `brand.voice`, aucune migration big-bang.
7. **#390 / Outside AI** : PILOT LOCAL read-only réouvert ; STDIO d'abord,
   aucun MCP custom, production reste `WAIT FOR STABLE`.
8. **Page generation contrôlée** : après preuves précédentes, brief ->
   composition candidate -> preuves -> revue humaine ; aucune génération libre
   de markup.
9. **Preflight** : préparer/brancher la validation indépendante lorsque son
   contrat d’intégration est prêt.

Cette séquence peut être ajustée par ticket, mais **aucune étape ultérieure ne
justifie de contourner COMPOSE BEFORE CREATE**.

## 13. Questions à poser avant tout nouveau développement

Avant de coder une nouvelle fonctionnalité frontend/IA/page-builder :

1. existe-t-elle déjà dans Drupal core ?
2. existe-t-elle dans Canvas ?
3. existe-t-elle dans Drupal AI / contrib officiel pertinent ?
4. quelle est la maturité/security coverage actuelle ?
5. quel est le gap Agency exact ?
6. peut-on le résoudre par configuration ou métadonnées ?
7. peut-on étendre une primitive Drupal plutôt que créer un moteur parallèle ?
8. quelle preuve indépendante valide le résultat ?
9. quelle revue humaine précède la publication ?

Si le gap ne peut pas être formulé précisément, le développement custom n’est
pas autorisé.
