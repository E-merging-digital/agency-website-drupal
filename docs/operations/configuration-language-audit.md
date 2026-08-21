# Configuration Language Audit — trusted read-only route

Statut : **PROUVÉE / AVAILABLE**  
Owner : #609  
Promotion : #614  
Architecture : `docs/decisions/ADR-002-configuration-language-governance.md`  
Policy : `docs/configuration-language-policy.yml`  
Baseline durable : `docs/evidence/configuration-language-baseline-609.yml`

## 1. Objectif

Cette route capture le snapshot exigé par #609 avant toute adoption de
`drupal/config_language_lock`.

Elle répond à une seule question : **quel est l’état linguistique réel d’une
installation Drupal fraîche reconstruite depuis la configuration versionnée ?**

Elle ne normalise rien et ne prend aucune décision d’adoption à elle seule.

## 2. Preuve d’admission

La route a été exercée avec succès sur le `main` live le 21 août 2026 :

```text
run                  = 32528341256
trusted main         = c6d77fd109aa40cc6cf5849249d04e3d87bae65e
verdict              = PASS / SNAPSHOT_CAPTURED
snapshot SHA-256     = df4d389eafaad6135fcd7d995354ff433111be62f745208ac0a65ddf8783629d
artifact             = agency-config-language-609-32528341256-1
artifact id          = 9463027789
artifact digest      = sha256:d9c008b56175a733b866b3beddd2076c5ac2bb12577fbd64d60ba32835ec854b
```

Les trois jobs — validation hosted, reconstruction/audit self-hosted et report
hosted — ont terminé `SUCCESS`, y compris le cleanup DDEV/workspace.

La reconstruction a prouvé :

```text
site:install --existing-config = PASS
cim                            = PASS
config:status                  = No differences
repository config count        = 595
active config count            = 595
missing names                  = 0
langcode mismatches            = 0
```

Le premier essai `32527869024` avait échoué fermé avant DDEV parce que PHP
n’existe pas sur l’hôte du runner. #612/#613 a déplacé le lint PHP dans DDEV.
Cet échec historique ne constitue pas un échec Drupal ni une donnée de baseline.

## 3. Baseline pré-migration

Distribution exacte observée :

| Surface | FR | EN | sans `langcode` | `und` |
| --- | ---: | ---: | ---: | ---: |
| Toutes configs | 352 | 183 | 59 | 1 |
| Config entities | 345 | 183 | 0 | 1 |
| Simple config | 7 | 0 | 59 | 0 |

Traductions de configuration exportées :

```text
config/sync/language/en = 172 overrides
config/sync/language/fr = 2 overrides
```

Analyse initiale :

```text
FR base avec override EN     = 171
FR base sans override EN     = 181
EN base avec override FR     = 2
mixed technical base         = true
canonical EN already uniform = false
```

Parmi les 181 bases FR sans override EN figurent notamment :

- 53 `base_field_override` ;
- 30 config entities Canvas `component` ;
- 13 dossiers Canvas ;
- 14 `language_content_settings` ;
- 11 form displays et 10 view displays ;
- champs/storages, media types, filtres, image styles, Views et autres surfaces.

Les sept simple configs FR sont :

- `agency_ai_translation.settings` ;
- `llms_txt.settings` ;
- `system.maintenance` ;
- `system.site` ;
- `user.mail` ;
- `user.settings` ;
- `webform.settings`.

Cette distribution confirme `status: migration_required`. Elle interdit une
réécriture globale `fr -> en`. Base canonique, configuration traduisible,
overrides de traduction et valeurs sémantiques/locked doivent être distingués.

Le détail compact et les identifiants de preuve sont versionnés dans
`docs/evidence/configuration-language-baseline-609.yml`. Le snapshot complet
reste l’artifact de run pour l’investigation détaillée.

## 4. Surface de contrôle

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

## 5. Séparation de privilèges

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
Drupal. PHP n’est pas requis sur l’hôte : les contrôles PHP s’exécutent dans le
runtime DDEV du projet.

## 6. Reconstruction canonique

```text
checkout exact main
-> DDEV isolé
-> lint PHP dans DDEV
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

## 7. Snapshot machine-readable

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

Le snapshot conserve les entrées individuelles afin qu’un futur diff
avant/après puisse identifier les objets transformés.

## 8. Critères PASS

Un audit est `PASS / SNAPSHOT_CAPTURED` uniquement si :

- la policy est `agency-configuration-language-v1` ;
- son état est encore `migration_required` ;
- sa cible canonique est `en` ;
- `enforce_consistency` est encore `false` ;
- la reconstruction `--existing-config` réussit ;
- `config:status` est propre ;
- aucun nom de configuration ne manque entre dépôt et active config ;
- aucun `langcode` ne diffère entre dépôt et active config ;
- tous les éléments de la migration watchlist existent.

Un PASS ne signifie **pas** que la configuration est normalisée EN. Il signifie
que l’état pré-migration est reproductible et mesuré.

## 9. Interdictions

Cette route ne doit jamais :

- exécuter `composer require` ou modifier `composer.json` / `composer.lock` ;
- activer `config_language_lock` ;
- exécuter `drush cex` ;
- écrire de configuration via `config:set` ou Entity API ;
- appliquer Governed Content / Content Sync ;
- utiliser le canal SSH production ;
- accepter du shell ou des chemins fournis par l’utilisateur ;
- faire passer `migration_required` à `enforced`.

## 10. Suite #609

Après cette preuve :

1. recharger les PR Composer #563/#566 ;
2. ne pas introduire `config_language_lock` tant qu’une mutation Composer
   concurrente n’est pas convergée ;
3. lorsque la voie Composer est libre, évaluer le module d’abord sans enforcement ;
4. capturer un nouveau snapshot dans le même environnement ;
5. comparer au baseline durable ;
6. seulement ensuite préparer une migration EN ;
7. n’activer l’enforcement qu’après preuve d’absence de perte ou dérive des
   traductions, Recipes, Config Actions, Canvas et workflows IA.
