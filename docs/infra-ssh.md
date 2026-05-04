# Infrastructure SSH : rôles et séparation des clés

Ce document décrit les usages SSH sur le projet afin d’éviter tout mélange de responsabilités entre clés.

## A) Clé serveur → GitHub

- **Usage** : permettre au serveur de production d’exécuter `git clone` / `git pull` sur le dépôt.
- **Localisation GitHub** : `Repository > Settings > Deploy keys`.
- **Localisation serveur** : `/home/deploy/.ssh/`.
- **Nom connu** : `emergingdigital-server`.

## B) Clé GitHub Actions → serveur

- **Usage** : permettre au workflow GitHub Actions de se connecter au serveur en SSH.
- **Localisation GitHub** : `Repository > Settings > Secrets and variables > Actions`.
- **Secret** : `SSH_PRIVATE_KEY`.
- **Côté serveur** : la clé publique correspondante est présente dans `/home/deploy/.ssh/authorized_keys`.

## C) Clés personnelles

- **Usage** : accès SSH manuel des développeurs/administrateurs.
- **Localisation serveur** : présentes dans `authorized_keys` des utilisateurs concernés.

## Règles de sécurité

- Ne jamais committer une clé privée.
- Une clé SSH = un usage unique.
- Ne pas réutiliser la deploy key serveur pour GitHub Actions.
- En cas de compromission, révoquer/supprimer uniquement la clé concernée.
