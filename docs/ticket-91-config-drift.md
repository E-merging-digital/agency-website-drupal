# Ticket 91 - Stabilisation des derives de configuration

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/245

## Causes racines

### Pathauto

Les patterns fallback `node_ai_feature`, `node_article`, `node_case_client`,
`node_page` et `node_service` etaient versionnes sans slash initial, par
exemple `ia/[node:title]`.

La configuration active Drupal/Pathauto normalise ces patterns avec un slash
initial, par exemple `/ia/[node:title]`. Un `drush cex` reexportait donc les
memes patterns a chaque passage.

La correction consiste a versionner la forme canonique exportee par Drupal. Les
patterns multilingues FR/EN utilisaient deja cette forme et les aliases
editoriaux restent geres par Content Sync avec `pathauto: false`.

### Link Checker

`linkchecker.settings` etait fonctionnellement correct, mais l'ordre exporte
ne suivait pas l'ordre du schema actif du module contrib. Drupal reexportait
uniquement le placement de `search_published_contents_only`.

Les erreurs locales `cURL error 60` venaient d'une cause distincte : en DDEV,
Link Checker utilise le client HTTP Drupal/Guzzle. Le routeur DDEV sert un
certificat TLS local Traefik qui n'etait pas dans le trust store utilise par
Guzzle dans le conteneur web.

`settings.php` configure maintenant, uniquement en DDEV, le parametre
`$settings['http_client_config']['verify']` vers le certificat Traefik du
projet, avec fallback vers le CA mkcert global DDEV. La verification TLS reste
active ; aucun `verify: false` ni contournement SSL n'est introduit.

### Display AI Feature

Le display `core.entity_view_display.node.ai_feature.default` etait stable
fonctionnellement, mais l'ordre des composants dans le YAML ne correspondait
pas a l'ordre canonique de l'export actif.

Les poids restent inchanges :

- `field_short_description`: 0
- `field_detailed_description`: 1
- `field_concrete_example`: 2
- `field_use_cases`: 3
- `field_customer_benefit`: 4
- `links`: 100

Le rendu public garde donc le meme ordre, mais l'export devient reproductible.

## Comportement attendu

- `ddev drush cim -y` ne doit pas modifier les menus ni `system.site:page.front`.
- `ddev drush cex -y` doit rester propre apres un premier export canonique.
- Les aliases Content Sync restent explicites et ne doivent pas etre regeneres.
- Les patterns Pathauto fallback restent avec slash initial dans la config.
- Link Checker peut verifier l'URL HTTPS DDEV sans erreur cURL 60.

## Verification Link Checker en DDEV

```bash
ddev drush cr
ddev drush linkchecker:clear --uri=https://agency-website-drupal.ddev.site
ddev drush linkchecker:check --uri=https://agency-website-drupal.ddev.site
```

Pour isoler un probleme TLS local :

```bash
ddev exec curl -I -s https://agency-website-drupal.ddev.site/fr
ddev exec curl --cacert /mnt/ddev_config/traefik/certs/agency-website-drupal.crt -I -s https://agency-website-drupal.ddev.site/fr
```
