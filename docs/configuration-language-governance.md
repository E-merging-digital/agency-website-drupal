# Configuration Language Governance

Statut : **SOURCE D’ARCHITECTURE ACTIVE — MIGRATION REQUIRED**  
Décision : `docs/decisions/ADR-002-configuration-language-governance.md`  
Policy machine-readable : `docs/configuration-language-policy.yml`  
Issue d’architecture : #608  
Adoption / migration : #609

## 1. Objectif

Agency doit pouvoir créer et transformer l’état Drupal par des opérations
manuelles ou automatisées sans que la langue source de la configuration varie
selon le contexte d’exécution.

La règle s’applique uniformément à :

- l’administration Drupal ;
- l’installation de modules ou thèmes ;
- Drupal Recipes ;
- Config Actions ;
- Canvas ;
- Drupal AI / Canvas AI ;
- agents automatisés qui passent par les APIs Drupal.

La langue éditoriale du site et la langue canonique de configuration sont deux
responsabilités distinctes. Agency conserve actuellement `fr` comme langue
éditoriale par défaut et vise `en` comme langue canonique de configuration.

## 2. État constaté avant migration

Le snapshot versionné de #608 montre une base réellement mixte :

| Surface | État observé |
| --- | --- |
| `system.site` | `langcode: fr`, `default_langcode: fr` |
| `node.type.article` | `langcode: fr` |
| Canvas folder `Agency governed` | `langcode: fr` |
| `core.entity_view_mode.user.token` | `langcode: en` |
| menu `footer` core | `langcode: und` |
| locked languages | fichiers `language.entity.und.yml` et `language.entity.zxx.yml` présents |
| config translations | `config/sync/language/fr` et `config/sync/language/en` présents |

Ce tableau n’est pas une liste exhaustive. Il prouve que la normalisation est
une migration et qu’une simple substitution de tous les `langcode` serait
incorrecte.

La policy reste donc :

```text
status: migration_required
enforce_consistency: false
canonical_configuration_language: en
```

jusqu’à #609.

## 3. Modèle de transformation gouvernée

Une transformation de configuration suit conceptuellement :

```text
état initial
  |
  v
transformation Drupal
  |
  +--> module/theme install
  +--> Recipe
  +--> Config Action
  +--> Canvas
  +--> Drupal AI / agent
  |
  v
état final
  |
  +--> structure
  +--> langcode canonique
  +--> traductions
  +--> permissions
  +--> composants / Canvas
  +--> configuration exportable
  |
  v
preuve before/after
```

Le validateur ne doit pas avoir besoin de savoir si l’opération a été déclenchée
par un humain, une Recipe ou une IA. Il juge l’état final selon la même policy.

## 4. Recipes

Agency considère une Recipe comme une **transformation reproductible de l’état
Drupal** et non comme un simple raccourci d’installation de modules.

Pour toute Recipe introduite ou consommée, la revue doit couvrir :

- préconditions ;
- Recipes parentes et dépendances ;
- modules et thèmes ;
- configuration créée/importée ;
- Config Actions ;
- langcodes de la configuration source ;
- traductions de configuration ;
- permissions ;
- SDC / composants ;
- Canvas ;
- Drupal AI ou agents concernés ;
- état final attendu ;
- snapshot/diff et preuve indépendante.

Le `langcode` écrit dans un YAML externe n’est pas automatiquement autoritatif
pour l’état Agency. #609 doit prouver le comportement d’une Recipe contenant un
langcode différent ou invalide et vérifier que le résultat final ne dérive pas
silencieusement de la policy.

## 5. Canvas / SDC

SDC reste le contrat de composant natif Drupal défini par ADR-001 et `DESIGN.md`.
Cette politique n’ajoute aucune abstraction de composant.

En revanche, les **config entities Canvas** font partie de la configuration
technique et doivent donc respecter la langue canonique lorsqu’elles sont créées
ou modifiées. La langue d’une Canvas Page ou de son contenu reste une question
éditoriale distincte.

Le pilote Canvas AI #530 doit être rebasé/revalidé contre ADR-002 avant admission
finale si sa surface crée ou modifie de la configuration. Il ne doit pas être
réécrit pour cette politique : l’enforcement doit rester transversal dans
Drupal.

## 6. Drupal AI et agents

Drupal AI reste l’abstraction IA par défaut. ADR-002 ajoute une contrainte sur
les **effets Drupal** d’une automatisation, pas sur le provider.

Une IA peut :

- produire du contenu en FR/EN ;
- proposer une composition Canvas ;
- déclencher une primitive Drupal autorisée.

Elle ne peut pas :

- choisir implicitement le `langcode` technique parce que le prompt est en FR ;
- hériter de la langue de l’admin comme politique de configuration ;
- contourner la policy via une API différente ;
- produire une seconde convention de langue propre à l’IA.

Les tests #609 doivent donc vérifier le résultat de la primitive Drupal, pas un
provider précis.

## 7. Configuration Language Lock

Classification actuelle :

```text
USE DRUPAL — CANDIDATE FOR ADOPTION
```

La release stable `drupal/config_language_lock` 1.0.0 est le candidat préféré
parce qu’elle intervient précisément sur les sauvegardes de config entities,
les installs, les Recipes et les Config Actions.

#608 ne l’installe pas. #609 doit :

1. capturer un snapshot complet avant changement ;
2. installer/activer d’abord le module sans enforcement ;
3. comparer son audit au snapshot Agency ;
4. simuler/observer la normalisation EN ;
5. vérifier les traductions existantes et les locked languages ;
6. prouver les cas Recipes/Config Actions/Canvas/IA ;
7. n’activer `locked_langcode: en` avec `follow_site_default: false` qu’après
   preuve verte ;
8. vérifier `cim -> cex` sans drift et `site:install --existing-config`.

Si ces preuves révèlent un blocker, #609 doit échouer fermé plutôt que corriger
le YAML à la main.

## 8. Language Audit

`drupal/language_audit` est une source d’outillage et d’idées utile pour #609 :

- distribution ;
- snapshot ;
- diff ;
- détails config entity/simple config ;
- provenance extension/Recipe lorsque détectable.

Il reste classé **DEV / INVESTIGATION** tant qu’une release stable supportée
n’existe pas. Il ne devient pas une dépendance production par défaut.

Agency ne doit pas développer un clone de Language Audit pour obtenir un rapport
qui existe déjà upstream. Un petit adaptateur ou export machine-readable n’est
acceptable que si Preflight a besoin d’un contrat que l’outil upstream n’expose
pas directement.

## 9. Contrat observable pour Preflight

Agency ne dépend pas de Preflight pour appliquer une Recipe ou rendre Canvas.
Il expose ce qui est nécessaire à une vérification indépendante :

```text
docs/configuration-language-policy.yml
+ snapshot avant
+ description de la transformation
+ snapshot après
+ diff
+ éventuelles violations/exceptions
```

Preflight peut alors vérifier :

- la langue canonique attendue ;
- les langues éditoriales/translation connues ;
- l’état `migration_required` ou `enforced` ;
- les transformations soumises à la policy ;
- les différences avant/après ;
- la conformité de l’état final.

La policy ne contient aucun détail d’implémentation interne de Preflight.

## 10. Migration future vers Drupal core

La policy Agency est durable ; le mécanisme d’enforcement ne l’est pas.

Drupal core travaille sur une langue de configuration distincte de la langue par
défaut du site. Lorsque cette primitive devient stable et couvre les cas Agency :

```text
policy + tests Agency conservés
-> bascule enforcement contrib -> core
-> preuves de non-régression
-> retrait de config_language_lock si devenu inutile
```

Aucune API propriétaire Agency ne doit rendre cette simplification difficile.

## 11. Roadmap

### #608 — Architecture et contrat

Statut cible : **P1 / documentation + policy + tests statiques**.

- ADR-002 ;
- policy machine-readable ;
- règles agents ;
- état initial matérialisé ;
- relation Recipes/Canvas/AI/Preflight ;
- aucun changement Composer/config runtime.

### #609 — Audit et adoption

Statut : **prochain chantier de configuration après #608**, coordonné avec les
PR Composer ouvertes.

- snapshot exhaustif ;
- évaluation `config_language_lock` ;
- migration dry-run ;
- tests nominal/multilingue/Recipe/Config Action/Canvas/IA ;
- décision d’adoption ou rejet fondée sur preuves ;
- passage de `migration_required` à `enforced` uniquement si l’état est réellement
  convergé.

### Canvas AI / Recipes futures

Tout chantier futur qui crée de la configuration doit consommer la policy et ne
pas réinventer l’enforcement. Les idées de provenance/snapshot supplémentaires
restent dans #609 ou dans un ticket dérivé uniquement lorsqu’un gap concret est
identifié.

## 12. Critère READY

#609 devient READY après fusion de #608 **et** après rechargement des PR Composer
ouvertes afin de ne pas créer de conflit de lockfile. Son premier acte est un
audit/snapshot, pas un `composer require` ni une normalisation.
