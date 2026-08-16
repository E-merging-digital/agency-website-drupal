# Governed Content — preuve de transition DDEV

Statut : route d’exécution gouvernée pour le pilote `#440`.

Cette capacité complète la Browser Validation générale. Elle sert lorsqu’une preuve doit conserver la même base Drupal entre un état de départ encore géré par Content Sync et le HEAD exact d’une PR qui libère des contenus.

## Modèle de confiance

Le dépôt étant public, aucune PR ne déclenche directement le runner self-hosted.

Le chemin autorisé est :

```text
branche de contrôle dédiée
-> .agency/governed-content-transition-request.json
-> gateway GitHub-hosted
-> revalidation live de la PR et du SHA
-> workflow trusted depuis main
-> runner agency-browser-runner-01
-> DDEV isolé
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
  "head_sha": "<sha exact sur 40 caractères>",
  "proof_profile": "case-studies-440"
}
```

Le gateway refuse notamment un acteur de contrôle autre que `E-merging-digital`, une PR fermée, une base autre que `main`, un fork ou un SHA devenu obsolète.

Le workflow trusted revalide ces invariants avant toute allocation self-hosted. Il refuse également les PR qui modifient les surfaces d’exécution de confiance : `.github/`, `.ddev/`, `.agency/`, `scripts/runner/`, `tests/browser/`, `package.json`, `package-lock.json` et `playwright.config.mjs`.

## Profil `case-studies-440`

Le profil courant est volontairement borné aux trois IDs réellement présents dans le catalogue :

```text
cas-client-refonte-drupal-institutionnelle
cas-client-migration-drupal-11
cas-client-integration-ia-editoriale
```

La preuve exécute :

1. checkout du SHA de base de la PR ;
2. installation DDEV depuis `--existing-config` ;
3. Content Sync complet pour matérialiser les mappings `active` ;
4. snapshot mappings, IDs/UUID, statuts, FR/EN, aliases, titres et révisions ;
5. checkout du HEAD exact de la PR sans détruire la base DDEV ;
6. `updb`, `cim` et cache rebuild, sans Content Sync préalable ;
7. release dry-run puis apply des trois mappings avec le code du candidat ;
8. vérification de conservation d’identité, publication, traductions et aliases ;
9. Content Sync complet après retrait du catalogue ;
10. modification éditoriale bornée d’un cas client, avec nouvelle révision ;
11. nouveau Content Sync et preuve que l’édition Drupal survit ;
12. validation Chromium desktop et mobile des six aliases FR/EN avec captures ;
13. rollback par restauration contrôlée des trois payloads/catalogue depuis le SHA de base, readmission explicite et resynchronisation ;
14. preuve que l’identité et le contenu canonique sont récupérés ;
15. upload des snapshots/logs/captures/résultat JSON ;
16. destruction de l’environnement DDEV et vérification d’un workspace propre.

## Limites

Cette route n’effectue aucune mutation de production et ne consomme aucun secret provider. La modification éditoriale de preuve reste confinée à la base DDEV éphémère du run.

Le profil `case-studies-440` est fixe. Les futurs lots `#441` doivent réutiliser le même modèle de contrôle mais ajouter un profil trusted explicitement revu ; ils ne doivent pas transmettre une liste d’IDs ou une commande shell arbitraire depuis la branche de contrôle.

## Lecture de l’artifact

L’artifact `agency-governed-content-transition-<run>-<attempt>` contient notamment :

- `result.json` : verdict machine lisible et SHAs exacts ;
- `snapshots/base-*` : état avant release ;
- `snapshots/released-*` : état immédiatement après release ;
- `snapshots/post-deploy-*` : état après Content Sync du candidat ;
- `snapshots/after-editorial-*` : preuve de persistance éditoriale ;
- `snapshots/rollback-*` : état après readmission/resync ;
- `browser/*.png` : captures des six aliases, desktop et mobile ;
- `logs/*.txt` : sorties des commandes de release, sync, readmit et Playwright.

Un PASS n’est valide que pour le couple `base_sha` / `target_sha` enregistré dans `result.json`. Un nouveau commit sur la PR impose un nouveau run.
