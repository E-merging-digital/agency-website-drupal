# Governed Content — façade et dette custom après migration

Statut : **façade Governed Content canonique ; compatibilité Content Sync conservée**  
Date : **2026-08-18**  
Parent : **#442**

## 1. État courant

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

Le moteur PHP historique conserve ses noms internes `ContentSync`, mais son rôle
runtime courant est la matérialisation déterministe du petit catalogue Governed
Content. Aucun renommage interne n'est requis pour obtenir cette garantie.

## 2. Façade CLI canonique

La façade publique/CLI canonique est :

```text
emerging:governed-content
emerging:governed-content:validate
```

Les anciens noms sont conservés comme aliases de compatibilité :

```text
emerging:content-sync
emerging:content-sync:validate
```

Les anciens noms sont considérés comme **compatibilité dépréciée**, sans date de
suppression sous #442. Aucun retrait n'est autorisé avant un ticket explicite,
une nouvelle preuve de compatibilité et une décision liée notamment à la future
migration des commandes Drush annotées vers Symfony Console.

Aucun alias Governed Content n'est ajouté à `release` ou `readmit`. Ces commandes
appartiennent au lifecycle historique de migration/rollback et restent classées
`RETIRE-LATER`.

## 3. Admission policy durable

Après #441, le catalogue ne combine plus deux classes d'admission.

L'invariant durable est :

```text
catalogue IDs == GovernedContentPolicy::GOVERNED_CONTENT_IDS
LEGACY_RELEASE_PENDING_IDS == []
```

Toute nouvelle entrée de catalogue doit donc être explicitement admise comme
Governed Content dans la policy. Il n'existe plus de file grandfathered pouvant
servir à réintroduire du contenu marketing ou éditorial ordinaire.

## 4. Preuve runtime de parité — #483

La Browser Validation trusted a reconstruit un Drupal complet dans DDEV sur le
`main` exact `d016a60c5b0b0a689dd45e5c27eeb9f9ca36764f`.

```text
gateway: 32119292743 / SUCCESS
browser validation: 32119304420 / SUCCESS
artifact id: 9318031275
artifact sha256: 95d04f6ed24ef56a5838a969a903c2071c4c49d8f87ac4f0bd0211b4b307d0eb
```

Le proof repository-owned a vérifié :

```text
old/new sync help status = 0/0
old/new validate help status = 0/0
old/new validate status = 0/0
validate outputs_equal = true
old/new --all --dry-run status = 0/0
dry-run outputs_equal = true
governed_state_unchanged = true
```

Les diffs de sortie `validate`/`dry-run` et les quatre diffs d'état gouverné
étaient vides.

## 5. Preuve d'apply réel — #487

Après #487/#488, le workflow trusted a appliqué le catalogue via :

```text
ddev drush emerging:governed-content --all
```

Preuve sur `main@5b65d6af002eec08eb34b8d3ff6fb8955e39c123` :

```text
gateway: 32120328538 / SUCCESS
browser validation: 32120339996 / SUCCESS
artifact id: 9318375593
artifact sha256: 2ac5523c693bbff85c2d489f2b7525317ec73d41043cfdc5d30e9e7ddaadad48
```

Résultat de l'apply :

- trois contenus légaux créés/mappés ;
- traductions FR/EN enregistrées ;
- `writes_attempted=true` ;
- `blocking_errors=false` ;
- 0 warning ;
- 0 erreur ;
- menus intacts ;
- configuration active/sync sans drift après apply ;
- Browser Validation desktop/mobile PASS ;
- 0 erreur console, page, réseau ou HTTP inattendue ;
- cleanup runner PASS.

La façade Governed Content est donc prouvée en lecture, dry-run et écriture
réelle.

## 6. Adoption production — #491

#491/#492 ont basculé `scripts/deploy-production.sh` vers :

```text
emerging:governed-content --all
```

sans changer l'ordre ni les protections du déploiement :

```text
updb
-> cim
-> production Config Split
-> Governed Content
-> cr
```

Preuve post-merge sur `main@f77de43b587b42968405ced2ba1715dfcae0f8bc` :

```text
CI #884: 32121371583 / SUCCESS
Deploy Production #185: 32121371710 / SUCCESS
```

Les logs production confirment :

- release clonée sur le SHA exact ;
- backup DB et maintenance mode opérationnels ;
- `updb`, `cim` et Config Split réussis ;
- étape `[deploy] Governed Content` exécutée ;
- trois contenus gouvernés valides ;
- 22 actions, 0 warning, 0 erreur ;
- `blocking_errors=false` ;
- `writes_attempted=true` ;
- `menus_touched=false` ;
- maintenance OFF et fin `[deploy] SUCCESS`.

La production ne dépend donc plus du nom historique `emerging:content-sync`.

## 7. Revalidation Drupal/contrib

Revalidation effectuée le 18 août 2026 à partir des sources officielles Drupal
et Drush.

### Drupal Core Default Content

Drupal 11 expose `Drupal\Core\DefaultContent`, avec import/export YAML et des
primitives utiles. L'API reste expérimentale ; elle ne remplace pas une surface
production qui possède déjà mapping, dry-run, audit et rollback éprouvés.

### `drupal/default_content`

La branche 2.x reste expérimentale et n'apporte pas aujourd'hui de motif de
migration justifiant une nouvelle dépendance.

### `drupal/default_content_deploy`

Une stable 2.1.4 existe, mais aucune parité n'est démontrée avec les garanties
Agency et la branche 2.2 a documenté un problème critique HAL sur les références.

Décision : **évaluer plus tard seulement sur preuve de parité**.

### Workspaces

Workspaces répond au staging intra-site, pas à la matérialisation
repository -> environnements des contenus Governed Content.

### Drush

Agency utilise Drush 13.7.x. Les aliases de `#[CLI\Command]` permettent de
conserver les anciens noms sans wrapper. La migration vers Symfony Console est
un chantier distinct et ne doit pas être mélangée à #442.

## 8. Matrice KEEP / CONVERGE / RETIRE-LATER

| Surface | Décision | Motif |
|---|---|---|
| catalogue YAML + loader/validator | KEEP | source versionnée des 3 contenus gouvernés |
| mapping repository | KEEP | identité locale, audit et protections lifecycle |
| `ContentSyncManager` | KEEP | matérialisation, dry-run, traductions, aliases et prune guards |
| `emerging:governed-content` | CANONICAL | façade publique prouvée en lecture, écriture et production |
| `emerging:governed-content:validate` | CANONICAL | façade publique de validation |
| `emerging:content-sync` | DEPRECATED COMPATIBILITY | alias conservé sans date de retrait sous #442 |
| `emerging:content-sync:validate` | DEPRECATED COMPATIBILITY | alias conservé sans date de retrait sous #442 |
| `ContentSyncReleaseManager` | RETIRE-LATER | plus de pending ; historique/rollback |
| `content-sync:release` | RETIRE-LATER | aucun nouveau lot ordinaire attendu |
| `content-sync:readmit` | RETIRE-LATER | conserver jusqu'à décision explicite de rollback |
| scripts de preuve de transition #440/#441 | RETIRE-LATER | preuves historiques ; ne plus étendre sans besoin |
| `scripts/deploy-production.sh` | CONVERGED | utilise Governed Content en production |
| services DI `content_sync_*` | KEEP | renommage interne sans gain de garantie métier |
| classes/namespaces `ContentSync*` | KEEP | dette nominale interne, pas de bénéfice runtime à renommer |
| Default Content / DCD / Workspaces | EVALUATE LATER | aucune parité démontrée justifiant une migration |

## 9. Politique de compatibilité après #442

#442 ne supprime aucun ancien nom et ne retire aucune primitive de rollback.

Règles de sortie :

1. `emerging:governed-content*` est le vocabulaire à utiliser dans toute nouvelle
   documentation, automatisation et opération ;
2. `emerging:content-sync` et `:validate` restent fonctionnels comme aliases ;
3. leur retrait éventuel exige un ticket futur explicite et une preuve que plus
   aucun consommateur supporté n'en dépend ;
4. `release/readmit` restent disponibles pour historique/rollback tant qu'une
   politique de rétention dédiée n'a pas été décidée ;
5. les scripts trusted de transition #440/#441 ne sont plus étendus et pourront
   être archivés/simplifiés dans un chantier futur distinct ;
6. les services/classes internes ne sont pas renommés par cosmétique.

Invariant :

```text
converger le vocabulaire
!= dupliquer le moteur
!= casser le déploiement
!= supprimer les garanties éprouvées
```
