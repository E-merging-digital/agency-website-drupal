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

## 2026-07-17 — Mise à jour système et PHP de production

- Issue / PR : issue #350 créée pour formaliser la procédure et ce journal
- Environnement : production
- Résultat : succès

### Versions

| Composant | Avant | Après | Action |
| --- | --- | --- | --- |
| Ubuntu | 24.04.4 LTS | 24.04.4 LTS | Paquets standards mis à jour |
| Noyau actif | 6.8.0-110-generic | 6.8.0-136-generic | Nouveau noyau installé et VM redémarrée |
| Nginx | 1.24.0 Ubuntu | 1.24.0 Ubuntu | Service validé, version inchangée |
| PHP CLI / PHP-FPM | 8.4.20 | 8.4.23 | Mise à jour corrective de sécurité |
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

- La procédure a prévu un snapshot de VM et une sauvegarde de base avant les
  opérations sensibles.
- Le noyau 6.8.0-134 a été conservé comme solution de repli.
- Aucun retour arrière n'a été nécessaire.

### Validations

- Noyau actif confirmé : `6.8.0-136-generic`.
- PHP CLI confirmé : `8.4.23`.
- Drupal utilise PHP `8.4.23`.
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
