# Issue #85 — Préparation de la traduction automatique IA (Drupal 11)

## 1) Objectif et périmètre

Ce document prépare **uniquement** la future mise en place d’un flux de traduction assistée par IA, sans déployer de solution de traduction automatique en production à ce stade.

Périmètre de cette itération :
- audit de la structure de contenu existante ;
- vérification de la traduisibilité des types de contenu, Paragraphs et champs ;
- cadrage d’une stratégie simple et maintenable compatible Drupal 11 ;
- priorisation des contenus à traduire ;
- définition d’un workflow éditorial cible (FR source → MT IA → relecture → publication).

Hors périmètre :
- intégration d’un connecteur de traduction ;
- automatisation complète en production ;
- changements d’architecture lourds.

---

## 2) Audit de la structure de contenu existante

### 2.1 Types de contenus (`node`)

Types de contenu détectés :
- `page`
- `service`
- `ai_feature`
- `case_client`
- `article`

### 2.2 Types de Paragraphs

Paragraphs détectés :
- `hero`
- `cta`
- `text_block`
- `services`
- `ai_features`
- `case_clients`
- `trust_list`

### 2.3 Constat sur les champs

Sur l’existant, les champs métier des bundles `node` et `paragraph` sont déjà majoritairement (et dans la pratique ici entièrement) marqués `translatable: true` dans la configuration exportée.

Conséquence :
- la modélisation actuelle est globalement favorable à la traduction de contenu ;
- le point bloquant principal n’est pas la structure des champs mais l’orchestration d’un workflow de traduction robuste.

---

## 3) Compatibilité Drupal standard (traduction)

Compatibilité globale : **favorable**.

Éléments validés :
- architecture basée sur entités Drupal (`node`, `paragraph`) ;
- contenu modulaire via Paragraphs compatible avec la traduction entité/champ ;
- champs texte, CTA et blocs éditoriaux déjà préparés côté structure (champs traduisibles).

Points d’attention pour la suite (sans implémentation dans ce ticket) :
- activer et configurer proprement les modules cœur de traduction selon l’environnement cible (langue de contenu source, langues cibles, règles éditoriales) ;
- confirmer la stratégie de traduction des champs de référence (traduire la cible vs partager la référence) selon les besoins métier ;
- cadrer la traduction SEO (metadonnées) bundle par bundle.

---

## 4) Priorisation de traduction (MVP éditorial)

Priorité P1 (immédiate) :
1. pages principales (`page`, sections `hero`, `cta`, `text_block`) ;
2. CTA (titres, textes, libellés de liens, variantes secondaires) ;
3. contenus éditoriaux à forte visibilité (`service`, `ai_feature`, `case_client`).

Priorité P2 (itération suivante) :
1. `article` (backlog éditorial selon trafic / valeur business) ;
2. métadonnées SEO (title/description OG/Twitter, alias, etc.) selon politique SEO internationale.

---

## 5) Stratégie cible simple pour la future traduction IA

### 5.1 Principes

- **Source unique = français**.
- La traduction IA est une **pré-traduction** (jamais publication directe par défaut).
- Validation humaine obligatoire avant publication.
- Traçabilité : état, date, réviseur, version source.

### 5.2 Workflow recommandé

1. **Rédaction FR** (statut brouillon/relecture interne).
2. **Génération automatique** de la traduction cible (lot ou à la demande).
3. **Relecture humaine** (linguistique + terminologie + conformité marque).
4. **Publication** de la version traduite après validation.
5. **Maintenance** : re-traduction contrôlée si la source FR évolue.

### 5.3 Règles opérationnelles minimales

- ne traduire automatiquement que les champs textuels pertinents ;
- exclure les IDs techniques, références internes et champs non éditoriaux ;
- appliquer un glossaire métier (noms produits, secteurs, termes imposés) ;
- ajouter une checklist de QA linguistique avant publication.

---

## 6) Plan d’implémentation progressive (prochaine étape)

Étape A — socle Drupal (faible risque) :
- confirmer langues source/cibles ;
- activer la traduction de contenu avec réglages standards ;
- verrouiller la politique de révision/modération pour les traductions.

Étape B — pré-intégration IA (sans publication auto) :
- définir un service d’abstraction de traduction (provider-agnostic) ;
- brancher un provider IA uniquement en préproduction ;
- journaliser requêtes/réponses et erreurs.

Étape C — industrialisation :
- priorisation automatique par type de contenu ;
- détection de contenus source modifiés ;
- relance de traduction et workflow de revalidation.

---

## 7) Critères de non-régression

- aucune rupture des contenus existants ;
- aucune dépendance additionnelle imposée dans cette phase de préparation ;
- architecture Drupal 11 respectée ;
- stratégie documentée et exécutable par étapes.

