# Configuration Language Governance

Statut : **ACTIVE / ENFORCED**  
Décision historique : docs/decisions/ADR-002-configuration-language-governance.md  
Policy machine-readable : docs/configuration-language-policy.yml  
Architecture initiale : #608  
Adoption historique : #609  
Migration matérialisée actuelle : #1316

## 1. Objectif

Agency doit produire une configuration Drupal déterministe et reproductible quel
que soit le chemin d’écriture : administration native, installation de module ou
de thème, Recipe, Config Action, Canvas, Drupal AI ou agent automatisé.

La langue technique d’un objet de configuration et la langue sémantique de ses
valeurs traduisibles sont deux concepts distincts. Un langcode technique FR ne
signifie donc pas que chaque valeur canonique est sémantiquement française.

## 2. Contrat actuellement appliqué

| Élément | Valeur |
| --- | --- |
| Statut | ACTIVE / ENFORCED |
| Policy | schema v2 / enforced |
| Langue par défaut du site | fr |
| Langues du site | fr, en |
| Intention historique de base | en |
| Stratégie des futures écritures | site_default |
| Langcode technique résolu | fr |
| Configuration Language Lock | drupal/config_language_lock 1.0.x |
| locked_langcode résolu | fr |
| follow_site_default | true |
| Traductions gérées | fr, en |
| Preflight | vérificateur indépendant |

L’implémentation est classée **USE DRUPAL / ADOPTED**. Les nouvelles écritures
ou sauvegardes de configuration suivent la langue par défaut du site, actuellement
FR.

## 3. Chronologie et statut des preuves

- #608 et ADR-002 ont établi l’architecture déterministe de langue de
  configuration.
- #609 a historiquement adopté et prouvé un verrou orienté EN
  (locked_langcode=en, follow_site_default=false). Cette preuve reste une
  évidence historique immuable.
- #1302 a exposé l’incompatibilité de cet ancien réglage avec l’invariant Canvas.
- #1314 a prouvé Candidate A et recommandé l’option C : préserver l’intention
  historique EN tout en faisant suivre les futures écritures à la langue par
  défaut du site.
- #1316 a matérialisé cette recommandation par les mécanismes Drupal : le dépôt
  est normalisé avec un verrou FR/true, les valeurs effectives FR et EN sont
  préservées, l’intention historique EN reste documentée, Canvas est compatible
  et les identités sémantiques und/zxx restent préservées.

ADR-002 n’est pas réécrit : il reste la décision historique qui explique
l’origine de la gouvernance. La policy v2 décrit l’état actuellement appliqué.

## 4. Écritures futures et configuration existante

La règle de future écriture est site_default. Avec le site actuel, elle se résout
à FR.

Modifier les réglages de Configuration Language Lock ne réécrit pas
rétroactivement la configuration existante. Un objet existant n’est normalisé
que par un mécanisme Drupal gouverné qui le sauvegarde ou le migre explicitement.

En conséquence, un futur changement de langue par défaut du site qui modifierait
le langcode technique résolu exige une revue gouvernée et, lorsque nécessaire,
une migration de dépôt prouvée. Il ne faut jamais supposer que l’existant se
réécrit automatiquement.

Aucune normalisation large ou manuelle de config/sync n’est autorisée pour
appliquer cette policy.

## 5. Traductions et sémantique FR/EN

Agency conserve les collections de traduction de configuration :

- config/sync/language/fr ;
- config/sync/language/en.

La normalisation technique vers FR ne doit pas effacer la sémantique effective
EN. Inversement, la présence d’une valeur anglaise dans la configuration
canonique ne rend pas son langcode technique EN.

Les langues spéciales und et zxx conservent leur identité et leur statut locked.

## 6. Recipes, Config Actions, Canvas et Drupal AI

Une Recipe ou une Config Action est une transformation reproductible d’état
Drupal. Sa revue doit couvrir les préconditions, la configuration créée ou
modifiée, les traductions, les permissions et l’état final exportable.

Canvas utilise la même policy technique. Le verrou suit la langue par défaut du
site et satisfait l’invariant Canvas actuel. La langue d’une Canvas Page ou du
contenu éditorial reste une responsabilité distincte.

Drupal AI et les agents utilisent eux aussi la même policy. Un prompt, un
provider ou la langue d’interface ne choisit jamais implicitement le langcode
technique. Les primitives Drupal gouvernées restent autoritatives.

## 7. Administration Drupal et contenu éditorial

Les sauvegardes natives de configuration dans l’administration suivent le verrou
site_default. Cette règle ne change pas la langue éditoriale des contenus : le
site reste FR par défaut et les contenus FR existants ou nouvellement créés
restent FR sauf choix éditorial explicite.

## 8. Preflight

Agency expose un contrat machine-readable de schema v2 et les preuves nécessaires
à un contrôle indépendant :

- docs/configuration-language-policy.yml ;
- snapshots before/after lorsque requis ;
- description de la transformation ;
- diff ;
- verdict indépendant.

Preflight reste découplé de l’implémentation Agency. Il vérifie la policy
courante et les preuves observables ; Agency ne dépend pas de son implémentation
interne.

## 9. Preuves et historique immuable

Les exigences de preuve restent : snapshot avant, snapshot après, diff et verdict
indépendant. Les preuves #609 et #1314 sont historiques et ne doivent pas être
réécrites pour refléter l’état #1316.

La policy actuelle doit donc distinguer explicitement :

1. l’intention historique de base EN ;
2. l’enforcement des futures écritures par site_default, actuellement FR.

## 10. Transition future vers Drupal core

La policy Agency est durable ; son mécanisme d’enforcement peut évoluer. Lorsque
Drupal core fournit une primitive stable couvrant les mêmes garanties, Agency
conserve la policy et les tests, prouve la non-régression puis retire
config_language_lock s’il est devenu inutile.

Aucune abstraction propriétaire Agency ne doit être ajoutée pour reproduire un
mécanisme Drupal suffisant.
