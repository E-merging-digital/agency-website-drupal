# ADR-002 — Configuration Language Governance

- **Statut : ACCEPTED**
- **Date : 2026-08-21**
- **Issue : #608**
- **Follow-up d’adoption : #609**
- **Complète : ADR-001 — Governed AI Experience**
- **Supersède : aucune décision**

## 1. Contexte

Agency évolue vers une plateforme Drupal où de plus en plus d’état peut être
créé ou transformé par des primitives automatisées : modules, Drupal Recipes,
Config Actions, Canvas, Drupal AI et agents. Cette automatisation reste
compatible avec la gouvernance Agency seulement si l’état produit est
déterministe, reproductible et vérifiable.

La langue de configuration est aujourd’hui un point de dérive réel dans Drupal.
Une configuration peut hériter de la langue d’une requête, de l’utilisateur
administrateur, de la langue de l’interface, de la langue fournie par une
extension ou une Recipe, ou du contexte d’exécution d’un outil automatisé.

L’état Agency observé sur `main` au 21 août 2026 confirme que le problème n’est
pas théorique :

- `system.site:default_langcode` vaut `fr` ;
- la configuration de base exportée contient des `langcode` `fr`, `en` et
  `und` ;
- `language.entity.zxx` porte naturellement aussi la valeur `zxx` ;
- des traductions de configuration sont exportées sous
  `config/sync/language/fr` et `config/sync/language/en` ;
- des objets Canvas déjà versionnés, par exemple le dossier `Agency governed`,
  ont été créés avec `langcode: fr` ;
- d’autres configurations provenant de core/contrib sont en `en` ou `und`.

Cette distribution historique ne doit pas être interprétée comme une politique.
Elle constitue un état de migration à comprendre avant normalisation.

## 2. Décision fondamentale

Agency adopte l’invariant suivant :

> Toute configuration créée ou modifiée par Agency, Drupal core/contrib, une
> Recipe, Config Actions, Canvas, Drupal AI ou un agent automatisé doit avoir un
> comportement linguistique déterministe, reproductible et vérifiable.

Le `langcode` d’une configuration technique ne doit jamais dépendre
accidentellement :

- de la langue de la requête HTTP ;
- de la langue de l’utilisateur administrateur ;
- de la langue active de l’interface ;
- de la provenance ou de la langue source d’une Recipe ;
- du contexte d’exécution d’un workflow IA ou d’un agent ;
- de l’ordre dans lequel des modules, thèmes ou transformations ont été
  appliqués.

Cet invariant complète `COMPOSE BEFORE CREATE` : une composition gouvernée doit
produire non seulement des composants autorisés, mais aussi un état Drupal dont
la langue technique est gouvernée.

## 3. Langue canonique cible

Agency sépare désormais explicitement :

```text
langue éditoriale/site par défaut
!=
langue canonique de configuration technique
```

Pour le repository Agency actuel :

```text
site/default éditorial = fr
configuration technique canonique cible = en
langues du site = fr, en
traduction cible de la configuration canonique = fr lorsque nécessaire
```

Le choix de `en` est motivé par :

- Drupal core et la majorité des extensions fournissent leur configuration de
  base en anglais ;
- Agency vise des transformations réutilisables indépendantes d’un site
  principalement FR, NL, DE ou EN ;
- une base technique EN rend la configuration source indépendante de la langue
  éditoriale courante ;
- les mécanismes Drupal de traduction de configuration restent responsables des
  libellés traduits.

Ce choix **ne modifie pas** `system.site:default_langcode`, qui reste `fr` tant
qu’un ticket éditorial distinct n’en décide pas autrement.

## 4. État de transition : `migration_required`

`en` est une cible d’architecture ACCEPTED, mais **l’enforcement n’est pas activé
dans #608**.

Le dépôt actuel contient déjà une base mixte et des overrides de traduction des
deux côtés. Une réécriture globale immédiate pourrait déplacer la langue source
d’objets traduisibles et donc modifier ce que Drupal considère comme original
ou traduction.

La policy machine-readable porte donc :

```text
status = migration_required
enforce_consistency = false
```

jusqu’à la preuve de migration #609.

#609 doit établir un snapshot avant/après, vérifier les traductions existantes,
les objets Canvas, les locked languages `und`/`zxx`, l’installation
`--existing-config` et la stabilité `cim -> cex` avant de faire passer la policy
en mode enforced.

## 5. Configuration Language Lock

`drupal/config_language_lock` `1.0.0`, publié le 18 août 2026, est retenu comme
**candidat `USE DRUPAL` privilégié** pour #609.

La release stable est couverte par la Drupal Security Team et compatible avec
Drupal `^11.2 || ^12`. Le module répond directement aux gaps identifiés :

- config entities sauvegardées via UI/API ;
- installation de modules et thèmes ;
- configuration importée par Recipes ;
- Config Actions ;
- visualisation de la distribution des langues ;
- normalisation vers une langue explicitement verrouillée.

Décision Agency :

```text
candidat = drupal/config_language_lock ^1.0
locked_langcode cible = en
follow_site_default = false
activation = uniquement après #609 vert
```

`follow_site_default` doit rester `false` : l’objectif est précisément de ne pas
coupler la langue technique canonique au défaut éditorial du site.

L’installation du module sans lock est acceptable comme étape de preuve sous
#609 parce que le module documente ce mode comme opt-in et non intrusif. Son
activation avec `en` ne doit cependant intervenir qu’après revue du diff de
normalisation.

## 6. Language Audit

`drupal/language_audit` est classé :

```text
DEVELOPMENT / INVESTIGATION TOOL
```

Au 21 août 2026, le projet ne dispose pas d’une release stable supportée. Il est
utile conceptuellement et potentiellement en DDEV pour :

- distribution des langcodes ;
- snapshots ;
- diff avant/après ;
- détail des config entities et simple config ;
- recherche de provenance module/thème/Recipe.

Il n’est pas ajouté comme dépendance production par #608. Agency ne doit pas
réimplémenter son interface ou son moteur de provenance. #609 peut l’utiliser
temporairement en développement si cela réduit le travail et si sa maturité est
revalidée au moment de l’usage.

## 7. Recipes = transformations gouvernées

Une Recipe Agency n’est pas seulement une liste de modules à installer. Elle est
une **transformation reproductible de l’état Drupal**.

Toute adoption ou création de Recipe doit pouvoir décrire et vérifier au
minimum :

```text
préconditions
-> dépendances / recipes parentes
-> modules / thèmes
-> configuration importée
-> Config Actions
-> langcodes source
-> traductions
-> permissions
-> SDC / Canvas concernés
-> interactions Drupal AI éventuelles
-> état final attendu
-> preuve indépendante
```

Une Recipe contenant du YAML avec un autre `langcode`, ou un langcode invalide,
ne peut pas introduire silencieusement un drift. Après la migration #609, l’état
actif résultant doit respecter la langue canonique Agency, quelle que soit la
langue source du fichier de Recipe.

Une Recipe externe est donc évaluée sur **l’état final produit**, pas uniquement
sur la validité syntaxique de son `recipe.yml`.

## 8. Canvas, SDC et Drupal AI

### SDC

Les fichiers de composants SDC ne deviennent pas des traductions de
configuration par eux-mêmes. Lorsqu’un SDC est matérialisé ou référencé par une
config entity Canvas, cette config entity relève de cette ADR.

### Canvas

Canvas est déjà la primitive de composition visuelle Agency. Toute config entity
Canvas créée ou modifiée manuellement ou par Canvas AI doit converger vers le
`langcode` canonique, indépendamment de la langue de l’éditeur ou de la page
candidate.

La langue du contenu d’une Canvas Page reste une propriété éditoriale de
l’entité de contenu et ne doit pas être confondue avec la langue de ses objets de
configuration technique.

### Drupal AI / agents

Un workflow IA ne reçoit aucune exception à la politique parce qu’il est
automatisé. S’il produit indirectement une configuration via une API Drupal, la
même politique s’applique que pour une action manuelle, une Recipe ou une Config
Action.

L’IA peut choisir du contenu dans une langue demandée ; elle ne choisit pas
implicitement la langue source de la configuration technique.

## 9. Contrat Agency -> Preflight

Agency expose la politique sans importer de logique Preflight.

La source machine-readable est :

```text
docs/configuration-language-policy.yml
```

Elle exprime au minimum :

- langue canonique cible ;
- langue éditoriale par défaut actuelle ;
- langues actives du site ;
- langues de traduction cible de la configuration ;
- état `migration_required` ou `enforced` ;
- mécanisme d’enforcement prévu ;
- transformations soumises à la politique ;
- exigences de snapshot/diff ;
- watchlist de migration ;
- transition future vers Drupal core.

Preflight peut lire cette policy et comparer un snapshot avant/après sans
connaître l’implémentation de `config_language_lock`, Canvas ou Drupal AI.

Le contrat cible est :

```text
état initial
-> transformation Drupal
-> snapshot/diff
-> policy check
-> verdict indépendant
```

## 10. Drupal core comme destination

Configuration Language Lock est une solution de transition, pas une dépendance
métier permanente.

Drupal core travaille sur des guardrails de langue de configuration, notamment :

- #3608533 — surface centralisée « Configuration language » ;
- #3337864 — langue par défaut de configuration distincte de la langue par
  défaut du site.

Agency doit éviter toute API propriétaire qui empêcherait une migration vers la
primitive core. Lorsque core fournit une couverture suffisante et stable :

```text
policy Agency inchangée
-> remplacer l’enforcement contrib par core
-> conserver les tests et preuves
-> retirer progressivement le workaround contrib
```

La politique est donc plus durable que son mécanisme d’implémentation.

## 11. Tests obligatoires pour l’adoption #609

Au minimum :

1. création nominale d’une config entity -> `en` ;
2. frontend FR + admin FR -> config technique toujours `en` ;
3. Config Action -> état final `en` ;
4. Recipe avec `langcode` différent/incorrect -> pas de drift silencieux ;
5. installation module/thème -> état final `en` ;
6. création/modification d’un objet Canvas -> `en` ;
7. workflow Drupal AI/agent matérialisant une config via Drupal -> même
   invariant ;
8. traductions de configuration FR préservées ;
9. `site:install --existing-config` reproductible ;
10. locked languages `und`/`zxx` de #412 non cassées ;
11. `cim` puis `cex` sans drift ;
12. preuve before/after exploitable indépendamment par Preflight.

#608 n’ajoute qu’un test du **contrat de gouvernance** et du snapshot déclaré ;
il n’émule pas `Language Audit` et ne prétend pas prouver l’enforcement runtime.

## 12. Conséquences

### Positives

- comportement reproductible des transformations ;
- séparation nette contenu/configuration ;
- Recipes et Canvas AI deviennent auditables selon la même règle ;
- moins de dérive liée à la langue de l’admin ;
- contrat consommable par Preflight ;
- trajectoire claire vers Drupal core.

### Coûts assumés

- migration initiale de la configuration historique ;
- revue des traductions avant normalisation ;
- tests supplémentaires sur Recipes/Canvas/AI ;
- maintien temporaire d’un mécanisme contrib si #609 l’adopte.

## 13. Règle de supersession

Cette ADR complète ADR-001 et est autoritative pour la langue de configuration.

Elle ne peut être remplacée que par une nouvelle ADR explicite. Une évolution de
Drupal core peut changer le **mécanisme** d’enforcement sans remettre en cause
l’invariant ni la séparation entre langue éditoriale et langue technique.