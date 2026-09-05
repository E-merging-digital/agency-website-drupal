# DESIGN.md — contrat du design system Agency

Statut : **CONTRAT FRONTEND AUTORITATIF**  
Décision : `docs/decisions/ADR-001-governed-ai-experience.md`  
Architecture : `docs/governed-ai-experience.md`  
Issue d’implémentation progressive : #519

## 1. Rôle

`DESIGN.md` est le contrat durable entre :

- design ;
- développement frontend ;
- agents de développement ;
- composants Drupal ;
- future composition Canvas / Canvas AI.

Il définit **ce qui est autorisé visuellement et techniquement** pour construire
l’interface Agency.

Invariant : **COMPOSE BEFORE CREATE**.

Une page ou une section doit d’abord réutiliser les primitives approuvées et
leurs variantes. Une nouvelle primitive n’est créée qu’après démonstration d’un
gap, validation et admission.

## 2. Ce document ne possède pas le contexte éditorial

`DESIGN.md` ne doit pas devenir un prompt global ni une seconde source de vérité
pour :

- positionnement commercial ;
- tone of voice ;
- audiences/personas ;
- terminologie FR/EN ;
- règles SEO éditoriales ;
- contraintes réglementaires métier ;
- règles de campagne ;
- contexte propre au chatbot ou à un use case IA.

Ces responsabilités suivent :

- `docs/drupal-ai-context-architecture.md` ;
- `docs/drupal-ai-architecture.md` ;
- `docs/seo/strategie-seo.md` ;
- les sources Drupal/versionnées spécialisées.

Le design system et le contexte IA sont complémentaires, pas fusionnés.

## 3. Hiérarchie des sources visuelles

### Figma

Figma est la source principale d’**intention visuelle** lorsqu’une maquette ou un
système de variables existe : composition, proportions, variantes, états,
interactions et références de composants.

Figma n’est pas la seule source de vérité technique. Un export Figma ne peut pas
contourner les contrats Drupal, SDC, accessibilité ou CSS de ce document.

### DESIGN.md

Ce document porte les règles durables et les critères de décision.

### Tokens runtime

Les valeurs effectivement rendues doivent être matérialisées dans le code du
design system. Aujourd’hui, la baseline canonique est dans :

```text
web/themes/custom/emerging_digital/css/base.css
```

À terme, #519 peut réorganiser leur consommation par SDC, mais ne doit pas créer
une deuxième palette concurrente.

### SDC

Les Single Directory Components approuvés deviennent progressivement les
primitives exécutables du design system. Le code du composant reste la source de
vérité de son rendu et de son API props/slots.

## 4. Baseline de tokens existante

Les valeurs ci-dessous sont **l’état réel du thème après l’alignement Brand
#1015**. Elles restent une évolution bornée du système existant et ne constituent
pas une refonte de la direction artistique.

### Couleurs

| Token | Valeur actuelle | Rôle |
| --- | --- | --- |
| `--color-bg` | `#ffffff` | fond principal |
| `--color-surface` | `#f6f7fb` | surfaces secondaires |
| `--color-text` | `#111827` | texte principal |
| `--color-muted` | `#4b5563` | texte secondaire |
| `--color-border` | `#d1d5db` | bordures |
| `--color-primary` | `#286FB6` | action/lien principal et bleu de marque |
| `--color-primary-contrast` | `#ffffff` | contenu sur primaire |
| `--color-accent` | `#77B72E` | accent, progression et décoration de marque |

Le bleu primaire peut porter du texte blanc : la combinaison reste compatible
avec l’objectif WCAG AA pour le texte normal. Le vert d’accent n’est pas un fond
pour du texte blanc normal ; s’il porte du texte, utiliser une couleur sombre
avec contraste suffisant. Les couleurs dédiées au focus, aux erreurs, aux
avertissements, aux confirmations et aux autres statuts restent indépendantes de
la palette Brand sauf décision d’accessibilité explicitement justifiée.

Règle : toute nouvelle couleur sémantique récurrente doit devenir un token
justifié. Ne pas multiplier des hex ad hoc dans de nouveaux composants.

Une correction isolée peut conserver une valeur locale si elle ne représente
pas un concept réutilisable ; la PR doit alors expliquer pourquoi un token serait
inapproprié.

### Typographie

| Token | Valeur actuelle |
| --- | --- |
| `--font-body` | `"Inter", "Segoe UI", Roboto, Arial, sans-serif` |
| `--font-heading` | `"Poppins", "Segoe UI", Roboto, Arial, sans-serif` |

Le body utilise une hauteur de ligne `1.6`; les titres utilisent `1.2`.

Règles :

- réutiliser les familles existantes par défaut ;
- ne pas introduire une troisième famille sans décision de design explicite ;
- conserver une mesure et une hiérarchie lisibles ;
- les composants ne doivent pas embarquer leur propre mini-système typographique
  sans justification.

### Espacements

| Token | Valeur actuelle |
| --- | --- |
| `--space-1` | `0.25rem` |
| `--space-2` | `0.5rem` |
| `--space-3` | `0.75rem` |
| `--space-4` | `1rem` |
| `--space-5` | `1.5rem` |
| `--space-6` | `2rem` |
| `--space-7` | `3rem` |

Règle : les nouveaux composants utilisent cette échelle avant de créer une
valeur d’espacement nouvelle.

Une nouvelle marche d’échelle doit répondre à un besoin récurrent et être revue
comme évolution du design system.

### Conteneur, rayons et ombre

| Token | Valeur actuelle |
| --- | --- |
| `--container-width` | `72rem` |
| `--radius-sm` | `0.25rem` |
| `--radius-md` | `0.5rem` |
| `--radius-lg` | `0.85rem` |
| `--shadow-sm` | `0 1px 2px rgb(0 0 0 / 8%)` |

Les conteneurs `.page-container` et `.ed-container` utilisent cette largeur avec
des gouttières issues de l’échelle d’espacement.

## 5. Responsive

Principes :

- mobile et petits écrans font partie du contrat du composant, pas d’une passe de
  correction finale ;
- éviter le débordement horizontal ;
- images, SVG, vidéo et iframe doivent rester contenus ;
- les props/slots d’un composant doivent produire un comportement prévisible à
  toutes les largeurs supportées ;
- ne pas créer une variante uniquement pour masquer un défaut responsive d’une
  primitive existante.

Le thème possède actuellement une adaptation de gouttière à `30rem` dans
`base.css`. Les autres breakpoints existants restent dans les CSS du thème et
doivent être inventoriés avant toute normalisation dans #519.

Ne pas créer un nouveau système de breakpoints dans un composant isolé sans
justification.

## 6. Accessibilité

Cible de conception : **WCAG 2.2 AA**, sauf exigence de ticket plus stricte.

Chaque primitive admise doit au minimum considérer :

- HTML sémantique ;
- hiérarchie de titres dans son contexte d’utilisation ;
- nom accessible des contrôles ;
- navigation clavier ;
- focus visible ;
- contrastes ;
- taille/zone des cibles interactives ;
- erreurs et aides de formulaire ;
- texte alternatif ou traitement décoratif des images ;
- mouvement/animation non indispensable ;
- responsive sans perte d’information ou d’action.

Une apparence conforme à Figma ne justifie jamais de dégrader ces critères.

## 7. CSS

Règles pour tout nouveau travail :

1. utiliser les tokens existants avant une valeur brute ;
2. préférer un style local au composant SDC lorsqu’il appartient réellement à la
   primitive ;
3. ne pas créer de dépendance à l’ordre accidentel des champs ou du DOM ;
4. éviter les sélecteurs globaux qui modifient des composants non concernés ;
5. conserver des noms de classes explicites et stables ;
6. ne pas générer du CSS à la volée depuis un prompt de page ;
7. ne pas ajouter de framework frontend lourd pour résoudre un besoin couvert par
   le thème/Drupal ;
8. toute nouvelle primitive visuelle récurrente doit avoir un owner et un contrat
   de composant.

Les CSS historiques ne doivent pas être refactorisés en masse simplement pour
respecter ce document. La convergence se fait au fil de tickets bornés.

## 8. Contrat SDC cible

Le design system exécutable converge vers Drupal core SDC.

Structure attendue pour une primitive nouvelle ou migrée :

```text
web/themes/custom/emerging_digital/components/<component>/
  <component>.component.yml
  <component>.twig
  <component>.css       # si nécessaire
  <component>.js        # si nécessaire
```

Le `*.component.yml` décrit l’API publique du composant.

### Props

Utiliser des props pour les valeurs structurées : texte court, booléens,
énumérations, URL, choix de variante, données simples.

Principes :

- noms explicites ;
- types stricts ;
- valeurs obligatoires seulement lorsqu’elles le sont réellement ;
- enum pour une liste fermée de variantes ;
- pas de prop « HTML/CSS arbitraire » servant de porte de contournement ;
- éviter `extra_classes` générique lorsqu’il permettrait à une IA d’inventer une
  nouvelle apparence hors design system.

### Slots

Utiliser des slots pour les zones de contenu/composition imbriquée prévues par le
composant.

Un slot n’est pas une permission d’insérer n’importe quoi : la future politique
d’admission Canvas doit pouvoir restreindre les composants pertinents par
contexte lorsque nécessaire.

### Variantes

Préférer une variante d’une primitive lorsque :

- la structure et le rôle fonctionnel restent les mêmes ;
- seule une dimension explicitement prévue varie ;
- la variante peut être nommée, testée et documentée.

Créer un composant distinct lorsque la sémantique, la structure ou les
interactions deviennent réellement différentes.

## 9. Critères d’admission d’un composant

Un composant n’entre pas dans le catalogue autorisé uniquement parce qu’il rend
correctement une capture.

Critères minimaux :

- besoin réel et rôle fonctionnel clair ;
- impossibilité raisonnable de couvrir le besoin avec une primitive/variante
  approuvée existante ;
- API props/slots bornée ;
- tokens réutilisés ;
- responsive validé ;
- accessibilité de base validée ;
- rendu Drupal sans erreur ;
- browser proof pertinente ;
- aucune dépendance ou permission disproportionnée ;
- revue humaine ;
- statut `approved` matérialisé selon le mécanisme défini dans #519.

Pour une nouvelle primitive, le ticket doit répondre explicitement :

```text
Pourquoi les composants existants + leurs variantes ne suffisent-ils pas ?
```

Sans réponse démontrable, la primitive n’est pas admise.

## 10. COMPOSE BEFORE CREATE pour les agents

Lorsqu’un agent reçoit une demande frontend/page :

```text
1. lire ADR-001 + DESIGN.md
2. inventorier les composants approuvés utiles
3. composer avec eux
4. essayer leurs variantes
5. signaler le gap restant
6. ne pas créer de primitive sans ticket/gate dédié
```

Interdictions :

- générer une page entière en HTML/CSS libre pour « aller plus vite » ;
- injecter du style inline afin de contourner les tokens ;
- créer une nouvelle variante implicite via classes arbitraires ;
- modifier un composant partagé pour un seul écran sans vérifier les usages ;
- utiliser Canvas AI comme autorité du design system.

Canvas AI sélectionne et compose **le système approuvé** ; il ne définit pas ce
système.

## 11. Figma -> DESIGN.md -> SDC

Pour une décision provenant de Figma :

```text
maquette / variable / composant de référence
-> identifier la décision durable
-> mapper vers token / variante / primitive
-> implémenter dans le système Drupal
-> prouver le rendu
```

Les pixels extraits d’une maquette ne deviennent pas automatiquement des tokens.
Une valeur devient un token lorsqu’elle porte une intention réutilisable.

Une future automatisation Figma/MCP doit produire ou proposer des changements
reviewables dans cette chaîne, pas écrire directement une page production.

## 12. Validation des changements frontend

Selon le ticket :

### Obligatoire au niveau code

- `git diff --check` ;
- lint/qualité projet ;
- tests Drupal concernés.

### Changement visible ou interactif significatif

Utiliser la preuve définie par `docs/browser-validation.md` :

- vrai Chromium ;
- desktop/mobile ;
- DOM ;
- console ;
- réseau ;
- interactions ;
- screenshots/traces lorsque pertinents.

### Composants SDC admis

#519 devra ajouter une validation reproductible du contrat de composant, avec au
minimum :

- schéma/props ;
- rendu ;
- responsive ;
- accessibilité ciblée ;
- preuve visuelle pertinente ;
- non-régression des usages existants.

Une régression visuelle automatisée est ajoutée uniquement lorsque la stabilité
de la surface et le coût de maintenance la rendent utile ; elle ne remplace pas
les contrôles sémantiques/fonctionnels.

## 13. Architecture actuelle et migration

Le thème existant est basé sur Paragraphs, Twig et CSS global/thématique. Ce
modèle n’est pas déclaré « incorrect » par cette décision.

Trajectoire :

```text
inventaire
-> identifier les primitives réellement réutilisables
-> vertical slice SDC
-> preuve
-> admission
-> migration progressive des usages lorsque rentable
```

Pas de migration big-bang.

Les Paragraphs peuvent continuer à porter du contenu structuré et appeler des
SDC pour le rendu. Canvas n’impose pas de supprimer l’architecture éditoriale
Drupal existante.

## 14. Définition d’une page conforme au design system

Une page candidate est conforme lorsque :

- chaque primitive visible appartient au catalogue autorisé ou à une surface
  explicitement hors catalogue ;
- ses variantes et props sont valides ;
- les tokens sont respectés ;
- aucun markup/CSS libre n’a été généré pour contourner le catalogue ;
- responsive et accessibilité passent les contrôles applicables ;
- les interactions et formulaires restent fonctionnels ;
- le contexte éditorial provient de ses sources de vérité ;
- la page reste une révision/candidate reviewable avant publication.

## 15. Évolution de ce contrat

Une modification de token, convention SDC ou critère d’admission passe par une
PR et doit considérer ses effets sur les composants existants.

Un changement qui remet en cause le principe de composition gouvernée ou
`COMPOSE BEFORE CREATE` nécessite une nouvelle ADR qui supersède explicitement
`ADR-001`.
