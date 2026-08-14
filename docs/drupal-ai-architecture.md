# Doctrine d'architecture Drupal AI

Statut : **ACCEPTEE**  
Date de décision : **2026-08-14**  
Tickets : #32, #376, #355, #356

## 1. Objet

Ce document est la source de vérité du projet pour les choix d'architecture liés à l'intelligence artificielle dans Drupal.

Il s'applique à toute nouvelle fonctionnalité IA, à la traduction assistée par IA, aux fonctionnalités IA du futur blog et à toute évolution d'un module custom qui communique avec un fournisseur de modèles.

La doctrine suit la direction de la **Drupal AI Initiative** et sa roadmap 2026 : l'IA doit renforcer le contenu structuré, les permissions, les révisions, les workflows et la gouvernance Drupal, pas les contourner.

Références autoritatives :

- Drupal AI Initiative : https://www.drupal.org/about/ai/initiatives
- Inside AI / Outside AI : https://www.drupal.org/about/ai/initiatives/blog/drupal-ai-initiative-introducing-inside-ai-and-outside-ai
- Drupal AI Roadmap 2026 : https://www.drupal.org/blog/drupals-ai-roadmap-for-2026
- Projet Drupal AI : https://www.drupal.org/project/ai
- Documentation Drupal AI : https://project.pages.drupalcode.org/ai/

Ces références sont temporelles. Avant une évolution importante, vérifier l'état stable courant sur Drupal.org et ne pas supposer qu'une branche de développement est devenue stable.

## 2. Etat du projet au moment de la décision

Le projet possède déjà :

- `drupal/ai` dans la branche stable 1.4.x ;
- `drupal/ai_provider_openai` ;
- le module `key` ;
- une configuration provider qui référence une Key Drupal plutôt qu'un secret versionné ;
- le module custom `agency_ai_translation` ;
- `llms_txt`, Schema.org, Metatag et Simple Sitemap pour la découvrabilité structurée.

Le module `agency_ai_translation` contient encore un client HTTP custom qui construit directement un payload compatible OpenAI et résout lui-même plusieurs sources de clé. Cette implémentation est **héritée et transitoire** : elle ne constitue pas le pattern à reproduire pour de nouvelles fonctionnalités.

## 3. Décisions d'architecture obligatoires

### 3.1 Drupal AI est l'abstraction IA par défaut

Toute nouvelle capacité IA doit utiliser les abstractions, providers et plugins de Drupal AI lorsqu'une capacité stable adéquate existe.

Interdit par défaut :

- nouvel appel HTTP direct à OpenAI, Anthropic ou autre fournisseur ;
- nouvel usage direct d'un SDK fournisseur dans le code custom ;
- logique de sélection de modèle ou de résolution de clé réimplémentée dans un module custom.

Une exception n'est acceptable que si un ticket dédié démontre qu'aucune abstraction Drupal AI stable ne couvre le besoin, documente le plan de retrait et ajoute les tests nécessaires.

### 3.2 Provider-agnostic par conception

Le code métier ne doit pas dépendre d'OpenAI comme concept fonctionnel. Le provider peut être OpenAI aujourd'hui, mais la logique éditoriale doit rester portable vers un autre provider supporté par Drupal AI.

Les identifiants de provider/modèle appartiennent à la configuration, pas au contenu éditorial.

### 3.3 Secrets et clés

- Aucun secret, token ou clé API n'est versionné.
- Utiliser Drupal Key et/ou les mécanismes locaux sécurisés déjà prévus par le projet.
- Une configuration exportée peut référencer un identifiant de Key, jamais la valeur secrète.
- Tout nouveau fallback de clé custom est interdit sans justification explicite.

### 3.4 Stable avant expérimental

Au 2026-08-14, le projet Drupal AI recommande la branche stable 1.4.x ; les branches 1.5.x et 2.0.x sont encore des branches de développement.

Règle :

- préférer une release stable couverte par la politique de sécurité Drupal ;
- ne pas introduire de `-dev`, alpha, beta ou RC pour une fonctionnalité de production sans ticket explicite, preuve de nécessité, analyse de sécurité et stratégie de retrait/mise à niveau.

### 3.5 L'IA assiste ; Drupal gouverne

L'IA peut proposer, résumer, reformuler, traduire, classer ou enrichir.

Elle ne doit pas contourner :

- permissions ;
- traductions Drupal ;
- révisions ;
- validation éditoriale ;
- statuts de publication ;
- workflows de modération lorsqu'ils existent.

**Aucune publication automatique d'un contenu généré ou traduit par IA n'est autorisée par défaut.** Une validation humaine précède toute publication.

### 3.6 Le contenu reste valable sans IA

Le modèle de contenu Drupal doit être conçu d'abord pour les besoins éditoriaux, SEO, accessibilité et multilingues.

Une panne, un changement de provider ou la désactivation de l'IA ne doit pas rendre le contenu inutilisable ni casser le rendu public.

## 4. Composants Drupal AI à privilégier

Avant tout développement custom, évaluer les composants officiels/stables adaptés au besoin.

### AI CKEditor

Usage visé : assistance ponctuelle dans l'éditeur pour reformulation, correction, ton, complétion ou traduction de fragments.

Principe : action explicite de l'éditeur, résultat relu avant sauvegarde/publication.

### AI Content Suggestions

Usage visé : feedback éditorial, qualité de contenu, suggestions d'amélioration, SEO/AEO lorsqu'elles sont disponibles et stables.

Ne pas confondre suggestion avec validation automatique.

### AI Automators

Usage visé : enrichissement de champs structurés lorsque l'automator stable convient, par exemple résumé, métadonnées, alt text, catégorisation ou autres champs dérivés.

Les automators doivent cibler des champs explicites et rester audités/testables.

### AI Translate

Usage visé : traduction de contenu au travers des primitives Drupal AI et Content Translation.

Avant de remplacer `agency_ai_translation`, vérifier la parité fonctionnelle réelle, notamment :

- Paragraphs et champs éditoriaux imbriqués ;
- Pathauto/aliases de traduction ;
- sélection source/cible ;
- comportement create/update ;
- redirection vers relecture ;
- publication manuelle ;
- tests fonctionnels existants.

Aucune suppression de `agency_ai_translation` avant preuve de parité et plan de migration.

### Guardrails

Pour les usages qui produisent ou traitent du contenu utilisateur, évaluer les guardrails Drupal AI stables : limites d'entrée, restrictions de sujet, contrôle de sortie et règles spécifiques au projet.

Les guardrails complètent les permissions et la validation humaine ; ils ne les remplacent pas.

## 5. Inside AI et Outside AI

### Inside AI

Inside AI guide les fonctionnalités qui assistent les éditeurs **dans Drupal** : rédaction, traduction, suggestions, enrichissement structuré et, plus tard, automatisations gouvernées.

Pour le site E-merging Digital, c'est le flux prioritaire à court terme.

### Outside AI

Outside AI guide la capacité de Drupal à être compris et utilisé par des agents externes.

A court terme, le projet prépare cette direction par :

- contenu structuré ;
- Schema.org ;
- métadonnées ;
- sitemap ;
- `llms.txt` ;
- permissions et APIs Drupal explicites lorsqu'un besoin futur sera validé.

**Cette doctrine n'autorise pas aujourd'hui une surface d'écriture autonome pour un agent externe.** Toute écriture agentique future devra avoir un ticket de gouvernance, une identité, des permissions minimales, des traces d'audit et des limites d'action.

## 6. Application au blog #355

Le blog est le premier chantier éditorial qui doit appliquer cette doctrine dès sa conception.

Le bundle `Article` doit rester un bon modèle Drupal indépendamment de l'IA. La fondation #356 doit donc d'abord fournir des champs structurés et traduisibles cohérents, notamment :

- titre ;
- `field_short_description` comme résumé éditorial ;
- corps structuré ;
- image principale avec texte alternatif ;
- catégorie ;
- métadonnées SEO/sociales ;
- traductions FR/EN ;
- révisions et publication Drupal.

Ensuite, des tickets IA séparés pourront brancher les capacités officielles pertinentes sur ces champs.

Candidats fonctionnels pour le blog :

- AI CKEditor sur le corps/résumé ;
- suggestions éditoriales et SEO/AEO ;
- automators pour résumé, alt text, tags/catégories ou métadonnées lorsque pertinent ;
- AI Translate pour la traduction FR/EN après validation de parité ;
- guardrails et contrôle humain avant publication.

Le blog ne doit pas générer/publier automatiquement des articles complets en production dans sa première version.

## 7. Trajectoire de `agency_ai_translation`

Statut : **legacy fonctionnel à converger, pas dette à supprimer aveuglément**.

Trajectoire :

1. ne plus ajouter de nouveaux appels provider directs ;
2. inventorier les comportements couverts par les tests existants ;
3. évaluer AI Translate et l'abstraction Drupal AI stable ;
4. si la parité est suffisante, migrer par ticket borné ;
5. conserver ou adapter uniquement les extensions projet réellement nécessaires ;
6. retirer les fallbacks/HTTP custom après preuve de migration et tests verts.

## 8. Gouvernance, coûts et observabilité

Toute nouvelle fonctionnalité IA doit préciser dans son ticket :

- qui peut la déclencher ;
- quel contenu est envoyé au provider ;
- si des données personnelles ou sensibles peuvent être envoyées ;
- quelle validation humaine est requise ;
- quel provider/modèle est configuré ;
- comportement en cas d'indisponibilité du provider ;
- impact coût/rate limit lorsque pertinent ;
- traces/logs nécessaires sans journaliser de secrets ou données sensibles.

Pour les traitements de masse ou automatisés, ajouter un plafond explicite, un mode dry-run/preview si pertinent et une stratégie d'arrêt/reprise avant activation en production.

## 9. Règle de revue pour toute future PR IA

Une PR IA ne peut être considérée terminée que si :

- elle respecte ce document ou documente explicitement une exception ;
- aucune clé n'est versionnée ;
- les permissions sont minimales ;
- la configuration exportable est propre ;
- la désactivation/panne IA ne casse pas le rendu public ;
- la publication reste humaine sauf décision distincte ;
- PHPCS, PHPStan, drupal-check et tests concernés sont verts ;
- les changements de provider ou de version ont été vérifiés contre les sources Drupal officielles courantes.

## 10. Mise à jour de cette doctrine

Cette décision n'est pas figée contre l'évolution de Drupal AI.

Elle doit être réévaluée lorsque :

- une nouvelle branche stable majeure/minor de Drupal AI devient recommandée ;
- AI Translate ou un autre composant atteint une maturité qui permet de supprimer du custom ;
- Outside AI fournit une primitive officielle stable pertinente pour ce site ;
- les exigences de gouvernance, sécurité ou conformité du site changent.

Toute modification substantielle de cette doctrine passe par un ticket et une PR afin de conserver l'historique de décision.