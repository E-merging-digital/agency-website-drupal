# Configuration Language Audit — trusted read-only route

Statut : **CANDIDATE — à prouver sur main avant promotion dans le registre global**  
Owner : #609  
Architecture : `docs/decisions/ADR-002-configuration-language-governance.md`  
Policy : `docs/configuration-language-policy.yml`

## 1. Objectif

Cette route capture le snapshot initial exigé par #609 avant toute adoption de
`drupal/config_language_lock`.

Elle répond à une seule question : **quel est l’état linguistique réel d’une
installation Drupal fraîche reconstruite depuis la configuration versionnée ?**

Elle ne normalise rien et ne prend aucune décision d’adoption à elle seule.

## 2. Surface de contrôle

La seule commande admise est un commentaire propriétaire exact sur l’issue
ouverte #609 :

```text
/agency-config-language inspect
```

Le gateway GitHub-hosted refuse la demande si :

- l’acteur ou l’auteur du commentaire n’est pas `E-merging-digital` ;
- le ticket n’est pas exactement #609 ;
- l’issue n’est plus ouverte ou a été créée par un autre compte ;
- le commentaire n’est pas exactement la commande ci-dessus ;
- la révision de workflow exécutée n’est pas le `main` live.

Aucun argument, chemin, package, commande shell ou cible runtime n’est fourni par
le commentaire.

## 3. Séparation de privilèges

```text
issue comment #609
-> gateway GitHub-hosted
   - valide acteur / issue / commande / live main
-> runner self-hosted Agency
   - contents: read
   - checkout exact main
   - DDEV isolé
   - reconstruction Drupal fraîche
   - audit read-only
-> artifact
-> job GitHub-hosted
   - issues: write
   - publie seulement le résumé borné
```

Le runner self-hosted ne reçoit aucun token repository write et utilise
`persist-credentials: false`.

La route ne reçoit aucun secret de production, provider IA ou utilisateur
Drupal.

## 4. Reconstruction canonique

Le job self-hosted réutilise les invariants déjà éprouvés par Browser Validation :

```text
checkout exact main
-> DDEV isolé
-> composer install depuis composer.lock
-> drush site:install --existing-config
-> drush cim -y
-> drush cr
-> config:status
-> snapshot
-> artifact
-> suppression DDEV
-> workspace propre
```

`config:status` doit indiquer `No differences` avant l’audit. Si la configuration
active et `config/sync` ne sont pas convergées, le verdict est
`CONFIGURATION_NOT_CANONICAL` et aucun snapshot n’est admis comme baseline.

## 5. Snapshot machine-readable

L’artifact a la forme :

```text
agency-config-language-609-<run_id>-<attempt>
```

Il contient au minimum :

```text
result.json
snapshot.json
config-status.txt
```

`snapshot.json` contient :

- policy Agency effectivement observée ;
- version Drupal et langue par défaut active ;
- inventaire de tous les YAML de base de `config/sync` ;
- classification simple config / config entity lorsque Drupal la connaît ;
- distribution des `langcode` ;
- inventaire équivalent de la configuration active ;
- comparaison nom par nom entre repository et active config ;
- répertoires et noms des traductions sous `config/sync/language/*` ;
- watchlist de migration ADR-002 ;
- observation du caractère mixte ou uniforme des langues techniques.

Le snapshot conserve les entrées individuelles, pas uniquement des compteurs,
afin qu’un futur diff avant/après puisse identifier précisément les objets
transformés.

## 6. Critères PASS

Le premier audit est `PASS / SNAPSHOT_CAPTURED` uniquement si :

- la policy est `agency-configuration-language-v1` ;
- son état est encore `migration_required` ;
- sa cible canonique est `en` ;
- `enforce_consistency` est encore `false` ;
- la reconstruction `--existing-config` réussit ;
- `config:status` est propre ;
- aucun nom de configuration ne manque entre dépôt et active config ;
- aucun `langcode` ne diffère entre dépôt et active config ;
- tous les éléments de la migration watchlist existent.

Un PASS ne signifie **pas** que la configuration est déjà normalisée EN. Il
signifie seulement que le baseline pré-migration a été capturé de manière
reproductible.

## 7. Interdictions

Cette route ne doit jamais :

- exécuter `composer require` ou modifier `composer.json` / `composer.lock` ;
- activer `config_language_lock` ;
- exécuter `drush cex` ;
- écrire de configuration via `config:set` ou Entity API ;
- appliquer Governed Content / Content Sync ;
- utiliser le canal SSH production ;
- accepter du shell ou des chemins fournis par l’utilisateur ;
- faire passer `migration_required` à `enforced`.

## 8. Suite après première preuve

Après un run PASS réellement observé sur `main` :

1. conserver le SHA-256 du snapshot comme baseline #609 ;
2. documenter la distribution réelle et les catégories de drift ;
3. promouvoir la route dans `docs/operations/execution-capabilities.md` ;
4. recharger les PR Composer #563/#566 ;
5. seulement ensuite préparer l’évaluation de `config_language_lock` sans
enforcement ;
6. comparer le snapshot après activation du module au baseline initial ;
7. ne préparer la migration EN qu’après preuve d’absence de perte de traduction.
