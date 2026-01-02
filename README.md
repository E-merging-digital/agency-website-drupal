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
```bash

### 2. Démarrer l’environnement :

```bash
ddev start
ddev composer install
```bash

### 3. Installer Drupal (profil standard) :

```bash
ddev drush site:install standard \
  --account-name=admin --account-pass=admin \
  --site-name="Agency Website" -y
```bash

### 4. Ouvrir le site :

```bash
ddev launch
```bash

## Commandes utiles

### Vérifier l’état du projet :

```bash
ddev describe
ddev drush status
```bash

### Vider le cache :

```bash
ddev drush cr
```bash

### Accéder au shell du container :

```bash
ddev ssh
```bash

### Réinitialiser l’environnement

⚠️ Cette opération supprime la base de données locale.

```bash
ddev stop --remove-data --omit-snapshot
ddev start
ddev composer install
```bash

### Puis réinstaller Drupal :

```bash
ddev drush site:install standard \
  --account-name=admin --account-pass=admin \
  --site-name="Agency Website" -y
```bash

## Notes

    Le projet utilise la structure drupal/recommended-project.

    Les fichiers sensibles (settings.local.php, etc.) ne doivent pas être versionnés.

    La configuration ddev (.ddev/) est versionnée pour garantir la reproductibilité.