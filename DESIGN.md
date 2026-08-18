# DESIGN.md — Contrat du design system Agency

Statut : **AUTHORITATIVE DESIGN CONTRACT**  
Décision parente : `docs/decisions/0001-governed-ai-experience.md`  
Owner initial : #518  
Vertical slice d’implémentation : #519

## 1. Rôle

Ce document est le contrat durable entre :

- designers ;
- développeurs ;
- agents de développement ;
- thème Drupal ;
- composants SDC ;
- composants exposés à Drupal Canvas.

Il décrit **ce qui est autorisé et attendu dans l’interface**, pas le contexte éditorial général du site.

Pour le tone of voice, les audiences, la terminologie, le positionnement, les contraintes de campagne et le contexte destiné aux fonctionnalités IA, lire `docs/drupal-ai-context-architecture.md`.

Pour les choix d’architecture IA, lire `docs/drupal-ai-architecture.md` et `docs/decisions/0001-governed-ai-experience.md`.

## 2. Invariant : COMPOSE BEFORE CREATE

Toute nouvelle page, section ou expérience doit d’abord être construite à partir des primitives approuvées.

Ordre obligatoire :

1. rechercher les composants existants ;
2. utiliser leurs props et slots ;
3. utiliser leurs variantes existantes ;
4. composer ;
5. seulement si le besoin reste non couvert, documenter le gap ;
6. créer la nouvelle primitive dans un flux séparé ;
7. la valider ;
8. l’admettre dans le catalogue ;
9. reprendre la composition.

Une IA ne peut pas résoudre un manque de composant en produisant silencieusement du HTML/CSS arbitraire.

## 3. Figma et source de vérité

### Figma porte l’intention visuelle

Figma peut représenter :

- les maquettes ;
- les variables et tokens de design ;
- les composants de référence ;
- les variantes ;
- les états et interactions ;
- la direction responsive.

### Le repository porte le contrat technique

Figma n’est pas la seule source de vérité technique.

Les éléments suivants doivent rester versionnés dans le repository :

- tokens réellement consommés par le code ;
- contrats de composants ;
- props/slots/variants ;
- sémantique HTML ;
- exigences d’accessibilité ;
- règles CSS ;
- critères de validation et d’admission ;
- preuves navigateur.

Une automatisation Figma -> code n’est pas présumée. Elle sera évaluée seulement lorsqu’un besoin concret existe et ne devra pas contourner la revue du design system.

## 4. Baseline actuelle des design tokens

La source runtime actuelle est :

```text
web/themes/custom/emerging_digital/css/base.css
```

Cette baseline ne constitue pas encore un système de tokens complet ; elle est le point de départ autoritatif à faire évoluer sans duplication.

### Couleurs

| Token | Valeur actuelle | Usage |
| --- | --- | --- |
| `--color-bg` | `#ffffff` | fond principal |
| `--color-surface` | `#f6f7fb` | surfaces secondaires |
| `--color-text` | `#111827` | texte principal |
| `--color-muted` | `#4b5563` | texte secondaire |
| `--color-border` | `#d1d5db` | bordures |
| `--color-primary` | `#005bbb` | action/lien principal |
| `--color-primary-contrast` | `#ffffff` | contenu sur primaire |

Le focus visible utilise actuellement `#1d4ed8`. Lors d’une prochaine évolution du design system, décider explicitement s’il doit devenir un token sémantique avant de le réutiliser ailleurs.

### Typographie

| Token | Valeur actuelle |
| --- | --- |
| `--font-body` | `"Inter", "Segoe UI", Roboto, Arial, sans-serif` |
| `--font-heading` | `"Poppins", "Segoe UI", Roboto, Arial, sans-serif` |

Règles :

- le corps utilise `--font-body` ;
- les titres utilisent `--font-heading` ;
- les tailles et échelles typographiques réutilisées doivent devenir des tokens ou conventions explicites avant prolifération ;
- ne pas introduire une nouvelle famille de polices dans un composant isolé sans décision de design system.

### Espacements

| Token | Valeur |
| --- | ---: |
| `--space-1` | `0.25rem` |
| `--space-2` | `0.5rem` |
| `--space-3` | `0.75rem` |
| `--space-4` | `1rem` |
| `--space-5` | `1.5rem` |
| `--space-6` | `2rem` |
| `--space-7` | `3rem` |

Les composants doivent privilégier cette échelle. Un nouvel espacement arbitraire récurrent doit être normalisé au niveau du système plutôt que copié dans plusieurs composants.

### Conteneur, rayons et ombres

| Token | Valeur |
| --- | ---: |
| `--container-width` | `72rem` |
| `--radius-sm` | `0.25rem` |
| `--radius-md` | `0.5rem` |
| `--radius-lg` | `0.85rem` |
| `--shadow-sm` | `0 1px 2px rgb(0 0 0 / 8%)` |

La classe `.page-container` / `.ed-container` utilise actuellement le conteneur global et réduit sa marge latérale sous `30rem`.

## 5. Responsive

Le design doit être conçu comme un système responsive, pas comme deux captures indépendantes.

Baseline de preuve navigateur actuelle :

```text
desktop : 1440 x 900
mobile  : 390 x 844
```

Ces viewports sont les points de preuve Playwright actuels ; ils ne définissent pas à eux seuls tous les breakpoints CSS du produit.

Règles :

- mobile et desktop doivent rester fonctionnels sans débordement horizontal ;
- les composants doivent s’adapter à leur conteneur et ne pas supposer une largeur globale fixe ;
- les breakpoints doivent répondre à un besoin de layout démontré ;
- éviter les valeurs media-query propres à un seul composant lorsqu’une convention partagée existe ;
- une nouvelle primitive doit documenter ses comportements responsive dans son contrat ou ses exemples de référence.

## 6. Architecture des composants

### 6.1 Primitive approuvée

Une primitive approuvée est un composant qui possède :

- un rôle d’interface clair ;
- une sémantique explicite ;
- des entrées contractuelles ;
- des variantes bornées ;
- des règles responsive ;
- des exigences d’accessibilité ;
- une preuve de rendu ;
- un owner dans le design system.

À mesure de #519, les composants réutilisables doivent converger vers SDC par vertical slices.

### 6.2 Conventions SDC

Pour un composant SDC :

- utiliser un nom machine stable et descriptif ;
- déclarer explicitement les props dans `*.component.yml` ;
- utiliser des slots pour le contenu composé lorsque la structure le justifie ;
- typer et contraindre les props lorsque le schéma le permet ;
- préférer une variante explicite à une copie quasi identique du composant ;
- éviter les props qui injectent du HTML/CSS arbitraire ;
- ne pas utiliser une prop générique comme échappatoire au contrat du composant ;
- préserver la possibilité pour Drupal/Canvas de comprendre et valider le composant.

Le composant doit être consommable sans dépendre d’un prompt particulier.

### 6.3 Props

Une prop représente une donnée ou un choix borné du composant.

Bon exemple :

```text
heading
summary
image
cta_label
cta_url
variant = primary | neutral
```

Mauvais exemple :

```text
custom_html
custom_css
arbitrary_classes
prompt_generated_markup
```

Toute prop de classe CSS libre doit être justifiée par un besoin non couvert par des variantes explicites.

### 6.4 Slots

Utiliser un slot lorsque le composant accueille une composition d’autres primitives ou un contenu structurel réellement variable.

Ne pas transformer chaque champ texte en slot par réflexe.

Un slot doit conserver :

- une intention claire ;
- une cardinalité raisonnable ;
- des composants enfants compatibles lorsque cette contrainte est nécessaire ;
- une structure accessible.

### 6.5 Variantes

Une variante est autorisée lorsqu’elle représente un état visuel ou fonctionnel cohérent d’une même primitive.

Créer une nouvelle variante plutôt qu’un nouveau composant si :

- la sémantique reste identique ;
- la structure reste majoritairement identique ;
- le changement concerne une présentation ou un état borné.

Créer un nouveau composant seulement lorsque le rôle, la structure ou le comportement diffère réellement.

## 7. Règles CSS

### Obligatoire

- réutiliser les tokens avant d’introduire une valeur récurrente ;
- conserver des sélecteurs compréhensibles et bornés ;
- garder les styles d’une primitive aussi proches que possible de cette primitive lors de la migration SDC ;
- utiliser les pseudo-classes d’état nécessaires (`:hover`, `:focus-visible`, etc.) ;
- préserver la cascade sans dépendances implicites fragiles ;
- tester les changements visibles dans un vrai navigateur.

### À éviter

- CSS inline généré par IA ;
- `style` arbitraire injecté depuis une prop ;
- duplication de règles de composant dans plusieurs fichiers ;
- sélecteurs globaux destinés à corriger un seul composant ;
- valeurs magiques récurrentes qui devraient devenir des tokens ;
- `!important` comme mécanisme normal de composition ;
- classes inventées par un agent au moment de générer une page.

### Transition depuis l’existant

Le thème contient actuellement :

```text
css/base.css
css/components.css
css/layout.css
css/blog.css
```

#519 doit migrer par vertical slices. Aucun big-bang CSS n’est demandé. Un composant peut rester temporairement dans la structure actuelle tant que son contrat et sa future trajectoire sont clairs.

## 8. Accessibilité

L’accessibilité est un critère d’admission d’un composant, pas une correction finale.

Chaque primitive doit respecter, selon son rôle :

- HTML sémantique ;
- ordre de titres cohérent avec le contexte d’utilisation ;
- navigation clavier ;
- focus visible ;
- nom accessible des contrôles ;
- contraste suffisant ;
- texte alternatif pertinent pour les images de contenu ;
- absence d’information portée uniquement par la couleur ;
- états et erreurs compréhensibles ;
- réduction ou suppression d’animations problématiques lorsque nécessaire ;
- contenu utilisable au zoom et sur petit écran.

Un composant interactif qui ne peut pas être utilisé au clavier n’est pas admissible.

Les contrôles automatiques complètent une revue sémantique humaine ; ils ne garantissent pas à eux seuls la conformité WCAG complète.

## 9. Validation et admission au catalogue

Une primitive n’est disponible pour la composition IA/Canvas qu’après admission.

Checklist minimale :

1. besoin et rôle documentés ;
2. vérification qu’aucune primitive/variante existante ne couvre le besoin ;
3. contrat props/slots/variants explicite ;
4. tokens et CSS conformes à ce document ;
5. rendu desktop et mobile validé ;
6. absence de débordement évident ;
7. console/page errors sans nouvelle erreur liée au composant ;
8. comportement interactif testé si applicable ;
9. accessibilité de base validée ;
10. capture ou autre preuve visuelle examinée ;
11. tests techniques pertinents verts ;
12. revue humaine ;
13. admission explicite dans le catalogue autorisé.

Lorsque la régression visuelle apporte une valeur stable et déterministe, elle peut être ajoutée comme preuve. Elle n’est pas imposée aveuglément à tous les composants.

## 10. Critères de création d’un nouveau composant

### Création autorisée si

- aucun composant existant ne couvre raisonnablement le rôle ;
- une variante existante rendrait le contrat incohérent ;
- le besoin est réutilisable au-delà d’un cas purement accidentel ;
- le composant peut avoir un contrat clair ;
- son coût de maintenance est justifié ;
- il passe la chaîne d’admission.

### Création interdite si

- une composition existante suffit ;
- une variante existante suffit ;
- le seul motif est d’imiter une sortie IA ponctuelle ;
- le composant encapsule du HTML/CSS arbitraire ;
- il contourne des contraintes Canvas/SDC ;
- il duplique une primitive upstream sans gap démontré ;
- il n’a pas de stratégie accessible/responsive.

## 11. Design system et IA

L’IA peut :

- rechercher des primitives ;
- choisir des variantes ;
- renseigner des props ;
- composer des slots ;
- proposer une structure de page ;
- proposer du contenu compatible avec les contraintes de contexte.

L’IA ne peut pas :

- élargir implicitement le catalogue ;
- inventer un langage visuel parallèle ;
- générer du CSS de page pour contourner les tokens ;
- introduire une nouvelle primitive pendant une simple demande de composition ;
- s’auto-approuver.

## 12. Relation avec Drupal Canvas

Canvas doit consommer un ensemble de composants approuvés et compréhensibles.

Le design system reste plus large que Canvas : il définit les règles et l’admission. Canvas est un environnement de composition et d’édition, pas l’autorité qui décide à lui seul quelles primitives sont valides.

Une adoption Canvas doit donc :

- partir du catalogue approuvé ;
- ne pas exposer des composants internes non prêts ;
- conserver les contrats SDC ;
- préserver les permissions et workflows Drupal ;
- rester vérifiable par la chaîne Playwright indépendante.

## 13. Relation avec le contexte IA

Ne pas ajouter ici :

- tone of voice détaillé ;
- personas ;
- audience PME/ASBL/institution ;
- terminologie FR/EN ;
- règles de campagne ;
- contraintes réglementaires éditoriales ;
- prompts de génération.

Ces éléments suivent `docs/drupal-ai-context-architecture.md` et, lorsque la maturité upstream le permettra, Context Control Center.

Ce document peut seulement exprimer les contraintes **visuelles et d’implémentation** nécessaires au design system.

## 14. Modification de ce contrat

Une modification structurante du design system doit :

- référencer l’ADR Governed AI Experience ;
- expliquer le besoin ;
- préserver ou migrer explicitement les composants concernés ;
- mettre à jour les tokens/contrats réellement utilisés ;
- être prouvée par les validations pertinentes ;
- éviter de faire diverger silencieusement Figma, ce document et l’implémentation.

Si une décision remet en cause `COMPOSE BEFORE CREATE` ou le rôle du design system comme catalogue gouverné, elle exige un nouvel ADR qui supersède explicitement ADR-0001.
