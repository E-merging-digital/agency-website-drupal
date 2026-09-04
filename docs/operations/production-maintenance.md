# Procédure de maintenance de production

Cette procédure décrit les opérations de maintenance du serveur de production du
site agence Drupal. Elle couvre les mises à jour Ubuntu, PHP, MariaDB et Drupal.

Elle complète le mécanisme de déploiement applicatif existant dans
`scripts/deploy-production.sh`. Elle ne remplace pas ce script et ne doit pas le
modifier sans ticket explicite.

Pour les droits de lecture/traversée nécessaires à Nginx/PHP-FPM sur les releases,
voir également `docs/operations/production-runtime-permissions.md`.

## Principes obligatoires

- Une intervention planifiée doit être rattachée à une issue GitHub.
- Une modification de documentation ou de code suit le workflow : une issue, une
  branche, une Pull Request.
- Ne jamais modifier directement `main`.
- Ne jamais committer de secret, token, mot de passe, adresse IP privée, clé SSH
  ou contenu de `settings.php` de production.
- Ne jamais modifier le `settings.php` de production dans Git.
- Ne pas mélanger mise à jour système et modification fonctionnelle du site dans
  une même intervention, sauf nécessité documentée.
- Ne pas effectuer de migration majeure de MariaDB sans ticket, sauvegarde,
  procédure de migration et test de restauration dédiés.
- Toujours distinguer les commandes réellement exécutées des commandes seulement
  proposées ou simulées.
- Toute intervention terminée doit être consignée dans
  `docs/operations/production-version-log.md` par une PR dédiée ou par la PR du
  ticket concerné.

## Périmètres de maintenance

### Maintenance système

Concerne Ubuntu, le noyau Linux, Nginx, PHP-FPM, les bibliothèques système et les
outils de la VM.

### Maintenance de la base de données

Concerne les mises à jour correctives de la branche MariaDB 11.8 actuellement
utilisée en production. Une migration vers une autre branche majeure, par exemple
de MariaDB 11.8 vers 12.x, est hors de cette procédure courante.

### Maintenance applicative Drupal

Concerne le code versionné, Composer, les mises à jour de base Drupal, les imports
de configuration, Config Split et Governed Content. Elle passe normalement par
une PR mergée puis par `scripts/deploy-production.sh`.

## 1. Préparer l'intervention

Avant toute modification :

1. Créer ou identifier l'issue GitHub de l'intervention.
2. Vérifier que le périmètre et les versions cibles sont explicites.
3. Prévoir une fenêtre de maintenance.
4. Créer un snapshot de la VM chez l'hébergeur.
5. Vérifier l'espace disque disponible :

```bash
hostname
date
df -h
free -h
```

6. Vérifier l'état de la release et des services :

```bash
readlink -f /var/www/agency/current
git -C /var/www/agency/current log -1 --oneline

php -v
sudo mariadb -NBe "SELECT VERSION();"
nginx -v
uname -r

sudo systemctl is-active nginx
sudo systemctl is-active php8.4-fpm
sudo systemctl is-active mariadb
sudo systemctl --failed --no-pager
```

7. Vérifier Drupal :

```bash
sudo -u deploy -H bash -lc '
  cd /var/www/agency/current
  vendor/bin/drush status
  vendor/bin/drush state:get system.maintenance_mode
  vendor/bin/drush config:status
  composer audit
'
```

Une dérive de configuration Drupal ne doit pas être corrigée automatiquement
pendant une maintenance système. Elle doit être analysée séparément.

## 2. Vérifier les mises à jour disponibles

Actualiser les index sans installer de paquet :

```bash
sudo apt-get update
apt list --upgradable
```

Vérifier les versions installées et candidates des composants sensibles :

```bash
apt-cache policy \
  php8.4-cli \
  php8.4-fpm \
  php8.4-common \
  mariadb-server \
  mariadb-client \
  nginx
```

Simuler une mise à jour standard :

```bash
sudo apt-get --simulate upgrade
```

Simuler une mise à jour complète, notamment lorsqu'un nouveau noyau est proposé :

```bash
sudo apt-get --simulate full-upgrade
```

Ne pas poursuivre si la simulation prévoit :

- la suppression inattendue de Nginx, PHP-FPM, MariaDB, SSH ou d'un paquet
  indispensable ;
- un changement majeur de MariaDB ;
- le remplacement de PHP 8.4 par une autre branche ;
- un nombre important de suppressions non expliqué ;
- une source de paquets inconnue ou non approuvée.

## 3. Sauvegarder avant intervention

### Sauvegarde de la base Drupal

```bash
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
BACKUPS="/var/www/agency/shared/backups"

sudo -u deploy -H bash -lc "
  cd /var/www/agency/current
  vendor/bin/drush sql:dump \
    --gzip \
    --result-file='$BACKUPS/pre-maintenance-$TIMESTAMP.sql.gz'
"
```

Vérifier que le fichier existe et n'est pas vide :

```bash
sudo test -s "/var/www/agency/shared/backups/pre-maintenance-$TIMESTAMP.sql.gz"
```

### Sauvegarde des configurations système concernées

Adapter la liste au périmètre réel :

```bash
sudo tar -C / -czf \
  "/var/www/agency/shared/backups/system-config-$TIMESTAMP.tar.gz" \
  etc/php/8.4 \
  etc/nginx \
  etc/mysql
```

Ne jamais ajouter ces archives dans Git.

## 4. Activer le mode maintenance Drupal

```bash
sudo -u deploy -H bash -lc '
  cd /var/www/agency/current
  vendor/bin/drush state:set system.maintenance_mode 1 --input-format=integer
  vendor/bin/drush cr
  vendor/bin/drush state:get system.maintenance_mode
'
```

Le résultat attendu est `1`.

Avant de poursuivre, conserver une seconde session SSH ouverte lorsque cela est
possible.

## 5. Installer les mises à jour

### Mise à jour ciblée de PHP 8.4

Utiliser une mise à jour ciblée lorsque seule la branche PHP 8.4 doit évoluer.
Commencer par identifier les paquets installés :

```bash
dpkg-query -W \
  -f='${binary:Package}\t${Status}\t${Version}\n' \
  'php8.4*' 2>/dev/null
```

Simuler ensuite l'installation ciblée, puis exécuter la même commande sans
`--simulate` lorsque le résultat est correct.

Exemple :

```bash
sudo apt-get --simulate install --only-upgrade \
  php8.4 php8.4-cli php8.4-common php8.4-fpm php8.4-opcache
```

La liste réelle doit inclure toutes les extensions PHP 8.4 installées sur le
serveur.

### Mise à jour Ubuntu complète

Lorsqu'un nouveau noyau ou des dépendances nécessaires sont retenus par
`upgrade`, utiliser :

```bash
sudo apt-get full-upgrade
```

Lire la liste proposée avant de confirmer. Ne pas utiliser `-y` lors d'une
intervention manuelle si la liste n'a pas déjà été validée par simulation.

### MariaDB

La production utilise MariaDB 11.8 depuis la migration #367. Une mise à jour
corrective courante peut être appliquée seulement si la version candidate reste
dans cette branche 11.8 :

```bash
apt-cache policy mariadb-server mariadb-client
```

Vérifier également la version réellement active et le paramètre de paquet serveur
qui a été relevé à 64 MiB lors de #660 :

```bash
sudo mariadb -NBe "SELECT VERSION(); SELECT @@global.max_allowed_packet;"
```

L'état vérifié après #660 est MariaDB `11.8.8-MariaDB-ubu2404` avec
`max_allowed_packet=67108864`. Ne pas ramener ce paramètre à 16 MiB lors d'une
maintenance corrective : le cache de parsing/routing Drupal a déjà dépassé
15 MiB et cette limite a provoqué un échec réel de `drush cr`.

Toute migration hors de la branche 11.8 ou toute modification supplémentaire de
configuration MariaDB doit disposer d'un ticket, d'une sauvegarde, d'un rollback
et de validations dédiés.

## 6. Valider les configurations et redémarrer les services

Après une mise à jour PHP :

```bash
sudo php-fpm8.4 -t
sudo systemctl restart php8.4-fpm
```

Après une mise à jour MariaDB :

```bash
sudo systemctl restart mariadb
```

Toujours valider et recharger Nginx :

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Contrôler les services :

```bash
sudo systemctl is-active nginx
sudo systemctl is-active php8.4-fpm
sudo systemctl is-active mariadb
sudo systemctl --failed --no-pager
```

## 7. Redémarrer la VM lorsque requis

Vérifier :

```bash
if [ -f /var/run/reboot-required ]; then
  echo "Redémarrage requis"
  cat /var/run/reboot-required.pkgs 2>/dev/null || true
else
  echo "Aucun redémarrage requis"
fi
```

Si un nouveau noyau ou un composant système essentiel l'exige :

```bash
sudo reboot
```

Après reconnexion :

```bash
uname -r
php -v
sudo mariadb -NBe "SELECT VERSION();"

sudo systemctl is-active nginx
sudo systemctl is-active php8.4-fpm
sudo systemctl is-active mariadb
sudo systemctl --failed --no-pager
```

Ne jamais supprimer l'ancien noyau avant d'avoir confirmé que la VM redémarre
correctement sur le nouveau.

## 8. Valider Drupal après intervention

```bash
sudo -u deploy -H bash -lc '
  cd /var/www/agency/current
  vendor/bin/drush status
  vendor/bin/drush sql:query "SELECT 1;"
  vendor/bin/drush cr
  composer audit
'
```

Vérifier également :

- la page d'accueil publique ;
- le formulaire de contact ;
- `/admin/reports/status` ;
- les erreurs Nginx et PHP-FPM récentes ;
- l'absence de service en échec.

Si Drupal CLI boote correctement mais que les requêtes HTTP retournent 403/404,
ne pas conclure immédiatement à un défaut de routage Drupal et ne pas redémarrer
les services au hasard. Vérifier le docroot Nginx et les droits de traversée de
`/var/www/agency/current` selon
`docs/operations/production-runtime-permissions.md`.

Exemples :

```bash
sudo journalctl -u php8.4-fpm --since "30 minutes ago" --no-pager
sudo tail -n 100 /var/log/nginx/error.log
```

## 9. Désactiver le mode maintenance

```bash
sudo -u deploy -H bash -lc '
  cd /var/www/agency/current
  vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer
  vendor/bin/drush cr
  vendor/bin/drush state:get system.maintenance_mode
'
```

Le résultat attendu est `0`.

En cas d'échec pendant l'intervention, tenter de désactiver le mode maintenance
avant de quitter, sauf si le site doit volontairement rester indisponible pour
éviter une corruption.

## 10. Nettoyer prudemment

Après validation complète et après redémarrage sur le nouveau noyau :

```bash
sudo apt-get --simulate autoremove --purge
```

Examiner les paquets proposés. Si la liste est correcte :

```bash
sudo apt-get autoremove --purge
```

Conserver au moins un noyau précédent fonctionnel comme solution de secours.

## 11. Déployer une mise à jour applicative Drupal

Les modifications Drupal doivent être validées localement, mergées dans `main`,
puis déployées avec le script existant :

```bash
sudo -u deploy -H bash -lc '
  /var/www/agency/current/scripts/deploy-production.sh main
'
```

Ce script gère notamment la nouvelle release, Composer, les droits runtime avant
activation, la sauvegarde SQL, le mode maintenance, `drush updb`, `drush cim`,
Config Split, Governed Content et le basculement du lien `current`.

Ne pas lancer un `drush cim` global manuellement pour corriger une dérive non
analysée.

## 12. Retour arrière

Le retour arrière dépend du type d'échec.

### Déploiement Drupal

Le script de déploiement conserve les releases précédentes. Utiliser une release
précédente seulement après analyse, en préservant les fichiers partagés et le
`settings.php` partagé.

### Base de données

Restaurer la sauvegarde SQL seulement si cela est nécessaire et après avoir
confirmé la compatibilité avec la release active.

### Mise à jour système

En cas d'échec grave :

1. utiliser la console de l'hébergeur ;
2. démarrer sur le noyau précédent si nécessaire ;
3. restaurer le snapshot de VM si la remise en état manuelle n'est pas fiable.

Une restauration de snapshot peut annuler des données créées après le snapshot.
Cette conséquence doit être prise en compte avant toute restauration.

## 13. Consigner l'intervention

Ajouter une entrée à `docs/operations/production-version-log.md` avec :

- date et fenêtre d'intervention ;
- issue ou PR associée ;
- versions avant et après ;
- commandes ou catégories d'opérations réellement exécutées ;
- sauvegardes créées ;
- redémarrage effectué ou non ;
- validations obtenues ;
- anomalies et décisions de non-action ;
- limites et risques résiduels.

Ne pas consigner de secret, d'adresse privée, de clé, de mot de passe, de contenu
de configuration sensible ou de sortie contenant des données personnelles.
