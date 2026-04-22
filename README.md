# Agency Website (Drupal 11)

Site d’agence basé sur **Drupal 11** avec un environnement local reproductible via **ddev**.

## Prérequis

- Git
- Docker Desktop
- ddev
- Accès au repository GitHub

## Stack

- Drupal : 11.x
- PHP : 8.3 (via ddev)
- Base de données : MariaDB (via ddev)
- Docroot : `web/`

## Installation locale

### 1. Cloner le dépôt

```bash
git clone <URL_DU_REPO>
cd agency-website-drupal
```

### 2. Démarrer l’environnement

```bash
ddev start
ddev composer install
```

### 3. Installer Drupal (profil standard)

```bash
ddev drush site:install standard \
  --account-name=admin --account-pass=admin \
  --site-name="Agency Website" -y
```

### 4. Ouvrir le site

```bash
ddev launch
```

## Conventions multi-environnements

### Rôle de `web/sites/default/settings.php`

- Fichier **versionné** qui définit le socle commun à tous les environnements.
- Charge en premier `default.settings.php` (base Drupal).
- Définit les chemins partagés du projet :
  - `config/sync` pour la configuration exportée
  - `private/` (hors docroot) pour les fichiers privés
- Contrôle l’ordre d’inclusion des surcharges d’environnement.

### Rôle de `web/sites/default/settings.ddev.php`

- Fichier géré par **DDEV**.
- Spécifique à l’environnement local conteneurisé.
- Peut contenir des paramètres de connexion BDD et d’intégration DDEV.
- **Ne pas modifier manuellement** dans le cadre des conventions projet.

### Rôle de `web/sites/default/settings.local.php`

- Fichier local développeur (**non versionné**).
- Sert aux surcharges machine individuelles (debug, paramètres locaux, etc.).
- Chargé après `settings.ddev.php` pour permettre des overrides explicites en local.

### Ordre d’inclusion recommandé

Dans `settings.php`, l’ordre est :

1. `default.settings.php`
2. paramètres partagés (`config_sync_directory`, `file_private_path`)
3. `settings.ddev.php` (si présent)
4. `settings.local.php` (si présent)

Cet ordre garantit un socle stable et des surcharges explicites.

## Configuration Drupal (`config/sync`)

- `config/sync/` est la **source de vérité** de la configuration exportée.
- Toute modification de configuration doit être synchronisée via Drush.
- Le dossier est versionné pour partager un état cohérent entre environnements.

### Commandes Drush de référence

Exporter la configuration locale vers `config/sync` :

```bash
ddev drush cex -y
```

Importer la configuration du dépôt vers la base locale :

```bash
ddev drush cim -y
```

## Gestion des fichiers privés (`private/`)

- `private/` est hors docroot (`web/`) et n’est donc pas exposé publiquement.
- Le chemin est défini dans `settings.php` via :
  - `$settings['file_private_path'] = '../private';`
- Le contenu runtime de `private/` est ignoré par Git (sauf éventuel `.gitkeep`).

## Conventions Git / branches / Pull Requests

- 1 ticket GitHub = 1 branche = 1 Pull Request.
- Branche de travail dédiée : `feature/<slug-du-ticket>`.
- Cible de PR : `main`.
- La PR doit référencer le ticket avec : `Closes #X`.
- Aucun changement hors périmètre du ticket.

## Rôle de `AGENTS.md`

`AGENTS.md` est le contrat de contribution du dépôt :

- règles Git (branches, PR, périmètre),
- attentes qualité/CI,
- règles sécurité/configuration,
- contraintes spécifiques pour agents automatisés et contributeurs humains.

Toute contribution doit respecter ces règles avant ouverture de PR.


## Filet de sécurité runtime (Ticket #4A)

Un test fonctionnel Drupal `BrowserTestBase` minimal vérifie le rendu de la page d’accueil (`<front>`) et doit rester vert avant de poursuivre les travaux front.

- Classe de test : `web/modules/custom/homepage_smoke_test/tests/src/Functional/HomepageRenderTest.php`
- Commande dédiée : `composer test:homepage-smoke`
- Intégration CI : incluse dans `composer ci`

Exécution locale :

```bash
ddev composer test:homepage-smoke
```

## Commandes utiles

### Vérifier l’état du projet

```bash
ddev describe
ddev drush status
```

### Vider le cache

```bash
ddev drush cr
```

### Accéder au shell du container

```bash
ddev ssh
```

### Réinitialiser l’environnement

⚠️ Cette opération supprime la base de données locale.

```bash
ddev stop --remove-data --omit-snapshot
ddev start
ddev composer install
```

### Puis réinstaller Drupal

```bash
ddev drush site:install standard \
  --account-name=admin --account-pass=admin \
  --site-name="Agency Website" -y
```

## Notes

- Le projet utilise la structure `drupal/recommended-project`.
- Les fichiers sensibles (`settings.local.php`, etc.) ne doivent pas être versionnés.
- La configuration DDEV (`.ddev/`) est versionnée pour garantir la reproductibilité.

## Thèmes (front + administration)

### Thème front custom

- Thème front : `emerging_digital` (custom, basé sur Starterkit Drupal core).
- Emplacement : `web/themes/custom/emerging_digital`.
- Objectif : fournir un socle front sobre et maintenable (tokens CSS, layout, boutons, templates Twig de base).

### UX administration

- Thème d’administration par défaut (config sync) : `claro` (stable).
- Option admin UX (installation manuelle) : `gin` + `gin_toolbar`.
- Objectif : garder une base stable en sync et activer Gin uniquement quand les dépendances sont réellement installées.


### Validation Composer (environnement restreint)

Si l'accès réseau à `packages.drupal.org` est limité (proxy/CI sandbox), la validation suivante permet de vérifier `composer.json` sans faire échouer le contrôle sur un `composer.lock` non rafraîchi :

```bash
composer validate --no-check-publish --no-check-lock
```

Dès qu'un environnement avec accès réseau complet est disponible, régénérez le lock proprement :

```bash
composer update drupal/gin drupal/gin_toolbar -W
```

### Commandes utiles (thèmes)

```bash
# Installer les dépendances thème admin
ddev composer require drupal/gin drupal/gin_toolbar

# Optionnel : activer Gin Toolbar et positionner Gin en admin theme
ddev drush en gin_toolbar -y
ddev drush cset system.theme admin gin -y

# Activer le thème front custom
ddev drush theme:enable emerging_digital -y
ddev drush config:set system.theme default emerging_digital -y

# Synchronisation config
ddev drush cex -y
ddev drush cim -y
```

## Ticket #5 — Contenus stratégiques et design de base

Le thème `emerging_digital` intègre désormais une base visuelle orientée conversion pour les pages stratégiques et la home :

- Hero orienté proposition de valeur Drupal + IA
- Blocs réutilisables pour services, fonctionnalités IA, bénéfices clients et CTA
- Grilles de cartes pour faciliter la lisibilité des offres
- Renforcement de la hiérarchie visuelle (titres, intertitres, contrastes, espacements)

Fichiers principalement concernés :

- `web/themes/custom/emerging_digital/templates/paragraphs/`
- `web/themes/custom/emerging_digital/templates/layout/`
- `web/themes/custom/emerging_digital/css/`
- `web/themes/custom/emerging_digital/js/main.js`


## Ticket #6 — Fondation IA Drupal (AI + OpenAI Provider)

### Modules à installer (Composer)

Le socle IA doit être installé avec des contraintes stables compatibles Drupal 11 :

- `drupal/ai:^1.2.12` (**version corrigée après l’avis de sécurité de mars 2026**)
- `drupal/ai_provider_openai:^1.2.1`
- `drupal/key:^1.20`

> Important sécurité : ne jamais utiliser une version `1.1.x` ou `1.2.x` vulnérable du module AI. Toujours installer une version corrigée et relancer un audit sécurité Composer.

### Procédure locale (non versionnée) pour la clé API OpenAI

1. Installer les dépendances en local (environnement avec accès réseau) :

```bash
composer install
composer require drupal/ai:^1.2.12 drupal/ai_provider_openai:^1.2.1 drupal/key:^1.20 -W
```

2. Activer les modules :

```bash
drush en ai ai_provider_openai key -y
```

3. Créer la clé OpenAI dans Drupal via **Key** :
   - Admin → Configuration → System → Keys
   - Ajouter une clé `openai_api_key`
   - Stocker la valeur en local uniquement (jamais dans Git)

4. Lier la clé au provider :
   - Admin → Configuration → AI → Providers → OpenAI
   - Sélectionner la clé `openai_api_key`
   - Sauvegarder et tester le provider

### Méthode recommandée pour secret local

- Utiliser un `settings.local.php` **non versionné** (déjà ignoré par Git).
- Définir la clé via variable d’environnement locale (DDEV / shell local), puis renseigner le module Key uniquement en local.
- Ne jamais exporter une configuration contenant une clé en clair.

Exemple d’injection locale (machine développeur) :

```bash
# Exemple local uniquement (ne pas commiter)
export OPENAI_API_KEY="sk-..."
```

### Cas d’usage IA activés immédiatement (admin)

Cette première phase cible des usages démontrables pour éditeurs Drupal (PME / ASBL) :

1. **Assistance rédactionnelle / reformulation**
2. **Correction éditoriale** (style, grammaire, clarté)
3. **Amélioration / suggestions SEO** (titres, méta, structure contenu)

### Cas d’usage documentés pour une phase suivante

- Traduction automatique
- Tags/alt text automatiques pour images
- Résumé de contenu
- Assistants avancés / workspaces

### Synchronisation de configuration

Après activation et paramétrage en local :

```bash
drush cex -y
drush cim -y
```

Vérifier que l’export ne contient aucun secret avant commit.

## Ticket #10 — Formulaire de contact (Webform) : gestion manuelle

Pour garder une implémentation simple et maintenable, le formulaire de contact **n'est pas créé automatiquement** par post-update.

### Pré-requis

- Module `webform` installé via Composer.
- Module `webform` activé dans Drupal.

### Créer le formulaire dans l’admin

1. Aller dans **Structure → Webforms → Add webform**.
2. Créer un formulaire `contact` (ou équivalent) avec au minimum :
   - `Nom` (textfield, requis)
   - `E-mail` (email, requis)
   - `Message` (textarea, requis)
3. Enregistrer et tester une soumission.

### Intégrer le formulaire sur la page Contact

Option recommandée (sans automatisation implicite) :

1. Aller dans **Structure → Block layout**.
2. Placer le block Webform dans une région adaptée du thème `emerging_digital`.
3. Limiter l’affichage du block au chemin `/contact` via les conditions de visibilité.
4. Vider le cache (`ddev drush cr`) et vérifier le rendu.

Cette approche évite les effets de bord des post-updates complexes et laisse la main à l’équipe éditoriale.

## Ticket #93 — Réactivation OpenAI via variable d’environnement

Objectif : réactiver l’intégration OpenAI sans exposer de secret dans la configuration exportée.

### Ce qui est versionné

- `config/sync/key.key.openai_api_key.yml`
  - Key Drupal `openai_api_key`
  - Provider : `env`
  - Variable attendue : `OPENAI_API_KEY`
- `config/sync/ai_provider_openai.settings.yml`
  - Configuration minimale conforme au schéma (`api_key` uniquement)
  - Provider OpenAI branché sur la Key `openai_api_key`

### Ce qui **n’est pas** versionné

- La valeur de la clé OpenAI (`sk-...`) n’apparaît jamais dans Git.
- La clé reste fournie uniquement par l’environnement DDEV.

### Vérification locale

```bash
ddev drush cr
ddev drush cex -y
git diff config/sync
ddev drush cim -y
```

Contrôle attendu : aucun secret en clair dans `config/sync` et provider OpenAI utilisable depuis l’UI Drupal.
