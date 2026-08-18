# Governed AI Experience — architecture cible

Statut : **SOURCE D’ARCHITECTURE ACTIVE**  
Décision : `docs/decisions/ADR-001-governed-ai-experience.md`  
Issue : #518  
Parents : #3, #32  
Date de cadrage : 2026-08-18

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

## 3. État réel du repository au 18 août 2026

### Déjà présent

- Drupal 11.4.x ;
- Drupal AI stable dans le projet ;
- thème custom `emerging_digital` ;
- Paragraphs et templates Twig explicites ;
- tokens CSS dans `web/themes/custom/emerging_digital/css/base.css` ;
- validation Playwright réelle desktop/mobile ;
- Playwright MCP et Chrome DevTools MCP disponibles sur le runner ;
- preuve DEV-ONLY de `drupal/ai_playwright` ;
- contrat de contexte partagé dans `docs/drupal-ai-context-architecture.md`.

### Pas encore présent

- intégration Drupal Canvas dans le produit Agency ;
- catalogue SDC Agency gouverné ;
- catalogue d’admission de composants pour composition IA ;
- intégration technique Figma ;
- validation automatique de conformité à un catalogue SDC ;
- Context Control Center en production ;
- génération de pages Canvas AI activée comme capacité produit.

Ce document ne doit jamais présenter un élément de la seconde liste comme une
capacité déjà livrée.

## 4. Classification upstream-first

Avant tout chantier, revalider les sources Drupal officielles et appliquer la
classification suivante.

| Capacité | Classification cible | Décision Agency |
| --- | --- | --- |
| Single Directory Components | `USE DRUPAL` | API de composants cible ; aucune réimplémentation custom de framework de composants. |
| Drupal Canvas — composition visuelle | `USE DRUPAL` | moteur de composition privilégié une fois la bibliothèque Agency prête et le vertical slice prouvé. |
| Canvas AI — page building | `DEFER / EXPERIMENTAL` puis `USE DRUPAL` si preuve suffisante | moteur IA privilégié ; ne pas construire un concurrent pendant l’évaluation upstream. |
| Métadonnées design system pour Canvas AI | `EXTEND DRUPAL` si nécessaire | compléter les métadonnées des composants Agency, pas créer un format parallèle générique. |
| Context Control Center | `DEFER / EXPERIMENTAL` | suivre #387 ; ne pas reconstruire CCC dans Agency. |
| Drupal AI | `USE DRUPAL` | abstraction IA par défaut selon `docs/drupal-ai-architecture.md`. |
| AI Playwright | `DEFER / EXPERIMENTAL` en production ; usage DEV-ONLY prouvé | auto-inspection complémentaire, jamais gate unique. |
| Playwright Test indépendant Agency | `BUILD IN AGENCY` justifié | preuve indépendante spécifique au projet ; futur point d’intégration avec Preflight. |
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

Le catalogue d’admission sera matérialisé dans #519. Il doit rester dérivable du
code et ne pas devenir une seconde implémentation des composants.

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

À mesure que #519 matérialise le catalogue :

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

#516 ne doit pas évoluer en moteur générique de modification de pages. Sa boucle
agentique ne redevient prioritaire qu’après le contrat composants/SDC de #519 et
doit opérer sur des primitives autorisées.

## 12. Roadmap de convergence

Ordre architectural :

1. **#518 — architecture durable** : ADR, `DESIGN.md`, règles agents.
2. **#519 — design system exécutable** : inventaire, vertical slice SDC,
   admission gouvernée.
3. **Validation** : renforcer l’accessibilité et la conformité composants dans la
   preuve navigateur existante, sans créer un second framework de test.
4. **Canvas** : vertical slice de composition visuelle avec les SDC approuvés.
5. **Canvas AI** : pilote borné de sélection/composition de ces mêmes composants.
6. **#387 / contexte** : brancher le contexte de marque gouverné lorsque la
   maturité upstream le permet ; ne pas dupliquer CCC.
7. **Page generation contrôlée** : brief -> composition candidate -> preuves ->
   revue humaine ; aucune génération libre de markup.
8. **Preflight** : préparer/brancher la validation indépendante lorsque son
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