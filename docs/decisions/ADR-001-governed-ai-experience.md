# ADR-001 — Governed AI Experience

- **Statut : ACCEPTED**
- **Date : 2026-08-18**
- **Issue : #518**
- **Parents stratégiques : #3, #32**
- **Supersède : aucune décision**

## 1. Contexte

Agency dispose déjà d’un socle Drupal solide : contenu structuré, traductions,
révisions, permissions, thème custom, Paragraphs, Drupal AI, validation
navigateur Playwright et règles de gouvernance. Le projet a également démontré
une capacité DEV-ONLY d’auto-inspection via AI Playwright.

En parallèle, l’écosystème Drupal a suffisamment évolué pour que la création de
pages assistée par IA ne doive plus être traitée comme un problème à résoudre
par un moteur propriétaire Agency. Au 18 août 2026 :

- Drupal Canvas publie une branche stable compatible Drupal 11 ;
- Single Directory Components (SDC) fait partie du système de rendu de Drupal
  core ;
- l’initiative Drupal AI travaille explicitement sur la construction de pages
  Canvas à partir de composants, de métadonnées de design system et de contexte
  gouverné ;
- Context Control Center reste une capacité upstream à suivre et à revalider
  avant adoption production ;
- certaines opérations Canvas AI restent en évolution et doivent donc être
  prouvées localement avant généralisation.

Références upstream au moment de la décision :

- https://www.drupal.org/project/canvas
- https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components/about-single-directory-components
- https://www.drupal.org/project/ai_initiative/issues/3547195
- https://www.drupal.org/project/canvas/issues/3541783
- https://www.drupal.org/project/ai_context

Ces références sont temporelles. Leur état doit être revalidé avant toute
installation ou décision de maturité.

## 2. Décision

Agency est une **plateforme Drupal de création d’expériences gouvernées**.

Agency **ne développe pas** un moteur générique propriétaire :

```text
prompt -> HTML/CSS/page arbitraire
```

La cible est :

```text
Figma / identité visuelle
        |
        v
DESIGN.md / design tokens
        |
        v
Design System
        |
        v
SDC / Canvas Component Library approuvée
        |
        +-----------------------+
        |                       |
        v                       v
Drupal AI Context           Canvas AI
        |                       |
        +-----------+-----------+
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

Drupal reste autoritatif pour les entités, permissions, traductions, révisions,
workflows et publication.

## 3. Invariant — COMPOSE BEFORE CREATE

Toute demande de page ou d’expérience suit cet ordre :

1. rechercher les composants approuvés existants ;
2. essayer une composition avec ces composants ;
3. utiliser leurs variantes, props et slots existants ;
4. ne proposer une nouvelle primitive que si le besoin ne peut raisonnablement
   pas être couvert ;
5. documenter le gap exact ;
6. concevoir, implémenter, tester et faire approuver cette primitive séparément ;
7. seulement après admission, rendre la primitive disponible à la composition
   humaine ou IA.

Une capacité de page building, humaine ou IA, ne doit pas pouvoir contourner ce
processus en générant librement du markup ou du CSS ad hoc.

## 4. Priorité de solution

Avant toute nouvelle fonctionnalité IA, Canvas ou page-builder, le ticket doit
classifier explicitement la solution :

```text
USE DRUPAL
EXTEND DRUPAL
BUILD IN AGENCY
DEFER / EXPERIMENTAL
```

L’ordre de préférence est :

```text
USE DRUPAL > EXTEND DRUPAL > BUILD IN AGENCY
```

`BUILD IN AGENCY` exige un gap Drupal réel, documenté et démontré. Une absence
de familiarité avec une capacité upstream n’est pas un gap.

`DEFER / EXPERIMENTAL` s’applique lorsqu’une capacité est pertinente mais pas
suffisamment mûre, security-covered ou prouvée pour le contexte visé.

## 5. Responsabilités

### Figma

Figma exprime l’intention visuelle : maquettes, variables, composants de
référence, variantes et interactions. Figma n’est pas la seule source de vérité
technique et ne peut pas imposer directement du code runtime.

### DESIGN.md

`DESIGN.md` est le contrat durable entre design, développement, agents et
composants. Il définit les tokens, règles frontend, conventions de composants,
accessibilité, responsive, réutilisation et critères de création d’une nouvelle
primitive.

Il ne duplique pas le tone of voice, les audiences, les règles éditoriales ou
les politiques IA, qui appartiennent au contexte gouverné et aux documents
spécialisés.

### SDC / Canvas components

Les SDC approuvés constituent la cible des **primitives exécutables autorisées**.
Leur API est explicite via props, slots et variantes. La transition depuis les
Paragraphs/Twig existants se fait progressivement, par vertical slices, sans
refonte big-bang.

### Drupal AI / Canvas AI

Drupal AI et Canvas AI sont le moteur privilégié pour interpréter un brief,
sélectionner des composants, proposer une structure, renseigner des propriétés
et composer une page candidate lorsque les capacités sont suffisamment mûres et
prouvées.

Agency ne construit pas un orchestrateur concurrent sauf gap démontré.

### Contexte IA Drupal

Le contexte de marque, audiences, terminologie, règles éditoriales, conformité,
localisation et contraintes propres aux use cases suit
`docs/drupal-ai-context-architecture.md`. `DESIGN.md` ne devient pas une seconde
base éditoriale.

### Validation

Les validations comprennent, selon l’impact : tests techniques, vrai navigateur,
responsive, console/réseau, DOM pertinent, accessibilité, conformité aux
composants/tokens, régression visuelle, liens, formulaires et règles éditoriales.

AI Playwright peut fournir des « yeux » à un agent en environnement borné. Il ne
remplace jamais la preuve indépendante décrite dans `docs/browser-validation.md`.

## 6. Frontière Agency / Preflight

La séparation cible est :

```text
Agency
= production et composition de l’expérience Drupal

Preflight
= validation, preuves et gouvernance indépendante
```

Agency doit produire des candidats et des preuves machine-readable suffisamment
propres pour qu’une future intégration Preflight puisse les vérifier sans
partager le même mécanisme de génération.

Le générateur et le validateur indépendant ne doivent pas devenir une seule
source de vérité.

## 7. Conséquences

### Positives

- moins de code propriétaire générique ;
- meilleur alignement avec Drupal core, Canvas et Drupal AI ;
- composants réutilisables et testables ;
- pages IA plus cohérentes avec l’identité visuelle ;
- validation plus indépendante et auditable ;
- possibilité de faire évoluer Figma, Canvas AI ou le provider IA sans changer
  la doctrine de gouvernance.

### Coûts assumés

- formaliser le design system existant avant la génération de pages ;
- convertir progressivement les primitives pertinentes vers SDC ;
- maintenir un catalogue d’admission et des preuves de composants ;
- accepter que certaines fonctions Canvas AI restent expérimentales jusqu’à
  preuve suffisante.

## 8. Ce que cette ADR n’autorise pas

Cette décision n’autorise pas à elle seule :

- l’installation ou l’activation production de Canvas/Canvas AI ;
- l’installation production d’une dépendance alpha/beta ;
- la génération automatique de nouveaux composants ;
- l’auto-publication ;
- une mutation production autonome ;
- un accès agentique aux secrets, utilisateurs ou permissions ;
- une migration big-bang des Paragraphs/Twig ;
- un moteur Agency de génération HTML/CSS libre.

Chaque activation reste soumise aux règles de `docs/drupal-ai-architecture.md`,
aux tickets dédiés et aux preuves du projet.

## 9. Supersession

Cette ADR a statut **ACCEPTED** et est autoritative pour les décisions de design
system, composants, Canvas et génération de pages.

Elle ne peut être remplacée que par une **nouvelle ADR explicite**, avec :

- un statut clair ;
- le lien vers cette ADR ;
- la motivation du changement ;
- les conséquences et le plan de migration.

Une conversation ChatGPT, un prompt de ticket ou un document non décisionnel ne
peut pas la superséder.