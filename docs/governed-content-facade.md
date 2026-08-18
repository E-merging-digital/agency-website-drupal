# Governed Content — façade et dette custom après migration

Statut : **phase de compatibilité #481**  
Date : **2026-08-18**  
Parent : **#442**

## 1. État de départ

La migration des contenus ordinaires est terminée :

```text
catalogue = 3 contenus légaux GOVERNED
LEGACY_RELEASE_PENDING_IDS = []
contenus ordinaires RELEASED = 33
```

Les sources machine autoritatives restent :

```text
web/modules/custom/emerging_digital_content/content_sync/catalog.yml
Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy
```

Le moteur historique porte encore le nom `ContentSync`, mais son rôle runtime
courant est désormais la matérialisation déterministe du petit catalogue
Governed Content.

## 2. Décision de façade

La convergence commence de façon additive.

Les commandes historiques restent canoniques pendant cette phase parce que la
production appelle encore directement `emerging:content-sync --all`.

Deux aliases Governed Content sont introduits sans wrapper ni duplication :

```text
emerging:governed-content
-> alias de emerging:content-sync

emerging:governed-content:validate
-> alias de emerging:content-sync:validate
```

Les deux noms appellent donc exactement les mêmes méthodes, services, options,
dry-run, validations et rapports.

Aucun alias Governed Content n'est ajouté à `release` ou `readmit` dans cette
phase. Ces commandes appartiennent au lifecycle historique de migration et de
rollback ; leur retrait éventuel exige une décision distincte sur la fenêtre de
compatibilité et les procédures de rollback encore supportées.

## 3. Admission policy durable

Après #441, le catalogue ne combine plus deux classes d'admission.

L'invariant durable devient :

```text
catalogue IDs == GovernedContentPolicy::GOVERNED_CONTENT_IDS
LEGACY_RELEASE_PENDING_IDS == []
```

Toute nouvelle entrée de catalogue doit donc être explicitement admise comme
Governed Content dans la policy. Il n'existe plus de file grandfathered pouvant
servir à introduire du contenu marketing ou éditorial ordinaire.

## 4. Revalidation Drupal/contrib

Revalidation effectuée le 18 août 2026 à partir des sources officielles Drupal
et Drush.

### Drupal Core Default Content

Drupal 11 expose `Drupal\Core\DefaultContent`, avec import/export YAML et des
primitives utiles. L'API est toutefois encore marquée **expérimentale**. Elle
n'est donc pas utilisée ici comme remplacement immédiat d'une surface production
qui possède déjà mapping, dry-run, audit et rollback éprouvés.

### `drupal/default_content`

La branche 2.x propose notamment YAML, traductions, fichiers et références, mais
le projet indique encore cette branche comme expérimentale et ne présente pas de
release stable supportée au moment de la revalidation.

Décision : **pas de nouvelle dépendance**.

### `drupal/default_content_deploy`

Une release stable 2.1.4 couverte par la Security Team existe pour Drupal 11.
Le projet possède cependant sa propre sémantique d'import/mise à jour, et la
branche 2.2 documente un problème critique HAL affectant les références.
Aucune parité n'est démontrée aujourd'hui avec les garanties Agency : mapping
local, release/readmission fail-closed, prune guard, exact dry-run, identité et
promotion déjà prouvés.

Décision : **évaluer plus tard seulement sur preuve de parité**, ne pas installer
pour #481.

### Workspaces

Core Workspaces sert à préparer et publier des changements de contenu dans un
site Drupal. Cette responsabilité est différente de la matérialisation
repository -> environnements utilisée pour les trois contenus Governed Content.

Décision : **complément possible, pas remplacement du moteur**.

### Drush

Agency utilise Drush 13.7.x. Les attributs `#[CLI\Command]` supportent les
aliases, ce qui permet une façade additive sans changer les méthodes exécutées.
La famille des commandes annotées est elle-même dépréciée au profit de Symfony
Console dans les versions récentes de Drush 13/14.

Décision : **ne pas mélanger la migration Symfony Console avec #481**. Ce sera un
chantier de compatibilité Drush séparé si nécessaire.

## 5. Matrice KEEP / CONVERGE / RETIRE-LATER

| Surface | Décision | Motif |
|---|---|---|
| catalogue YAML + loader/validator | KEEP | source versionnée des 3 contenus gouvernés |
| mapping repository | KEEP | identité locale, audit et protections lifecycle |
| `ContentSyncManager` | KEEP | matérialisation, dry-run, traductions, aliases et prune guards |
| `emerging:content-sync` | CONVERGE | encore utilisé en production ; garder compatible |
| `emerging:content-sync:validate` | CONVERGE | même moteur, nouveau vocabulaire disponible en alias |
| `emerging:governed-content` | ADDITIVE | façade de vocabulaire, zéro logique dupliquée |
| `emerging:governed-content:validate` | ADDITIVE | façade de vocabulaire, zéro logique dupliquée |
| `ContentSyncReleaseManager` | RETIRE-LATER | plus de pending ; encore utile pour historique/rollback |
| `content-sync:release` | RETIRE-LATER | aucun nouveau lot ordinaire attendu |
| `content-sync:readmit` | RETIRE-LATER | conserver jusqu'à décision de fenêtre de rollback |
| scripts de preuve de transition #440/#441 | RETIRE-LATER | preuves historiques utiles ; ne plus étendre sans nouveau besoin |
| `scripts/deploy-production.sh` | KEEP UNCHANGED #481 | production prouvée sur ancien nom |
| services DI `content_sync_*` | KEEP #481 | renommage interne n'apporte pas de garantie métier |
| Default Content / DCD / Workspaces | EVALUATE LATER | aucune parité démontrée justifiant une migration maintenant |

## 6. Ordre de convergence restant sous #442

Après #481 :

1. prouver sur une surface Drupal réelle que les deux nouveaux aliases sont
   découverts et produisent la même validation/dry-run que les noms historiques ;
2. décider si le prochain déploiement peut utiliser le nouvel alias tout en
   conservant l'ancien ;
3. seulement après plusieurs déploiements verts, décider du nom canonique ;
4. fixer une fenêtre explicite avant tout retrait de `release/readmit` ou des
   scripts de preuve de transition ;
5. réévaluer les composants contrib lorsque leurs APIs/release lines apportent
   une parité réelle et stable.

Invariant :

```text
converger le vocabulaire
!= dupliquer le moteur
!= casser le déploiement
!= supprimer les garanties éprouvées
```
