# Governed Content — preuve de transition DDEV

Statut : route d’exécution gouvernée pour le pilote `#440`.

Cette capacité complète la Browser Validation générale. Elle sert lorsqu’une preuve doit conserver la même base Drupal entre un état de départ encore géré par Content Sync, un commit de release contrôlée et le HEAD exact d’une PR qui retire ensuite les contenus de la gouvernance Git.

## Modèle de confiance

Le dépôt étant public, aucune PR ne déclenche directement le runner self-hosted.

Le chemin autorisé est :

```text
branche de contrôle dédiée
-> .agency/governed-content-transition-request.json
-> gateway GitHub-hosted
-> revalidation live de la PR, du release candidate et du HEAD exact
-> workflow trusted depuis main
-> runner agency-browser-runner-01
-> même base DDEV sur toute la transition
-> artifact de preuve
-> cleanup always()
```

La branche de contrôle est :

```text
agency/governed-content-transition-dispatch-control
```

La mutation autorisée sur cette branche est limitée à :

```text
.agency/governed-content-transition-request.json
```

Le schéma du pilote est strict :

```json
{
  "request_id": "agency-governed-pr444-proof-001",
  "pr_number": 444,
  "head_sha": "<HEAD exact de la PR sur 40 caractères>",
  "release_sha": "<release candidate revu sur 40 caractères>",
  "proof_profile": "case-studies-440"
}
```

Le gateway refuse notamment un acteur de contrôle autre que `E-merging-digital`, une PR fermée, une base autre que `main`, un fork ou un HEAD devenu obsolète.

Le workflow trusted revalide ces invariants avant toute allocation self-hosted. Il refuse également les PR qui modifient les surfaces d’exécution de confiance : `.github/`, `.ddev/`, `.agency/`, `scripts/runner/`, `tests/browser/`, `package.json`, `package-lock.json` et `playwright.config.mjs`.

Le `release_sha` n’est pas une commande ni un ref arbitraire accepté tel quel. Pour le profil `case-studies-440`, le gateway trusted prouve qu’il est un descendant du SHA de base et un ancêtre du HEAD exact, que le diff base -> release candidate contient exactement la policy, le catalogue et les trois payloads du pilote, que les trois IDs restent autorisés par la policy à ce stade et qu’ils ont déjà été retirés du catalogue et des payloads. Le HEAD final doit, lui, avoir retiré ces IDs de l’allowlist de release.

## Profil `case-studies-440`

Le profil courant est volontairement borné aux trois IDs réellement présents dans le catalogue de départ :

```text
cas-client-refonte-drupal-institutionnelle
cas-client-migration-drupal-11
cas-client-integration-ia-editoriale
```

La preuve suit trois états distincts :

```text
base main avec mappings active
-> release candidate : payloads/catalogue retirés, IDs encore autorisés à release
-> HEAD final : IDs retirés de l’allowlist après release
```

Cette séparation est obligatoire : lancer `release` directement sur le HEAD final serait incorrect puisque ce HEAD ne doit plus autoriser une nouvelle release de ces IDs.

La preuve exécute :

1. checkout du SHA de base de la PR ;
2. installation DDEV depuis `--existing-config` ;
3. Content Sync complet pour matérialiser les mappings `active` ;
4. snapshot mappings, IDs/UUID, statuts, FR/EN, aliases, titres et révisions ;
5. checkout du `release_sha` sans détruire la base DDEV ;
6. release dry-run puis apply des trois mappings avant tout Content Sync du catalogue réduit ;
7. vérification de conservation d’identité, publication, traductions et aliases ;
8. Content Sync complet avec les trois contenus absents du catalogue ;
9. modification éditoriale bornée d’un cas client, puis nouveau Content Sync prouvant que l’édition Drupal survit ;
10. validation Chromium desktop et mobile des six aliases FR/EN avec captures ;
11. rollback contrôlé vers les payloads/catalogue de base et readmission explicite ;
12. replay de la release sur le même DDEV afin de préparer la vérification du HEAD final ;
13. checkout du `head_sha` exact sans détruire la base ;
14. `updb`, `cim`, Content Sync et cache rebuild sur le HEAD final ;
15. preuve que les mappings restent `released`, que les IDs/UUID, publication, FR/EN et aliases sont préservés ;
16. nouvelle modification éditoriale bornée, nouveau Content Sync et preuve de persistance au HEAD exact ;
17. seconde validation Chromium desktop/mobile des six aliases ;
18. rollback final par restauration contrôlée des trois payloads/catalogue depuis le SHA de base, readmission explicite et resynchronisation ;
19. preuve que l’identité et le contenu canonique sont récupérés ;
20. upload des snapshots/logs/captures et des deux résultats JSON ;
21. destruction de l’environnement DDEV et vérification d’un workspace propre.

## Limites

Cette route n’effectue aucune mutation de production et ne consomme aucun secret provider. Les modifications éditoriales de preuve restent confinées à la base DDEV éphémère du run.

Le profil `case-studies-440` est fixe. Les futurs lots `#441` doivent réutiliser le même modèle de contrôle mais ajouter un profil trusted explicitement revu ; ils ne doivent pas transmettre une liste d’IDs ou une commande shell arbitraire depuis la branche de contrôle.

## Lecture de l’artifact

L’artifact `agency-governed-content-transition-<run>-<attempt>` contient notamment :

- `result.json` : verdict de la première transition base -> release candidate ;
- `final-result.json` : verdict du replay release candidate -> HEAD exact ;
- `snapshots/base-*` : état avant release ;
- `snapshots/released-*` et `post-deploy-*` : état du release candidate ;
- `snapshots/after-editorial-*` : première preuve de persistance éditoriale ;
- `snapshots/final-*` : état et rollback du HEAD exact ;
- `browser/*.png` : captures des six aliases, desktop et mobile ;
- `logs/*.txt` : sorties des commandes de release, sync, readmit et Playwright.

Un PASS n’est valide que pour le triplet `base_sha` / `release_sha` / `target_sha` enregistré dans les résultats. Un nouveau commit sur la PR impose un nouveau run.