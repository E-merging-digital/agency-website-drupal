# Governed Content — libération contrôlée d'un mapping

Statut : **primitive disponible, aucun contenu réel libéré dans #439**  
Date : **2026-08-16**  
Ticket : **#439**  
Trajectoire : `docs/governed-content-trajectory.md`

## 1. Invariant

```text
release from Git governance
!= delete Drupal entity
!= unpublish Drupal entity
!= rewrite Drupal entity
```

La release #439 modifie uniquement la ligne de mapping persistante Content Sync.

Elle conserve :

- `content_id` ;
- `entity_type` ;
- `entity_id` ;
- `entity_uuid` ;
- `langcode` ;
- `catalog_hash` ;
- `last_synced` ;
- timestamps de création.

Elle change uniquement le lifecycle/audit :

```text
status: active -> released
last_action: released
changed: <current request time>
```

## 2. Policy autoritative

La classification de transition est centralisée dans :

```text
Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy
```

Deux ensembles sont explicites :

```text
GOVERNED_CONTENT_IDS
LEGACY_RELEASE_PENDING_IDS
```

Une release n'est autorisée que pour un ID encore présent dans `LEGACY_RELEASE_PENDING_IDS`.

Les trois contenus légaux `GOVERNED_CONTENT_IDS` ne peuvent donc pas être libérés par cette commande.

`GovernedContentCatalogPolicyTest` compare le catalogue réel à cette policy. Toute admission ou sortie de catalogue reste ainsi une modification de code revue, pas une décision implicite du runtime.

## 3. Dry-run par défaut

La commande est volontairement plus prudente qu'un write Drush ordinaire.

Sans option :

```bash
ddev drush emerging:content-sync:release services/drupal
```

elle reste en dry-run.

Forme explicite recommandée :

```bash
ddev drush emerging:content-sync:release services/drupal --dry-run
```

Le dry-run vérifie :

- l'admission dans `LEGACY_RELEASE_PENDING_IDS` ;
- l'existence du mapping ;
- le statut courant ;
- la présence de `entity_id` et `entity_uuid` ;
- l'identité de l'entité qui restera intacte.

Il ne modifie ni le mapping ni Drupal.

## 4. Apply explicite

La mutation exige obligatoirement :

```bash
ddev drush emerging:content-sync:release services/drupal --apply
```

`--dry-run` et `--apply` sont mutuellement exclusifs.

Après apply, vérifier au minimum :

```text
mapping_status = released
mapped_entity = même entity_type:entity_id
mapped_uuid = même UUID
catalog_hash = inchangé
last_synced = inchangé
last_action = released
```

La commande est idempotente : relancer une release déjà appliquée n'écrit pas une nouvelle identité et ne touche pas l'entité.

## 5. Comportement d'un mapping released

### Prune

`findActiveMissingFromCatalog()` sélectionne explicitement :

```text
status = active
```

Un mapping `released` est donc exclu des candidats de :

```bash
emerging:content-sync --all --prune=unpublish
```

Cette propriété est couverte par un Kernel test.

### Content Sync normal tant que l'ID est encore catalogué

Un intervalle de migration peut exister entre :

1. passage du mapping à `released` ;
2. retrait gouverné de l'ID/payload du catalogue.

Pendant cet intervalle, le comportement est fail-closed :

```text
content-sync dry-run
-> mapping_status = released
-> planned_operation = blocked
-> error explicite

content-sync targeted apply
-> STOP avant applyValidatedEntry
-> aucune écriture entity/mapping

content-sync full apply
-> preflight détecte released
-> STOP avant les écritures du catalogue
```

Le repository possède en plus un second garde : `createOrUpdate()` refuse de remplacer implicitement un mapping `released` par un mapping `active`.

Il faut donc toujours **readmettre explicitement** avant qu'un Content Sync normal puisse reprendre possession du contenu.

## 6. Réadmission explicite

Commande :

```bash
ddev drush emerging:content-sync:readmit services/drupal --dry-run
```

puis :

```bash
ddev drush emerging:content-sync:readmit services/drupal --apply
```

Sans option, la commande est également un dry-run.

Conditions :

- le mapping existe ;
- il est `released` ;
- l'ID est à nouveau présent dans le catalogue courant.

La dernière condition impose qu'un rollback réintroduise d'abord explicitement le payload/catalogue dans le checkout gouverné utilisé pour l'opération. La readmission ne peut donc pas transformer arbitrairement un mapping released en contenu géré absent du repository.

Transition :

```text
status: released -> active
last_action: readmitted
```

`entity_id`, `entity_uuid`, `catalog_hash` et `last_synced` restent préservés. Le Content Sync normal peut ensuite être relancé explicitement.

## 7. Séquence du pilote #440

#439 ne retire aucun contenu. Pour le futur lot des trois case studies, la séquence admissible est :

1. revalider que le code #439 est déployé dans l'environnement de preuve ;
2. valider le catalogue ;
3. exécuter `content-sync --all --dry-run` avant toute release ;
4. snapshotter mapping, node ID, UUID, FR/EN, aliases, révision et publication ;
5. `content-sync:release <id> --dry-run` ;
6. `content-sync:release <id> --apply` ;
7. vérifier le mapping `released` et l'entité inchangée ;
8. ne pas relancer un Content Sync normal tant que l'ancien catalogue contient encore cet ID ; le guard fail-closed le refuserait de toute façon ;
9. appliquer le changement gouverné qui retire exactement les IDs/payloads du lot et réduit `LEGACY_RELEASE_PENDING_IDS` ;
10. relancer les commandes de déploiement normales ; released étant absent du catalogue et ignoré par prune, son entité reste editor-owned ;
11. modifier le contenu dans Drupal ;
12. relancer le déploiement/Content Sync et prouver l'absence d'overwrite ;
13. valider traductions, aliases, SEO et rendu navigateur ;
14. seulement ensuite promouvoir la même transition.

## 8. Rollback du pilote

Avant toute promotion production, le rollback doit être prouvé dans l'environnement de test.

Ordre :

1. restaurer l'ID et son payload dans le catalogue/policy du checkout de rollback ;
2. lancer `content-sync:readmit <id> --dry-run` ;
3. lancer `content-sync:readmit <id> --apply` ;
4. vérifier la même identité node/UUID ;
5. relancer le Content Sync ciblé ou global selon la procédure de rollback ;
6. vérifier FR/EN, aliases, publication et rendu.

Ne pas utiliser `--prune=unpublish` comme rollback de release.

## 9. Audit et diagnostics

Les transitions repository journalisent :

- `content_id` ;
- statut précédent ;
- statut suivant ;
- conservation explicite de l'identité Drupal.

Le rapport Drush affiche :

- mode dry-run/apply ;
- mapping status ;
- entity type + ID ;
- UUID ;
- actions ;
- erreurs fail-closed.

Aucun secret ni contenu de champ n'est nécessaire à cette opération.

## 10. Tests #439

Les tests couvrent :

- lifecycle `active -> released -> active` ;
- conservation ID/UUID/hash/last-sync ;
- exclusion de `released` des prune candidates ;
- refus de réactivation implicite par repository ;
- release dry-run sans écriture ;
- release apply sans delete/unpublish/rewrite du node ;
- idempotence ;
- refus des contenus légaux/non grandfathered ;
- blocage du Content Sync dry-run et apply tant qu'un mapping released reste catalogué ;
- réadmission dry-run puis apply explicite.

## 11. Hors périmètre

#439 ne :

- retire aucun des 33 IDs du catalogue ;
- supprime aucun payload ;
- change aucun contenu métier ;
- change aucun alias ;
- touche aucun menu ;
- modifie aucun script de déploiement ;
- désactive aucun garde production ;
- exécute aucune release sur production.

La première libération réelle appartient à #440.
