# Project Brief

## Identité

- Projet : site public de l’agence E-merging Digital.
- Dépôt : `E-merging-digital/agency-website-drupal`.
- Produit : site Drupal bilingue FR/EN présentant l’agence, ses services, ses cas clients et ses expertises Drupal et IA.
- Statut : projet existant en production, maintenu par évolutions bornées et déploiements versionnés.

## Stack

- Drupal 11.
- PHP 8.4 dans DDEV et en production ; la plateforme Composer reste définie séparément dans `composer.json`.
- MariaDB 10.11.
- DDEV pour l’environnement local.
- Nginx, PHP-FPM et Ubuntu 24.04 LTS en production.
- Composer, Drush, GitHub Issues, Pull Requests et GitHub Actions pour le workflow de développement.

## Architecture utile

- Docroot : `web/`.
- Configuration Drupal : `config/sync`.
- Modules custom : `web/modules/custom/`.
- Thème custom : `web/themes/custom/emerging_digital/`.
- Contenu éditorial versionné : catalogue Content Sync du module `emerging_digital_content`.
- Documentation durable : `docs/`.
- Scripts d’exploitation : `scripts/`.
- Procédure et journal de production : `docs/operations/`.

## Sources d’autorité

L’ordre de priorité est le suivant :

1. `AGENTS.md` pour les règles obligatoires de contribution et les interdictions.
2. Les décisions acceptées dans `docs/decisions/` lorsqu’elles existent.
3. L’issue GitHub active pour le périmètre et la définition de terminé.
4. Le code, la configuration et les tests versionnés du dépôt.
5. L’état Git et GitHub réel : branche, commits, PR, checks et commentaires.
6. `PROJECT_BRIEF.md` et `docs/project-context/` pour le contexte compact et les procédures.
7. Les conversations d’assistants, uniquement comme contexte temporaire non canonique.

En cas de contradiction, une source moins prioritaire ne doit jamais écraser silencieusement une source plus prioritaire.

## Workflow obligatoire

- Une issue GitHub = une branche = une Pull Request.
- Toujours partir de `main` à jour.
- Utiliser une branche `feature/<slug-du-ticket>`.
- Ne jamais modifier directement `main`.
- Limiter strictement les changements au périmètre de l’issue.
- La PR cible `main` et contient `Closes #<issue>`.
- Distinguer les commandes réellement exécutées, les simulations et les propositions.
- Exécuter et rapporter les validations adaptées avant livraison.

## Principes Drupal

- Préférer le cœur Drupal et les modules contribués maintenus avant de créer du code custom.
- Préserver la configuration versionnée, Content Sync et leur idempotence.
- Ne pas modifier les menus, la homepage, les aliases, les content types ou les workflows GitHub sans ticket explicite.
- Ne pas activer OpenAI, un chatbot IA ou du tracking sans ticket explicite.
- Ne jamais committer de secret ni de configuration locale sensible.

## Production

- La production est gérée par releases sous `/var/www/agency/`.
- `scripts/deploy-production.sh` reste le mécanisme de déploiement applicatif de référence.
- Les opérations système suivent `docs/operations/production-maintenance.md`.
- Les versions réellement appliquées sont consignées dans `docs/operations/production-version-log.md`.
- `settings.php` de production et les secrets restent hors Git.
- Aucune automatisation de production ne peut être ajoutée pendant le pilote ForgePilot.

## Rôle de ForgePilot

ForgePilot est une couche externe de gouvernance et d’orchestration locale. Il ne fait pas partie du runtime Drupal et ne doit pas devenir une dépendance du site.

La phase initiale est un pilote manuel supervisé destiné à :

- charger les sources d’autorité ;
- vérifier l’état d’onboarding ;
- recommander la prochaine action sûre ;
- préparer un contexte reproductible pour Codex ou Claude Code ;
- améliorer la continuité entre assistants ;
- suivre les actions, validations, limites et décisions sans dupliquer la gouvernance du dépôt.

Les merges, fermetures d’issues, approbations de PR, batchs autonomes et opérations de production restent bloqués pendant cette phase.

## Validations de référence

Les commandes exactes dépendent du ticket. Le socle courant comprend notamment :

```bash
git diff --check
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
ddev composer test:homepage-smoke
ddev composer test:contact
ddev composer test:project-functional
```

Les changements documentaires peuvent utiliser un sous-ensemble justifié, mais les validations réellement exécutées doivent toujours être indiquées.

## Limites connues

- DDEV utilise PHP 8.4 tandis que la plateforme Composer est encore configurée séparément.
- Certaines différences de configuration peuvent être propres à la production et ne doivent pas être corrigées par un `drush cim` global sans analyse.
- ForgePilot est encore en intégration pilote : sa configuration ne constitue pas une autorisation implicite d’exécuter une action sensible.
