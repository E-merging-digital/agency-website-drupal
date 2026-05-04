# Déploiement manuel en production (GandiCloud)

## Références complémentaires

- Configuration HTTPS (Let's Encrypt / Certbot, politique canonique) : `docs/https.md`
- Configuration Analytics GA4 (Google Tag + Config Split) : `docs/analytics.md`

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

> Exemple avec la branche `main`. Adapter `BRANCH` selon la version à déployer.
> **Important :** copier-coller le bloc de variables complet à chaque déploiement pour éviter de réutiliser d’anciennes valeurs de shell.

### 3.1 Connexion et préparation

```bash
ssh ubuntu@emergingdigital
sudo -iu deploy

unset PROJECT_ROOT RELEASES_DIR SHARED_DIR REPO_URL BRANCH TIMESTAMP NEW_RELEASE

PROJECT_ROOT=/var/www/agency
RELEASES_DIR="$PROJECT_ROOT/releases"
SHARED_DIR="$PROJECT_ROOT/shared"
REPO_URL="git@github.com:E-merging-digital/agency-website-drupal.git"
BRANCH="main"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
NEW_RELEASE="$RELEASES_DIR/$TIMESTAMP"

mkdir -p "$RELEASES_DIR" "$SHARED_DIR"
mkdir -p "$SHARED_DIR/files" "$SHARED_DIR/private" "$SHARED_DIR/settings"
```

### 3.2 Vérifications avant clone (important)

```bash
whoami
printf 'REPO_URL=%s\nBRANCH=%s\nNEW_RELEASE=%s\n' "$REPO_URL" "$BRANCH" "$NEW_RELEASE"
ssh -T git@github.com || true
git ls-remote "$REPO_URL" -h "refs/heads/$BRANCH"
```

Attendus :

- `whoami` retourne `deploy` ;
- `REPO_URL` n’est **pas vide** et ne contient pas `<org>/<repo>` ;
- le test SSH GitHub répond (même avec un message sans shell interactif) ;
- `git ls-remote` retourne un hash (sinon, accès dépôt incorrect).

### 3.3 Création de la release et récupération du code

```bash
git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$NEW_RELEASE"
cd "$NEW_RELEASE"
```

### 3.4 Installation des dépendances

```bash
composer install --no-dev --optimize-autoloader
```

### 3.5 Liens symboliques des éléments partagés

```bash
# Fichiers publics Drupal
rm -rf "$NEW_RELEASE/web/sites/default/files"
ln -sfn "$SHARED_DIR/files" "$NEW_RELEASE/web/sites/default/files"

# settings.php partagé
rm -f "$NEW_RELEASE/web/sites/default/settings.php"
ln -sfn "$SHARED_DIR/settings/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"
```

### 3.6 Activation de la nouvelle release

```bash
ln -sfn "$NEW_RELEASE" "$PROJECT_ROOT/current"
cd "$PROJECT_ROOT/current"
```

### 3.7 Mises à jour Drupal post-déploiement

```bash
vendor/bin/drush updb -y
vendor/bin/drush cim -y
vendor/bin/drush cr
```

### 3.8 Nginx

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

## 7) Dépannage rapide

### Erreur `fatal: repository '' does not exist`

Cette erreur signifie que `REPO_URL` est vide, mal défini, resté avec une valeur placeholder (`<org>/<repo>`), ou que la deploy key n'a pas accès au dépôt.
Recharger explicitement les variables puis retenter le clone :

```bash
REPO_URL="git@github.com:E-merging-digital/agency-website-drupal.git"
BRANCH="main"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
NEW_RELEASE="/var/www/agency/releases/$TIMESTAMP"

printf 'REPO_URL=%s\n' "$REPO_URL"
[[ "$REPO_URL" == *"<org>/<repo>"* ]] && echo "REPO_URL invalide" && return 1
git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$NEW_RELEASE"
```

Si l’erreur persiste, vérifier que la deploy key GitHub est bien attachée au dépôt `E-merging-digital/agency-website-drupal` avec accès en lecture.

## 8) Précautions importantes

- Ne jamais modifier directement une release active.
- Ne jamais committer `settings.php`.
- Ne jamais versionner de secrets (tokens, mots de passe, clés privées).
- Ne jamais supprimer le dossier `/var/www/agency/shared`.
- Ne pas appliquer de permissions globales de type `chmod 664` sur tout le projet : cela casse les exécutables (Composer/Drush).
- Si correction de permissions nécessaire, préférer :

```bash
chmod -R ug+rwX /var/www/agency/current
```

## 9) Perspective CI/CD

Cette procédure manuelle sert de base à l’automatisation future (ex. GitHub Actions) :

- construction d’une release ;
- installation des dépendances ;
- bascule atomique du symlink `current` ;
- exécution des commandes Drupal post-déploiement ;
- vérifications.

Aucun workflow CI/CD n’est créé à ce stade : ce document définit la référence opérationnelle.

## 10) Procédure automatique (script)

Un script de déploiement est disponible : `scripts/deploy-production.sh`.

### Usage standard

```bash
ssh ubuntu@emergingdigital
sudo -iu deploy
cd /var/www/agency/current

bash scripts/deploy-production.sh main
```

### Variables supportées

```bash
PROJECT_ROOT=/var/www/agency
RELEASES_DIR=/var/www/agency/releases
SHARED_DIR=/var/www/agency/shared
REPO_URL=git@github.com:E-merging-digital/agency-website-drupal.git
BRANCH=main
KEEP_RELEASES=3
RUN_NGINX_RELOAD=0
```

Exemple avec reload Nginx :

```bash
REPO_URL=git@github.com:E-merging-digital/agency-website-drupal.git bash scripts/deploy-production.sh main
```

Le script applique la même logique que la procédure manuelle : validation GitHub, clone timestampé, `composer install`, symlinks partagés, bascule `current`, `drush updb`, `drush cim`, `drush cr`, puis nettoyage des anciennes releases.

## 11) Déploiement automatisé

Le script `scripts/deploy-production.sh` automatise le déploiement en production avec gestion robuste des erreurs.

### Commande

```bash
bash scripts/deploy-production.sh main
```

### Ce que fait le script

- crée une nouvelle release timestampée dans `/var/www/agency/releases` ;
- exécute un backup de base de données (si une release `current` existe) dans `/var/www/agency/shared/backups` ;
- active le mode maintenance Drupal juste avant la bascule de release ;
- bascule `current` vers la nouvelle release puis exécute `drush updb`, `drush cim`, `drush cr` ;
- désactive le mode maintenance après succès ;
- écrit un journal persistant dans `/var/www/agency/shared/deployments.log` (statuts `START`, `SUCCESS`, `FAILURE`) ;
- conserve les 3 dernières releases sans supprimer celle pointée par `current` ;
- conserve les 10 derniers backups DB ;
- en cas d’échec, trace l’erreur, tente de désactiver la maintenance, et laisse la release précédente intacte.

### Rollback

Le rollback reste basé sur le repointage du symlink `current` vers une release précédente (voir section 4). Le script ne supprime pas la release active précédente en cas d’erreur, ce qui permet un retour arrière rapide.

## 10) Référence SSH

Pour la séparation des clés SSH (serveur, GitHub Actions, accès personnels), voir `docs/infra-ssh.md`.
