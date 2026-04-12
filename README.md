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
