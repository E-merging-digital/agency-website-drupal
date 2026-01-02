# AGENTS.md

Ce fichier définit les règles **strictes** de travail pour les agents automatisés
(Codex, IA) et pour tout contributeur humain sur ce dépôt.

Il constitue un **contrat de contribution**.

---

## 1. Principes fondamentaux

- **1 ticket GitHub = 1 branche Git = 1 Pull Request**
- Toute branche doit être créée depuis `main`
- Aucune modification directe sur `main` n’est autorisée
- Chaque Pull Request doit référencer explicitement son ticket (`Closes #X`)
- Aucun changement hors du périmètre du ticket n’est autorisé

---

## 2. Workflow Git obligatoire

1. Se placer sur `main` et s’assurer qu’il est à jour
2. Créer une branche dédiée :
   feature/<slug-du-ticket>
3. Implémenter **uniquement** le contenu du ticket
4. Commits clairs et cohérents avec le ticket
5. Ouvrir une Pull Request vers `main`
6. Vérifier que le CI passe avant fusion

---

## 3. Contexte technique du projet

- CMS : **Drupal 11** (`drupal/recommended-project`)
- PHP : **8.3**
- Base de données : MariaDB
- Docroot : `web/`
- Environnement local : **ddev**

---

## 4. Règles de qualité et CI

- Les scripts **Composer** sont la source de vérité pour le CI
- Les contrôles qualité **ne doivent jamais** analyser :
- `web/core`
- `web/modules/contrib`
- `web/themes/contrib`
- Les contrôles qualité ciblent uniquement :
- `web/modules/custom`
- `web/themes/custom`
- `web/profiles/custom`

---

## 5. Portée des changements

- Ne pas modifier de fichiers non listés dans le ticket
- Ne pas reformater ou refactoriser du code existant hors nécessité explicite
- En cas de doute sur le périmètre → **demander confirmation**

---

## 6. Sécurité et configuration

- Aucun secret ne doit être commité
- Les fichiers locaux ou sensibles (`settings.local.php`, clés, tokens) sont exclus du dépôt
- Les chemins de fichiers (public/private) doivent être configurables par environnement

---

## 7. Règles spécifiques pour Codex

Les agents automatisés doivent :

- Respecter strictement ce fichier
- Ne jamais proposer de commit sur `main`
- Toujours travailler dans une branche `feature/*`
- S’arrêter dès que le périmètre du ticket est rempli

Toute violation de ces règles invalide la contribution.
