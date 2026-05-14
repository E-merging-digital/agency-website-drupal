# Ticket 83 - Link Checker

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/231

## Objectif

Le site utilise le module contrib `drupal/linkchecker` pour surveiller les liens internes et externes via Drupal cron, les queues Drupal et le rapport d'administration.

Le contrôle des liens n'est pas bloquant pour le déploiement : il n'est pas intégré à GitHub Actions, ne modifie pas `deploy-production.sh` et ne crée pas de test CI dédié aux liens.

## Configuration

- Page de configuration : `/admin/config/content/linkchecker`
- Rapport admin des liens cassés : `/admin/reports/linkchecker`
- Mode de vérification : liens internes et externes
- Schéma par défaut : `https://`
- Base path canonique : `emergingdigital.be`
- Contenus analysés : contenus publiés uniquement
- Extraction HTML : liens `<a>` des champs texte pertinents

Les champs `link` des paragraphes ne sont pas scannés afin d'éviter des faux positifs multilingues sur des URI internes neutres comme `internal:/contact`. Les liens SEO explicites dans les contenus YAML sont portés par les champs HTML et restent analysés avec leurs préfixes `/fr/` et `/en/`.

## Champs analysés

Link Checker est activé sur les champs texte qui portent le maillage interne SEO :

- descriptions détaillées et courtes des contenus `service`, `ai_feature`, `case_client` et `article`
- textes et listes des paragraphes `hero`, `text_block`, `services`, `ai_features`, `case_clients`, `trust_list` et `cta`

Cette configuration ne modifie ni les types de contenu, ni les aliases, ni les menus.

## Exploitation

Drupal cron alimente la surveillance en continu :

```bash
ddev drush cron
```

Pour une vérification locale ponctuelle après une grosse synchronisation de contenu :

```bash
ddev drush linkchecker:clear --uri=http://agency-website-drupal.ddev.site
ddev drush linkchecker:check --uri=http://agency-website-drupal.ddev.site
```

En DDEV, Drupal configure son client HTTP avec le certificat Traefik du projet.
Cela permet aussi une exécution avec l'URL HTTPS DDEV sans désactiver la
vérification TLS :

```bash
ddev drush linkchecker:clear --uri=https://agency-website-drupal.ddev.site
ddev drush linkchecker:check --uri=https://agency-website-drupal.ddev.site
```

En environnement public, cron doit utiliser l'URL publique du site afin que les liens internes soient vérifiés contre le domaine canonique.
