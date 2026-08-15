# Validation navigateur et UI

## Pourquoi

Les tests Drupal existants restent la preuve de référence pour la logique serveur,
la configuration et les parcours couverts par `BrowserTestBase`. Ils ne remplacent
pas l'observation d'un vrai navigateur JavaScript.

La validation navigateur ajoute une preuve complémentaire pour les changements
visibles ou interactifs :

```text
implémentation
-> tests techniques ciblés
-> vrai navigateur
-> interaction
-> DOM rendu
-> console / erreurs page
-> réseau same-origin
-> desktop + mobile
-> preuve PASS / FAIL
```

Cette première capacité est volontairement petite. Elle n'est pas encore un
merge gate et ne constitue pas une suite de visual regression pixel-perfect.

## Stack

- Playwright Test ;
- Chromium uniquement pour le vertical slice initial ;
- Node.js 20 ou plus récent ;
- DDEV comme environnement Agency réel ;
- aucun MCP obligatoire.

Playwright MCP peut être utilisé par un agent pour l'inspection interactive, mais
la validation reproductible décrite ici ne dépend jamais d'un LLM ou d'un MCP.

## Installation

Depuis la racine du projet :

```bash
npm install
npm run browser:install
```

La version de Playwright Test est épinglée dans `package.json`. Le navigateur
Chromium doit être installé une fois par environnement avec
`npm run browser:install`.

Les binaires Node et les artefacts Playwright ne sont pas versionnés.

## Lancer la preuve Agency réelle

Démarrer d'abord Drupal :

```bash
ddev start
```

Puis :

```bash
npm run browser:validate
```

Le runner :

1. utilise `BROWSER_VALIDATION_BASE_URL`, `PLAYWRIGHT_BASE_URL` ou
   `DDEV_PRIMARY_URL` si l'une de ces variables est définie ;
2. sinon exécute `ddev describe -j` et détecte l'URL primaire ;
3. attend que la cible du contrat réponde réellement ;
4. lance Playwright contre cette URL ;
5. exécute le scénario en desktop et mobile ;
6. écrit un résultat machine-readable.

Pour forcer une autre cible d'environnement sans modifier le code :

```bash
BROWSER_VALIDATION_BASE_URL=https://example.test npm run browser:validate
```

Sous PowerShell :

```powershell
$env:BROWSER_VALIDATION_BASE_URL = "https://example.test"
npm run browser:validate
Remove-Item Env:BROWSER_VALIDATION_BASE_URL
```

## Lancer un seul contexte

Exemple desktop :

```bash
npm run browser:validate -- --project=desktop
```

Exemple mobile :

```bash
npm run browser:validate -- --project=mobile
```

Un run partiel est utile pour diagnostiquer un problème, mais le contrat complet
attend les deux projets pour une preuve finale.

## Vertical slice initial

Le contrat versionné est :

```text
tests/browser/contracts/public-blog.json
```

Il utilise un acteur `anonymous` et la cible `/fr/blog`.

Le scénario :

- ouvre le Blog public Agency ;
- vérifie la réponse HTTP initiale ;
- vérifie le H1 et le `<main>` dans le DOM final ;
- clique réellement `Services`, puis revient au Blog via la navigation ;
- vérifie qu'il n'existe qu'un H1 ;
- vérifie `lang="fr"` ;
- détecte un débordement horizontal évident ;
- collecte les erreurs console et les exceptions non gérées ;
- collecte les réponses same-origin 4xx/5xx et requêtes réseau échouées ;
- prend une capture pleine page desktop et mobile.

Les sélecteurs privilégient les rôles et noms accessibles.

## Résultat machine-readable

Le fichier principal est :

```text
artifacts/browser-validation/result.json
```

Exemple de forme :

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

Les verdicts possibles sont :

- `PASS` : preuve complète réussie ;
- `FAIL` : la validation a été exécutée et au moins un contrôle a échoué ;
- `NOT_RUN` : l'environnement nécessaire n'était pas disponible.

`NOT_RUN` ne doit jamais être interprété comme un succès.

## Preuves et diagnostic

Les artefacts sont générés sous :

```text
artifacts/browser-validation/
```

Sur succès :

- `result.json` ;
- `evidence/desktop.json` ;
- `evidence/mobile.json` ;
- captures `screenshots/public-blog-desktop.png` et
  `screenshots/public-blog-mobile.png`.

Sur échec, Playwright conserve aussi sa sortie de test et une trace lorsque la
création de trace a pu commencer. Les artefacts sont locaux et ignorés par Git.

Pour ouvrir une trace :

```bash
npx playwright show-trace <chemin-vers-trace.zip>
```

Ordre de diagnostic recommandé :

1. lire `result.json` ;
2. lire l'evidence du projet en échec ;
3. ouvrir la capture ;
4. examiner la trace ;
5. identifier console, exception page ou requête fautive ;
6. corriger ;
7. relancer la validation.

## Browser validation contract

Un contrat reste un JSON simple contenant :

- `id` ;
- `target` ;
- `actor` ;
- attentes humaines lisibles ;
- catégories de checks ;
- preuves attendues.

Il ne doit pas devenir un DSL. Le test Playwright porte la logique exécutable.

Pour une future tâche frontend, le ticket ou la PR peut exprimer le contrat ainsi :

```text
BROWSER VALIDATION

Target:
<URL ou parcours>

Actor:
<anonymous | authenticated | role>

Expected:
- comportement attendu

Checks:
- functional
- rendered DOM
- browser console
- unexpected HTTP failures
- desktop rendering
- mobile rendering

Evidence:
- screenshots
- machine-readable result
- trace on failure
```

## Utilisation par un agent IA

Après un changement frontend ou interactif, l'agent doit :

1. identifier les pages réellement touchées ;
2. exécuter les tests techniques ciblés utiles ;
3. exécuter `npm run browser:validate` ou un contrat dédié ;
4. lire `result.json` ;
5. inspecter les captures desktop/mobile ;
6. en cas d'échec, inspecter la trace, le DOM, la console et le réseau ;
7. corriger puis relancer ;
8. joindre le verdict et les preuves pertinentes à la PR.

La règle cible est :

```text
frontend-impacting change
-> browser validation expected
```

Elle reste volontaire dans ce premier incrément. Elle pourra devenir un merge
gate uniquement après stabilisation et preuve CI fiable.

## Authentification et rôles Drupal

Le vertical slice public ne nécessite aucun secret.

Pour les futurs scénarios authentifiés, utiliser les primitives Playwright de
`storageState` afin de créer une session puis la réutiliser dans un projet ou un
contexte dédié.

Les fichiers de session doivent rester sous :

```text
tests/browser/.auth/
```

Ce dossier est ignoré par Git.

Ne jamais :

- committer un mot de passe, cookie ou état authentifié ;
- imprimer les secrets dans les logs ;
- capturer un écran contenant un secret ;
- improviser une nouvelle gestion de secrets.

Les rôles devront être ajoutés seulement lorsqu'un scénario réel le nécessite.

## Playwright MCP

Pour une inspection agent-driven interactive, Playwright MCP peut être lancé dans
un client MCP compatible, par exemple :

```bash
npx @playwright/mcp@latest
```

Usage attendu :

- ouvrir Agency ;
- interagir ;
- demander un snapshot d'accessibilité ;
- inspecter le DOM rendu ;
- diagnostiquer un état avant de formaliser ou corriger un test.

MCP disponible n'implique jamais MCP requis pour CI.

Chrome DevTools MCP n'est pas ajouté dans ce premier incrément : Playwright
couvre déjà la preuve nécessaire. Il pourra être évalué plus tard uniquement si
un besoin de diagnostic DevTools non couvert apparaît.

## Accessibilité

Cette capacité n'est pas encore un audit WCAG.

Le vertical slice utilise déjà des locators sémantiques (`getByRole`) afin que les
interactions restent alignées sur l'arbre d'accessibilité. Une future tâche pourra
ajouter axe ou des assertions ciblées sans refondre la base Playwright.

## CI

La CI GitHub actuelle n'est pas modifiée dans ce premier incrément.

Raison : elle exécute actuellement Drupal via serveur PHP + SQLite pour
`BrowserTestBase`, et ne possède pas encore une installation Agency équivalente au
DDEV réel. Rendre Playwright bloquant contre cet environnement incomplet
donnerait une preuve trompeuse.

Adoption prévue :

```text
preuve DDEV locale
-> usage volontaire sur changements frontend
-> stabilisation
-> environnement CI navigateur fiable
-> upload des artefacts CI
-> éventuel merge gate
```

## Limites actuelles

- un seul contrat public de preuve ;
- Chromium uniquement ;
- aucun état authentifié versionné ;
- pas de visual regression pixel-perfect ;
- pas d'audit WCAG exhaustif ;
- pas encore de job GitHub Actions navigateur.

Ces limites sont intentionnelles : le but initial est de permettre à un agent de
voir et vérifier réellement ce qu'il vient de développer.
