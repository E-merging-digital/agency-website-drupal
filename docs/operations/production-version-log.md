# Journal des versions de production

Ce document consigne les versions et interventions réellement appliquées sur la
production du site agence Drupal.

Il est volontairement distinct des Pull Requests applicatives : une PR décrit ce
qui doit être déployé, tandis que ce journal décrit ce qui a effectivement été
exécuté et vérifié sur le serveur.

## Règles de tenue du journal

- Ajouter les nouvelles interventions en tête du document.
- Ne consigner que des versions et opérations réellement vérifiées.
- Indiquer explicitement les opérations simulées mais non exécutées.
- Associer chaque entrée à une issue ou une Pull Request lorsque cela existe.
- Ne jamais consigner de secret, clé, mot de passe, adresse IP privée, contenu de
  `settings.php` ou sortie contenant des données personnelles.
- Ne pas réécrire l'historique d'une intervention passée. Ajouter une correction
  datée lorsqu'une information doit être rectifiée.
- Une mise à jour majeure de PHP, MariaDB, Ubuntu ou Drupal doit disposer de son
  propre ticket.

## Modèle d'entrée

```markdown
## AAAA-MM-JJ — Titre de l'intervention

- Issue / PR : #...
- Opérateur : ...
- Environnement : production
- Fenêtre : ...
- Résultat : succès / échec / partiel

### Versions

| Composant | Avant | Après | Action |
| --- | --- | --- | --- |
| Ubuntu | ... | ... | ... |
| Noyau | ... | ... | ... |
| Nginx | ... | ... | ... |
| PHP | ... | ... | ... |
| MariaDB | ... | ... | ... |
| Drupal | ... | ... | ... |

### Opérations réellement exécutées

- ...

### Sauvegardes et retour arrière

- ...

### Validations

- ...

### Anomalies, non-actions et risques résiduels

- ...
```

## 2026-08-14 — Migration MariaDB de production vers 11.8

- Issue / PR : #367 / #371
- Opérateur : workflow GitHub Actions temporaire piloté via le compte de déploiement
- Environnement : production
- Résultat : succès
- Preuve principale : run GitHub Actions `31819641406`
- Postchecks : runs `31819857255` et `31819928915`

### Versions

| Composant | Avant | Après | Action |
| --- | --- | --- | --- |
| Ubuntu | 24.04.4 LTS | 24.04.4 LTS | Version inchangée |
| Noyau actif | 6.8.0-137-generic | 6.8.0-137-generic | Version inchangée |
| MariaDB | 10.11.14-MariaDB-0ubuntu0.24.04.1 | 11.8.8-MariaDB-ubu2404 | Migration majeure |
| Drupal | 11.4.5 | 11.4.5 | Bootstrap et connexion DB validés, version inchangée |

### Opérations réellement exécutées

- Validation préalable sur DDEV en MariaDB 11.8 dans #366.
- Vérification de la version source, de `innodb_fast_shutdown`, du service MariaDB
  et du bootstrap Drupal.
- Création et vérification d'un dump SQL compressé.
- Installation de `mariadb-backup` 10.11, création puis préparation d'une
  sauvegarde physique cohérente de la base source.
- Sauvegarde de `/etc/mysql`, de l'état APT et du manifeste des paquets MariaDB.
- Simulation préalable de l'installation MariaDB 11.8.8 : 13 paquets mis à jour,
  3 paquets ajoutés, aucune suppression de paquet critique.
- Ajout du dépôt officiel MariaDB 11.8 pour Ubuntu Noble.
- Activation temporaire du mode maintenance Drupal.
- Arrêt propre de MariaDB 10.11.
- Mise à jour de `mariadb-server`, `mariadb-client`, `mariadb-backup`, des
  bibliothèques et providers associés vers 11.8.8.
- Exécution de `mariadb-upgrade --force` puis de
  `mariadb-check --all-databases --check-upgrade`.
- Redémarrage de MariaDB et reconstruction du cache Drupal.
- Désactivation du mode maintenance Drupal après validation.
- Marquage explicite comme paquets manuels des providers de compression MariaDB
  `bzip2`, `lz4`, `lzma`, `lzo` et `snappy` afin d'empêcher leur suppression par
  un futur `apt autoremove`.
- Suppression du sudoers temporaire utilisé pour l'intervention.

### Sauvegardes et retour arrière

- Racine des sauvegardes de l'intervention :
  `/var/www/agency/shared/backups/issue367-mariadb-20260814162501`.
- Dump SQL vérifié par `gzip -t`.
- Sauvegarde physique `mariadb-backup` 10.11 préparée et vérifiée avant la
  migration.
- Copie de `/etc/mysql`, état APT et inventaire des paquets conservés avec le même
  préfixe de sauvegarde.
- Snapshot de VM préparé avant l'intervention.
- Aucun retour arrière n'a été nécessaire.

### Validations

- MariaDB finale : `11.8.8-MariaDB-ubu2404`.
- Paquet installé et candidat APT : `1:11.8.8+maria~ubu2404`.
- Dépôt MariaDB 11.8 confirmé actif après l'intervention.
- `mariadb-upgrade` : succès.
- `mariadb-check --all-databases --check-upgrade` : succès sur les tables système
  et applicatives.
- Nombre de tables applicatives avant/après : `140`.
- Service MariaDB : actif.
- Aucun service systemd en échec au contrôle post-migration.
- Aucun message MariaDB de niveau erreur dans le journal de la fenêtre de
  migration.
- Bootstrap Drupal : `Successful` ; base de données : `Connected`.
- Mode maintenance Drupal : `0` après intervention.
- Homepage : HTTP 200.
- Page contact : HTTP 200.
- Postcheck indépendant sans sudo : succès.
- Sudoers temporaire : absent au postcheck indépendant.
- Simulation finale `apt-get -s autoremove` : aucune suppression proposée ; aucun
  composant MariaDB n'est exposé à `autoremove`.

### Anomalies, non-actions et risques résiduels

- Plusieurs itérations du workflow temporaire de migration se sont arrêtées avant
  mutation à cause de défauts de script (suffixe `.gz` puis SIGPIPE). Ces runs
  n'ont pas modifié la version MariaDB ; les sauvegardes et l'état source ont été
  revalidés avant la migration réelle.
- L'installation 11.8 a remplacé certains fichiers de configuration fournis par le
  paquet, notamment `50-server.cnf` et `60-galera.cnf`. Les contrôles de service,
  intégrité et application sont restés GREEN après redémarrage.
- Les providers de compression MariaDB étaient initialement marqués comme
  auto-removables après la migration ; ils ont été marqués manuels puis un
  `apt-get -s autoremove` a confirmé qu'aucune suppression n'était encore proposée.
- Ubuntu signale encore des mises à jour standards disponibles et des correctifs
  ESM Apps non activés ; ils sont hors périmètre de #367.
- Aucun code applicatif, contenu, menu, tracking, secret de production ni workflow
  permanent de déploiement n'a été modifié par la migration.

## 2026-07-17 — Mise à jour système et PHP de production

- Issue / PR : issue #350 créée après l'intervention pour formaliser la procédure
  et ce journal
- Environnement : production
- Résultat : succès

### Versions

| Composant | Avant | Après | Action |
| --- | --- | --- | --- |
| Ubuntu | 24.04.4 LTS | 24.04.4 LTS | Paquets standards mis à jour |
| Noyau actif | 6.8.0-110-generic | 6.8.0-136-generic | Nouveau noyau installé et VM redémarrée |
| Nginx | 1.24.0 Ubuntu | 1.24.0 Ubuntu | Service validé, version inchangée |
| PHP | 8.4.20 | 8.4.23 | Mise à jour corrective de la branche 8.4 |
| MariaDB | 10.11.14 Ubuntu | 10.11.14 Ubuntu | Aucune mise à jour disponible dans les dépôts configurés |
| Drupal | 11.4.4 | 11.4.4 | Bootstrap et cache validés, version inchangée |
| Drush | 13.7.4 | 13.7.4 | Version inchangée |

### Opérations réellement exécutées

- Actualisation des index APT.
- Vérification des versions installées et candidates de PHP et MariaDB.
- Simulation de `apt-get upgrade` et de `apt-get full-upgrade`.
- Mise à jour complète des paquets Ubuntu proposés.
- Mise à jour de PHP 8.4.20 vers PHP 8.4.23 et de ses extensions installées.
- Installation du noyau Linux 6.8.0-136.
- Redémarrage de la VM.
- Suppression par `autoremove --purge` de l'ancien noyau 6.8.0-110 et de ses
  paquets associés.
- Conservation du noyau 6.8.0-134 comme noyau de secours.
- Reconstruction du cache Drupal.

### Sauvegardes et retour arrière

- La création préalable d'un snapshot de VM et d'une sauvegarde SQL avait été
  recommandée, mais leur exécution n'est pas confirmée dans les sorties conservées
  pour cette intervention.
- Le noyau 6.8.0-134 a été conservé comme solution de repli vérifiable.
- Aucun retour arrière n'a été nécessaire.

### Validations

- Noyau actif confirmé : `6.8.0-136-generic`.
- PHP CLI confirmé : `8.4.23`.
- Drupal utilise PHP `8.4.23` pour Drush.
- MariaDB confirmée : `10.11.14-MariaDB-0ubuntu0.24.04.1`.
- Nginx, PHP-FPM et MariaDB actifs.
- Aucun service systemd en échec.
- Bootstrap Drupal réussi et base de données connectée.
- Mode maintenance Drupal confirmé à `0`.
- Cache Drupal reconstruit avec succès.
- `composer audit` : aucune vulnérabilité connue.
- Aucun paquet standard encore disponible après intervention.
- Aucun redémarrage supplémentaire requis.
- Aucun paquet supplémentaire proposé par `autoremove --purge` après nettoyage.

### Anomalies, non-actions et risques résiduels

- Ubuntu Pro / ESM Apps n'est pas activé. Le serveur annonce des correctifs ESM
  Apps supplémentaires, distincts des mises à jour standards disponibles.
- MariaDB est restée sur la version 10.11.14 fournie par Ubuntu. Aucun dépôt
  MariaDB externe n'a été ajouté et aucune migration majeure n'a été réalisée.
- La configuration Drupal présentait les différences suivantes :
  `google_tag.container...` uniquement en base, `google_tag.settings` uniquement
  en base et `system.mail` différent. Aucun `drush cim` global n'a été lancé pour
  corriger cette dérive non analysée.
- Les bibliothèques externes Webform sont restées hors périmètre.
- Aucun fichier du dépôt, menu, contenu de homepage, tracking, secret,
  configuration OpenAI, chatbot IA ou script de déploiement n'a été modifié par
  l'intervention système.
