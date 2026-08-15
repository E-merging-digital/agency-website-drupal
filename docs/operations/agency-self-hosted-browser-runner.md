# Agency self-hosted browser validation runner

Status: **PROVISIONING**  
Issue: #400  
Repository: `E-merging-digital/agency-website-drupal`

## 1. Architecture retenue

Agency réutilise la machine déjà éprouvée `preflight-runner-01`, mais **pas** le
runner GitHub Preflight lui-même.

La machine héberge deux runners repository-scoped distincts :

```text
preflight-runner-01
|
+-- runner Preflight existant
|   account: preflight-runner
|   labels: self-hosted, linux, x64, preflight, ddev
|
+-- runner Agency Browser
    account: agency-runner
    name: agency-browser-runner-01
    labels: self-hosted, linux, x64, agency, ddev, browser
```

Le compte, le répertoire d'installation, le workdir et le service systemd du
runner Agency sont séparés de Preflight. #400 n'autorise aucune modification du
runner/service Preflight.

## 2. Pourquoi le runner n'est jamais déclenché directement par une PR

Le dépôt Agency est public. Un self-hosted runner ne doit pas exécuter du code
d'une fork PR ou d'un workflow modifiable par un contributeur non fiable.

La browser validation self-hosted est donc **trusted dispatch only** :

```text
agent / opérateur autorisé
        |
        v
branche de contrôle dédiée
        |
        v
workflow GitHub-hosted de validation/dispatch
        |
        v
validation cible same-repository + exact HEAD
        |
        v
workflow trusted sur main
        |
        v
runner Agency self-hosted
```

Le workflow self-hosted n'a aucun trigger `pull_request` ou `push`.

## 3. Workflows

### `.github/workflows/agent-browser-validation-dispatch.yml`

Surface de contrôle GitHub-hosted. Elle observe uniquement :

```text
branch = agency/browser-validation-dispatch-control
file = .agency/browser-validation-request.json
```

Elle exige :

- acteur `E-merging-digital` ;
- exactement un fichier modifié ;
- schéma JSON strict ;
- `request_id` borné ;
- SHA exact ;
- soit `pr_number=0` et SHA égal au `main` live ;
- soit PR `OPEN`, base `main`, same-repository et HEAD exact.

Le job ne reçoit aucun secret externe. Son `GITHUB_TOKEN` est limité à
`actions: write`, `contents: read`, `pull-requests: read` et sert uniquement à
dispatcher le workflow allowlisté.

Format de requête :

```json
{
  "request_id": "agency-browser-20260815-001",
  "pr_number": 0,
  "head_sha": "40-character-lowercase-sha"
}
```

`pr_number=0` sert à la preuve bootstrap sur `main`. Pour une PR normale,
`pr_number` doit porter le numéro exact de la PR et `head_sha` son HEAD live.

### `.github/workflows/self-hosted-browser-validation.yml`

Workflow trusted, `workflow_dispatch` uniquement.

Avant qu'un job atteigne la machine self-hosted, un job GitHub-hosted valide :

- acteur autorisé (`E-merging-digital` ou `github-actions[bot]`) ;
- request id ;
- SHA exact ;
- PR same-repository / base `main` / OPEN ;
- absence de changement de l'infrastructure `.github/`, `.ddev/`, `.agency/`
  ou `scripts/runner/` sur une cible PR.

Le job self-hosted cible uniquement :

```yaml
runs-on:
  - self-hosted
  - linux
  - x64
  - agency
  - ddev
  - browser
```

## 4. État Drupal reproductible

Aucun dump local n'est utilisé.

Chaque run crée un nom DDEV éphémère avec un `config.*.yaml` local, puis :

```text
checkout exact
-> npm ci
-> Chromium Playwright
-> ddev start
-> composer install
-> drush site:install --existing-config
-> emerging:content-sync:validate
-> emerging:content-sync --all --dry-run
-> emerging:content-sync --all
-> drush cr/status/config:status
-> npm run browser:validate
```

Le vertical slice `/fr/blog` n'exige pas l'Article1 local : il vérifie la page
Blog, le DOM, la navigation `Services -> Blog`, console/réseau et les rendus
mobile/desktop. La config versionnée + Content Sync constituent donc la source
de données de validation.

## 5. DDEV isolation et nettoyage

Le fichier créé par le workflow :

```text
.ddev/config.gate-browser-ci.yaml
```

porte un nom :

```text
agency-browser-<run_id>-<attempt>
```

Les `config.*.yaml` sont des overrides DDEV supportés. Le run finit par :

```bash
ddev delete --omit-snapshot --yes
rm -f .ddev/config.gate-browser-ci.yaml
git diff --check
git status --porcelain
```

La suppression omet volontairement le snapshot : la base est une fixture
reconstructible, pas une donnée à conserver.

## 6. Preuves publiées

Le workflow upload avec `actions/upload-artifact` :

```text
artifacts/browser-validation/
playwright-report/
test-results/
```

La preuve principale reste :

```text
artifacts/browser-validation/result.json
```

et les screenshots desktop/mobile. Les traces ne sont attendues qu'en cas de
besoin/échec selon la configuration Playwright.

Retention initiale : 14 jours.

## 7. Provisioning du runner Agency

### Prérequis hôte

La machine cible est `preflight-runner-01`, déjà prouvée pour Docker et DDEV.
Le bootstrap vérifie aussi :

- `curl`, `tar`, `sha256sum` ;
- Docker fonctionnel ;
- DDEV fonctionnel ;
- Node.js >= 20 et npm ;
- accès réseau à GitHub/npm ;
- droits root pour l'installation one-shot.

Le script :

```text
scripts/runner/bootstrap-agency-browser-runner.sh
```

utilise actuellement le runner GitHub officiel `2.336.0` et vérifie le SHA-256
de l'archive Linux x64 avant extraction.

Il crée :

```text
account = agency-runner
dir = /opt/actions-runner-agency
workdir = /opt/actions-runner-agency/_work
runner = agency-browser-runner-01
labels = agency,ddev,browser (+ labels GitHub par défaut)
```

Il ajoute uniquement `agency-runner` au groupe Docker et installe une fois les
dépendances système Chromium requises par Playwright. Aucun `sudo` n'est donné
au compte runner pour les jobs ordinaires.

### Seul gate humain de provisioning

L'intégration GitHub utilisée par l'agent ne possède pas la permission
`Self-hosted runners`; elle ne peut donc pas obtenir le token éphémère
d'enregistrement.

Dans GitHub :

```text
Agency repository
-> Settings
-> Actions
-> Runners
-> New self-hosted runner
-> Linux / x64
```

Copier uniquement le **registration token** éphémère. Ne jamais le committer.

Sur `preflight-runner-01`, depuis un checkout de la branche #400 ou après merge :

```bash
sudo bash scripts/runner/bootstrap-agency-browser-runner.sh
```

Le script demande le token sans écho. Variante non interactive :

```bash
sudo env AGENCY_RUNNER_REGISTRATION_TOKEN='...' \
  bash scripts/runner/bootstrap-agency-browser-runner.sh
```

Éviter de mettre ce token dans l'historique shell. La saisie interactive est
préférée.

Après exécution, vérifier dans GitHub que :

```text
agency-browser-runner-01 = Idle/Online
labels = self-hosted, Linux, X64, agency, ddev, browser
```

## 8. Bootstrap de la branche de contrôle

À faire seulement une fois les workflows #400 fusionnés sur `main` :

```text
branch = agency/browser-validation-dispatch-control
base = main exact
```

Aucun fichier métier ne vit sur cette branche. Les requêtes successives ne
modifient que :

```text
.agency/browser-validation-request.json
```

L'agent GitHub peut ensuite produire un commit de requête sans clic humain ; le
workflow GitHub-hosted vérifie et déclenche la browser validation.

## 9. Première preuve unattended

Une fois #399 et #400 présents sur `main` :

1. créer/mettre à jour la requête de contrôle avec `pr_number=0` et le SHA exact
   de `main` ;
2. observer le gateway GitHub-hosted ;
3. observer le run self-hosted ;
4. exiger `result.json = PASS` ;
5. vérifier les screenshots desktop/mobile ;
6. vérifier console/network sans erreurs inattendues ;
7. vérifier l'artifact GitHub ;
8. vérifier cleanup DDEV + workspace propre.

Ce run constitue la preuve DoD « unattended Agency réel ».

## 10. Playwright MCP

MCP est optionnel et ne participe jamais au verdict CI.

Sur la machine Agency, la voie préférée pour un agent local est **stdio** :

```bash
codex mcp add playwright npx "@playwright/mcp@latest"
```

ou configuration Codex équivalente.

Le serveur Playwright MCP officiel utilise localhost par défaut lorsqu'un
transport réseau est demandé. Agency interdit :

```text
--host 0.0.0.0
exposition Internet
port forwarding public
profil navigateur contenant des credentials persistants non maîtrisés
```

Un futur transport HTTP, s'il devient nécessaire, doit rester `127.0.0.1` et
être atteint par une route privée/gouvernée. Le simple fait que MCP soit installé
ne prouve pas qu'un control plane distant peut l'atteindre.

## 11. Authentification Drupal future

Les scénarios authentifiés ne sont pas nécessaires à #400.

La stratégie cible reste `storageState` Playwright sous :

```text
tests/browser/.auth/
```

Ce répertoire doit rester ignoré. Aucun mot de passe, cookie ou état de session
n'est uploadé comme artifact par défaut.

Avant d'ajouter un rôle Drupal :

- créer un ticket/use case réel ;
- fournir le secret au runtime via une surface sécurisée ;
- créer la session pendant le job ;
- exclure auth state, pages privées et secrets des screenshots/logs/artifacts.

## 12. Merge gate

La browser validation ne devient **pas** un required check dans #400.

Séquence :

```text
runner provisionné
-> première preuve unattended PASS
-> quelques runs réels stables
-> seulement ensuite décision séparée sur un merge gate
```

## 13. Sources techniques

- GitHub self-hosted runners / labels / accès : documentation GitHub Actions.
- DDEV `config.*.yaml` et `ddev delete --omit-snapshot --yes` : documentation DDEV.
- Drush `site:install --existing-config` : documentation Drush.
- Playwright CI / Chromium : documentation Playwright.
- Playwright MCP : dépôt officiel `microsoft/playwright-mcp`.
