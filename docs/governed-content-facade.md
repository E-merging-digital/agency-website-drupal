# Governed Content — façade et dette custom après migration

Statut : **façade Governed Content prouvée ; adoption production #491**  
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

Le moteur PHP historique conserve encore ses noms internes `ContentSync`, mais
son rôle runtime courant est la matérialisation déterministe du petit catalogue
Governed Content.

## 2. Façade CLI

#481/#482 ont introduit deux aliases sans wrapper ni duplication de logique :

```text
emerging:governed-content
-> alias de emerging:content-sync

emerging:governed-content:validate
-> alias de emerging:content-sync:validate
```

Les anciens noms restent disponibles comme compatibilité. Ils ne sont pas
supprimés par #442 tant qu'une fenêtre de dépréciation/retrait n'a pas été
explicitement décidée et prouvée.

Aucun nouvel alias Governed Content n'est ajouté à `release` ou `readmit`. Ces
commandes appartiennent au lifecycle historique de migration/rollback et restent
classées `RETIRE-LATER`.

## 3. Admission policy durable

Après #441, le catalogue ne combine plus deux classes d'admission.

L'invariant durable est :

```text
catalogue IDs == GovernedContentPolicy::GOVERNED_CONTENT_IDS
LEGACY_RELEASE_PENDING_IDS == []
```

Toute nouvelle entrée de catalogue doit être explicitement admise comme Governed
Content dans la policy. Il n'existe plus de file grandfathered permettant de
réintroduire du contenu marketing ou éditorial ordinaire.

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

Après #487/#488, le workflow trusted a remplacé uniquement son apply historique
par :

```text
ddev drush emerging:governed-content --all
```

La preuve a été rejouée sur `main@5b65d6af002eec08eb34b8d3ff6fb8955e39c123` :

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

La nouvelle façade est donc prouvée en lecture, dry-run et écriture réelle.

## 6. Adoption production — #491

Après la preuve #487, le consommateur repository-owned
`scripts/deploy-production.sh` peut utiliser :

```text
emerging:governed-content --all
```

tout en conservant `emerging:content-sync --all` comme compatibilité au niveau
Drush.

#491 ne change ni l'ordre du déploiement ni ses protections :

```text
updb
-> cim
-> production Config Split
-> Governed Content
-> cr
```

La CI doit verrouiller cette séquence. Après merge, le `Deploy Production` du
même SHA doit fournir la preuve finale que le nouvel alias fonctionne sur la
surface production réelle.

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

Agency utilise Drush 13.7.x. Les aliases de `#[CLI\Command]` permettent la
convergence additive actuelle. La migration vers Symfony Console est un chantier
distinct et ne doit pas être mélangée à #442.

## 8. Matrice KEEP / CONVERGE / RETIRE-LATER

| Surface | Décision | Motif |
|---|---|---|
| catalogue YAML + loader/validator | KEEP | source versionnée des 3 contenus gouvernés |
| mapping repository | KEEP | identité locale, audit et protections lifecycle |
| `ContentSyncManager` | KEEP | matérialisation, dry-run, traductions, aliases et prune guards |
| `emerging:governed-content` | PREFERRED | façade prouvée en lecture et écriture |
| `emerging:governed-content:validate` | PREFERRED | façade prouvée, même moteur |
| `emerging:content-sync` | COMPATIBILITY | ancien nom conservé temporairement |
| `emerging:content-sync:validate` | COMPATIBILITY | ancien nom conservé temporairement |
| `ContentSyncReleaseManager` | RETIRE-LATER | plus de pending ; historique/rollback |
| `content-sync:release` | RETIRE-LATER | aucun nouveau lot ordinaire attendu |
| `content-sync:readmit` | RETIRE-LATER | conserver jusqu'à décision de fenêtre de rollback |
| scripts de preuve de transition #440/#441 | RETIRE-LATER | preuves historiques ; ne plus étendre sans besoin |
| `scripts/deploy-production.sh` | CONVERGE #491 | bascule après preuves #483/#487 |
| services DI `content_sync_*` | KEEP | renommage interne sans gain de garantie métier |
| Default Content / DCD / Workspaces | EVALUATE LATER | aucune parité démontrée justifiant une migration |

## 9. Convergence restante sous #442

Après la preuve production de #491 :

1. décider si le nom canonique Drush doit devenir `emerging:governed-content`
   avec l'ancien nom rétrogradé en alias, ou si la compatibilité actuelle suffit ;
2. documenter une fenêtre explicite avant tout retrait de `release/readmit` ;
3. décider du devenir des scripts trusted de transition #440/#441 ;
4. ne renommer les services/classes internes que si un gain concret est démontré ;
5. fermer la surface opérateur temporaire #489/#490 lorsque #442 est terminé.

Invariant :

```text
converger le vocabulaire
!= dupliquer le moteur
!= casser le déploiement
!= supprimer les garanties éprouvées
```
