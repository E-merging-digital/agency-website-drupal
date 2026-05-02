# Déploiement manuel en production (GandiCloud)

## 1) Principe général du déploiement

Le déploiement repose sur une structure **à releases timestampées** :

- chaque version est installée dans un dossier dédié sous `/var/www/agency/releases/<timestamp>` ;
- le lien symbolique `/var/www/agency/current` pointe vers la release active ;
- les éléments persistants sont partagés via `/var/www/agency/shared` ;
- un rollback consiste à repointer `current` vers une release précédente.

Cette approche permet des changements atomiques de version et limite les interruptions de service.

## 2) Prérequis

Vérifier avant tout déploiement :

- accès SSH au serveur Ubuntu 24.04 LTS ;
- accès à l’utilisateur de déploiement `deploy` ;
- accès GitHub opérationnel via deploy key (lecture du dépôt) ;
- `composer` (2.9+) disponible côté serveur ;
- base de données MariaDB déjà configurée ;
- fichier partagé `settings.php` déjà présent :
  `/var/www/agency/shared/settings/settings.php`.

## 3) Procédure de déploiement manuel

> Exemple avec la branche `main`. Adapter la variable `BRANCH` selon la version à déployer.

### 3.1 Connexion et préparation

```bash
ssh <user>@<server>
sudo -iu deploy

PROJECT_ROOT=/var/www/agency
RELEASES_DIR="$PROJECT_ROOT/releases"
SHARED_DIR="$PROJECT_ROOT/shared"
REPO_URL="git@github.com:<org>/<repo>.git"
BRANCH="main"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
NEW_RELEASE="$RELEASES_DIR/$TIMESTAMP"

mkdir -p "$RELEASES_DIR" "$SHARED_DIR"
mkdir -p "$SHARED_DIR/files" "$SHARED_DIR/private" "$SHARED_DIR/settings"
```

### 3.2 Création de la release et récupération du code

```bash
git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$NEW_RELEASE"
cd "$NEW_RELEASE"
```

### 3.3 Installation des dépendances

```bash
composer install --no-dev --optimize-autoloader
```

### 3.4 Liens symboliques des éléments partagés

```bash
# Fichiers publics Drupal
ln -sfn "$SHARED_DIR/files" "$NEW_RELEASE/web/sites/default/files"

# settings.php partagé
ln -sfn "$SHARED_DIR/settings/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"
```

### 3.5 Activation de la nouvelle release

```bash
ln -sfn "$NEW_RELEASE" "$PROJECT_ROOT/current"
cd "$PROJECT_ROOT/current"
```

### 3.6 Mises à jour Drupal post-déploiement

```bash
vendor/bin/drush updb -y
vendor/bin/drush cim -y
vendor/bin/drush cr
```

### 3.7 Nginx

En général, le changement de symlink suffit. Recharger Nginx uniquement si nécessaire :

```bash
sudo systemctl reload nginx
```

## 4) Procédure de rollback

### 4.1 Identifier la release précédente

```bash
ls -1dt /var/www/agency/releases/*
```

### 4.2 Repointer `current`

```bash
PREVIOUS_RELEASE="/var/www/agency/releases/<timestamp_precedent>"
ln -sfn "$PREVIOUS_RELEASE" /var/www/agency/current
cd /var/www/agency/current
vendor/bin/drush cr
```

### 4.3 Point d’attention base de données

Le rollback de code est rapide via symlink, mais le rollback de base de données **n’est pas automatique**.
Si des updates Drupal ont modifié le schéma/données, prévoir une stratégie de restauration DB séparée.

## 5) Vérifications après déploiement

Exécuter les contrôles suivants :

```bash
cd /var/www/agency/current
vendor/bin/drush status
vendor/bin/drush config:status
curl -I http://emergingdigital.be
```

Puis compléter par :

- vérification visuelle des pages clés du site ;
- vérification des logs Drupal (watchdog / journal système).

## 6) Commandes de maintenance utiles

### Lister les releases

```bash
ls -1dt /var/www/agency/releases/*
```

### Supprimer les anciennes releases (garder les 3 dernières)

```bash
cd /var/www/agency/releases
ls -1dt */ | tail -n +4 | xargs -r rm -rf
```

### Vider le cache Drupal

```bash
cd /var/www/agency/current
vendor/bin/drush cr
```

### Consulter le watchdog

```bash
cd /var/www/agency/current
vendor/bin/drush watchdog:show
```

## 7) Précautions importantes

- Ne jamais modifier directement une release active.
- Ne jamais committer `settings.php`.
- Ne jamais versionner de secrets (tokens, mots de passe, clés privées).
- Ne jamais supprimer le dossier `/var/www/agency/shared`.
- Ne pas appliquer de permissions globales de type `chmod 664` sur tout le projet : cela casse les exécutables (Composer/Drush).
- Si correction de permissions nécessaire, préférer :

```bash
chmod -R ug+rwX /var/www/agency/current
```

## 8) Perspective CI/CD

Cette procédure manuelle sert de base à l’automatisation future (ex. GitHub Actions) :

- construction d’une release ;
- installation des dépendances ;
- bascule atomique du symlink `current` ;
- exécution des commandes Drupal post-déploiement ;
- vérifications.

Aucun workflow CI/CD n’est créé à ce stade : ce document définit la référence opérationnelle.
