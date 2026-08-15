# Validation navigateur et UI

## Objectif

Les tests Drupal existants restent la preuve de référence pour la logique
serveur, la configuration et les parcours couverts par `BrowserTestBase`.
Agency dispose en complément d'une validation dans un vrai navigateur
JavaScript pour les changements visibles ou interactifs.

La chaîne de preuve cible est :

```text
implementation
-> tests techniques ciblés
-> vrai Chromium
-> interaction
-> DOM rendu
-> console / erreurs page
-> réseau same-origin
-> desktop + mobile
-> screenshots / traces
-> PASS / FAIL
```

La source de vérité sur les capacités machine disponibles est :

```text
docs/operations/execution-capabilities.md
```

Tout agent doit la relire avant de conclure qu'un runner, Playwright,
Playwright MCP ou Chrome DevTools MCP est indisponible.

## Stack et capacités

Le vertical slice reproductible utilise :

- Playwright Test ;
- Chromium ;
- Node.js 20+ (Node 24 sur le runner gouverné actuel) ;
- DDEV comme environnement Agency réel ;
- desktop 1440x900 ;
- mobile 390x844.

Le runner auto-hébergé Agency dispose également de :

```text
Playwright MCP      = AVAILABLE
Chrome DevTools MCP = AVAILABLE
```

Ces MCP sont des capacités du project executor. Ils sont optionnels pour le
diagnostic interactif et ne participent jamais au verdict reproductible CI.

Invariant :

```text
operator-surface capability
!=
project-executor capability
```

L'absence d'un bouton ou d'un outil MCP directement visible dans un cockpit
ChatGPT ne signifie donc pas que le MCP est absent du runner.

## Exécution locale

Depuis la racine :

```bash
npm ci
npm run browser:install
ddev start
npm run browser:validate
```

Le runner choisit la cible dans cet ordre :

1. `BROWSER_VALIDATION_BASE_URL` ;
2. `PLAYWRIGHT_BASE_URL` ;
3. `DDEV_PRIMARY_URL` ;
4. sinon `ddev describe -j`.

Pour forcer une cible :

```bash
BROWSER_VALIDATION_BASE_URL=https://example.test npm run browser:validate
```

Exécution partielle :

```bash
npm run browser:validate -- --project=desktop
npm run browser:validate -- --project=mobile
```

Un run partiel sert au diagnostic ; la preuve complète attend les deux projets.

## Exécution gouvernée sur le runner Agency

La route unattended est documentée dans :

```text
docs/operations/agency-self-hosted-browser-runner.md
```

Elle reconstruit un environnement isolé :

```text
exact checkout
-> Node / npm ci
-> Playwright Chromium
-> DDEV isolé
-> Composer
-> drush site:install --existing-config
-> Content Sync validate / dry-run / apply
-> browser validation
-> GitHub artifacts
-> cleanup DDEV / workspace propre
```

Le runner est `agency-browser-runner-01` sur `preflight-runner-01` et la route
self-hosted reste trusted-dispatch-only.

## Contrat public initial

Le contrat est :

```text
tests/browser/contracts/public-blog.json
```

Acteur : `anonymous`. Cible : `/fr/blog`.

Le scénario :

- ouvre réellement le Blog ;
- vérifie le statut HTTP initial et le DOM ;
- navigue `Services -> Blog` avec des locators sémantiques ;
- vérifie le H1 et `lang="fr"` ;
- détecte un débordement horizontal évident ;
- collecte erreurs console et exceptions page ;
- collecte 4xx/5xx same-origin inattendus et requêtes échouées ;
- produit une preuve desktop et mobile.

## Résultat machine-readable

La preuve principale est :

```text
artifacts/browser-validation/result.json
```

Forme attendue :

```json
{
  "result": "PASS",
  "functional": "PASS",
  "dom": "PASS",
  "visual_desktop": "PASS",
  "visual_mobile": "PASS",
  "console_errors": 0,
  "unexpected_http_4xx": 0,
  "http_5xx": 0
}
```

Verdicts :

- `PASS` : preuve complète réussie ;
- `FAIL` : la validation a été exécutée et un contrôle a échoué ;
- `NOT_RUN` : l'environnement requis n'était pas disponible.

`NOT_RUN` n'est jamais un succès.

## Preuves et revue visuelle

Les artifacts peuvent contenir :

```text
artifacts/browser-validation/result.json
artifacts/browser-validation/evidence/desktop.json
artifacts/browser-validation/evidence/mobile.json
artifacts/browser-validation/screenshots/*.png
artifacts/browser-validation/test-results/**/test-failed-1.png
artifacts/browser-validation/test-results/**/trace.zip
playwright-report/
```

Un agent peut récupérer ces artifacts GitHub et examiner lui-même les captures.
La revue graphique ne dépend donc pas uniquement des assertions ou des logs.

Ordre de diagnostic recommandé :

1. lire `result.json` ;
2. lire l'evidence du projet en échec ;
3. examiner la capture desktop/mobile ;
4. examiner l'accessibility snapshot / error context ;
5. ouvrir la trace si nécessaire ;
6. utiliser Playwright MCP ou Chrome DevTools MCP sur le runner pour une
   investigation interactive plus profonde si le cas le justifie ;
7. corriger puis relancer la preuve reproductible.

## Playwright MCP

Playwright MCP est disponible sur le runner Agency pour une boucle interactive :

- navigation ;
- interaction ;
- snapshot d'accessibilité ;
- DOM rendu ;
- diagnostic avant de formaliser un test.

Il complète Playwright Test mais ne le remplace pas.

## Chrome DevTools MCP

Chrome DevTools MCP est également disponible sur le runner Agency. Il est
pertinent lorsque le diagnostic nécessite davantage de profondeur DevTools,
notamment :

- console ;
- réseau ;
- CSS / layout ;
- JavaScript/runtime ;
- inspection navigateur détaillée.

Il n'est pas un prérequis de la browser validation déterministe.

## Authentification future

Le scénario public ne nécessite aucun secret.

Pour les futurs parcours authentifiés, utiliser une session éphémère et
Playwright `storageState` sous :

```text
tests/browser/.auth/
```

Ce répertoire reste ignoré. Ne jamais committer ni publier comme artifact
standard : mot de passe, cookie, état authentifié ou profil navigateur sensible.

## Accessibilité

Cette capacité n'est pas encore un audit WCAG exhaustif. Les locators
sémantiques (`getByRole`) alignent déjà les interactions sur l'arbre
d'accessibilité. Des contrôles dédiés pourront être ajoutés dans des tickets
séparés.

## Politique de merge gate

La Browser Validation n'est pas encore un required check GitHub. La séquence est :

```text
runner opérationnel
-> preuves réelles stables
-> corrections des défauts de bootstrap
-> plusieurs runs PASS
-> décision séparée sur un éventuel required check
```

Pour tout changement frontend ou interactif significatif, la validation
navigateur réelle et l'examen des preuves visuelles sont néanmoins attendus.
