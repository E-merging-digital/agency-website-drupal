# AI Content Suggestions standalone — verdict projet

Statut : **REJECT POUR LE PÉRIMÈTRE ÉDITORIAL ACTUEL**  
Date : **2026-08-16**  
Ticket : **#383**  
Parent : **#32**

## 1. Décision

Le standalone `drupal/ai_content_suggestions` est désormais **techniquement compatible** avec la pile Agency actuelle, mais il n'apporte pas assez de valeur éditoriale distincte pour justifier son activation maintenant.

Décision :

```text
compatible != nécessaire

AI Content Suggestions 1.5.0
-> REJECT pour le scope éditorial actuel
-> aucune dépendance conservée
-> aucun module activé
-> aucune configuration provider supplémentaire
-> réévaluer seulement sur besoin métier explicite ou lors de la convergence Drupal AI 2.x
```

Il ne s'agit donc plus d'un `WAIT` pour incompatibilité Composer. Le motif est produit, UX, coût et minimisation de surface.

## 2. Preuve de compatibilité exacte

Pile verrouillée pendant la preuve :

- `drupal/ai 1.4.6` ;
- `drupal/field_widget_actions 1.4.1` ;
- Drupal core `11.4.5` ;
- PHP CI `8.4.24`.

La métadonnée Composer publiée pour `drupal/ai_content_suggestions 1.5.0` déclare :

```text
drupal/ai ^1.4
drupal/field_widget_actions ^1.3
drupal/core ^10.4 || ^11
```

La distribution installée porte l'information de packaging Drupal.org du `2026-07-09`.

Deux niveaux de preuve ont été exécutés dans un workspace GitHub Actions jetable, sans secret et sans appel à un provider :

1. dry-run Composer : run `31936157319` ;
2. installation réelle temporaire + inspection source + test fonctionnel : run `31936396904`.

Artefact final :

```text
issue-383-content-suggestions-functional-31936396904
sha256:8f787a2790fef7e9dcc1321dd50fcfd1a0c1a847f03eef18ffa04d2081d63b8e
```

Résultat Composer final :

```text
COMPATIBILITY=COMPATIBLE
AI Content Suggestions = 1.5.0
Drupal AI = 1.4.6 inchangé
Field Widget Actions = 1.4.1 inchangé
FUNCTIONAL_TEST_EXIT=0
```

Le `composer require 'drupal/ai_content_suggestions:^1.4' --with-all-dependencies` n'exige donc aucune migration vers Drupal AI 2.x dans l'état exact testé.

### Note sur la page projet upstream

Au moment de cette décision, la page projet Drupal.org visible publiquement contient encore une note générique indiquant une cible AI `^2.0`, alors que la distribution Composer `1.5.0` effectivement publiée exige AI `^1.4` et s'installe sans modifier notre AI `1.4.6`.

Pour la compatibilité exécutable du projet, la preuve Composer sur la distribution exacte prévaut. Cette divergence documentaire est néanmoins une raison de revalider les métadonnées upstream avant toute adoption future.

## 3. Fonctionnalités réellement fournies par 1.5.0

L'inspection du code exact de la distribution 1.5.0 montre six plugins :

- `Moderate text` ;
- `Evaluate Readability` ;
- `Summarise text` ;
- `Suggest taxonomy tags` ;
- `Suggest title` ;
- `Alter tone`.

Le module ajoute aussi une action Field Widget Actions générique `Content Suggestion with prompt` pour les champs string/text.

La configuration permet de limiter les suggestions par type d'entité et bundle. L'accès utilisateur est protégé par la permission dédiée :

```text
access ai content suggestion tools
```

L'administration du module utilise la permission Drupal AI existante `administer ai`.

## 4. Preuve fonctionnelle sans provider

Un `BrowserTestBase` jetable a installé `ai`, `field_widget_actions` et `ai_content_suggestions`, sans provider configuré et sans appel réseau.

Résultat : **1 test, 32 assertions, PASS**.

Le test prouve que :

- la page `/admin/config/ai/suggestions` reste accessible à l'administrateur ;
- sans provider disponible, elle affiche explicitement qu'aucun plugin de suggestion n'est disponible ;
- un utilisateur sans `access ai content suggestion tools` ne voit pas `Evaluate Readability` ;
- avec cette permission et une configuration de plugin préexistante, l'outil est visible ;
- l'éditeur peut toujours créer et sauvegarder normalement un Article alors qu'aucun provider n'est configuré.

La dégradation de l'édition Drupal de base est donc acceptable : **la sauvegarde humaine ne dépend pas du provider**.

La preuve n'autorise pas à affirmer qu'un clic sur une action IA configurée avec un provider devenu indisponible est toujours rendu sous forme d'erreur UX propre. Dans le code 1.5.0, la résolution `getSetProvider()` intervient avant le `try/catch` de plusieurs appels. Ce cas reste un point à revalider avant une éventuelle adoption.

## 5. Données envoyées et privacy boundary

Les outils au niveau formulaire permettent de sélectionner des champs texte ; leurs valeurs sont concaténées puis envoyées au provider pour l'action choisie.

Le plugin Field Widget Actions générique mérite une vigilance plus forte : si son prompt ne contient pas de token de l'entité cible, son implémentation rend l'entité complète dans son view mode, la convertit en Markdown et l'ajoute au prompt.

Conséquence projet :

```text
si adoption future
-> prompts explicitement bornés par tokens/champs
-> jamais de fallback implicite vers l'entité complète sans revue privacy
-> permissions limitées aux rôles éditoriaux prévus
-> observabilité #389 conservée privacy-first
```

Cette surface est plus large que les Automators Article actuels, volontairement configurés sur des champs précis.

## 6. Comparaison avec le socle éditorial Agency existant

Le projet possède déjà :

- AI CKEditor avec complétion, prompt libre, correction et résumé déclenchés explicitement ;
- AI Automators / Field Widget Actions manuels pour le chapeau `field_short_description` et l'ALT de l'image principale ;
- AI Translate standalone pour la trajectoire traduction ;
- Guardrails déterministes ;
- AI Observability privacy-first.

### Résumé

Chevauche directement :

- AI CKEditor `summarize` ;
- l'Automator manuel du chapeau Article.

**Valeur marginale insuffisante.**

### Ton

Chevauche la capacité de prompt/rewrite de CKEditor. Le projet a volontairement évité d'ajouter une taxonomie artificielle de tons sans besoin éditorial démontré.

**Valeur marginale insuffisante.**

### Suggestion de titre

Peut être utile, mais reste une suggestion LLM simple qui peut être couverte par le socle éditorial existant si le besoin apparaît.

**Pas de justification suffisante pour un module supplémentaire.**

### Taxonomie

Le Blog utilise une catégorisation contrôlée. Une suggestion automatique de tags/catégories n'est pas souhaitable tant qu'un besoin de taxonomie plus riche n'est pas établi.

**Risque de prolifération supérieur au bénéfice actuel.**

### Modération

Le contenu est produit par des éditeurs authentifiés et relu humainement. Aucun cas métier actuel ne justifie un appel de modération provider supplémentaire à chaque demande.

**Pas de besoin démontré.**

### Lisibilité

C'est la capacité la plus distincte. Toutefois la version 1.5.0 demande à un LLM de produire un score de Flesch et des recommandations.

Pour Agency, un score de lisibilité classique est calculable de façon déterministe, sans tokens, sans latence provider et sans transmission de contenu à un LLM. Une fonctionnalité LLM dédiée uniquement à ce besoin n'est donc pas un motif suffisant d'adoption.

**Valeur réelle, implémentation actuellement surdimensionnée pour notre besoin.**

## 7. Coût et comportement opérationnel

Les plugins AI Content Suggestions déclenchent des opérations Drupal AI à la demande. Ils ne publient pas automatiquement du contenu, ce qui est positif, mais chaque usage LLM ajoute :

- tokens ;
- coût provider éventuel ;
- latence ;
- une nouvelle surface de configuration ;
- une nouvelle UX éditoriale à expliquer et maintenir.

Le bénéfice marginal ne compense pas ces coûts dans le scope actuel.

## 8. Signal de maintenance Drupal 12

Le test fonctionnel sous Drupal `11.4.5` déclenche une dépréciation spécifique à `AiContentSuggestionsPluginManager` : la découverte de plugins ne fournit pas encore l'Attribute attendu par Drupal moderne. Drupal indique que ce mode est déprécié depuis 11.2 et supprimé en 12.0.

Ce n'est pas un défaut bloquant sous Drupal 11.4.5, mais c'est un signal supplémentaire pour ne pas agrandir inutilement notre surface contrib avant que le besoin produit existe.

## 9. Conditions de réouverture

Réévaluer le module uniquement si au moins une de ces conditions apparaît :

1. les éditeurs demandent réellement un workflow de review global distinct de CKEditor/Automators ;
2. modération ou suggestion taxonomique devient un besoin métier ;
3. AI Content Suggestions apporte un contrôle de lisibilité déterministe ou une valeur de review nettement supérieure ;
4. la migration planifiée vers Drupal AI 2.x rend cette brique naturelle dans une consolidation plus large ;
5. les points de dégradation provider et de compatibilité Drupal 12 sont résolus.

À ce moment, refaire : compatibilité Composer, privacy review, permissions, coût, provider-failure UX et validation navigateur.

## 10. Conclusion

#383 est clôturable avec le verdict :

```text
REJECT — CURRENT SCOPE
```

Le standalone est sainement séparé de Drupal AI, installable sur notre pile et correctement permissionné. Mais Agency dispose déjà d'un socle d'assistance éditoriale plus ciblé, avec moins de surfaces et des usages mieux définis.

Ne pas installer une capacité uniquement parce qu'elle est disponible respecte la doctrine upstream-first du projet : **réutiliser l'upstream quand il répond à un besoin, sans accumuler des modules IA redondants.**
