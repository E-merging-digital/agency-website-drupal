# Ticket 69 - Migration Content Sync de la page Services

## Objectif

La page `/services` est désormais déclarée dans le catalogue
`content_sync/` sous l’identifiant métier `services`.

Cette migration conserve les anciens fichiers `default_content` et ne retire
pas la dépendance au module `default_content`. Elle ne modifie ni les menus, ni
la homepage, ni le script de déploiement production.

## Contenu synchronisé

- Node `page` : `services`
- Alias FR : `/services`
- Alias EN interne : `/services`, exposé publiquement avec le préfixe de langue
  `/en/services`
- Paragraphes synchronisés dans l’ordre éditorial :
  1. `services.hero`
  2. `services.intro`
  3. `services.grid`
  4. `services.why-drupal`
  5. `services.cta`

Chaque paragraphe garde un identifiant métier stable et déclare son UUID
historique `default_content` pour permettre une première reprise sans créer de
doublon lorsque le contenu existe déjà.

## Moteur Content Sync

Le moteur supporte maintenant un périmètre supplémentaire strict :

- `node:service`, déjà existant ;
- `node:page`, uniquement pour les pages déclarant des composants
  `field_home_components`.

Pour une page, la synchronisation :

1. résout le node par mapping, UUID historique, puis alias ;
2. applique les traductions FR/EN et les alias ;
3. résout chaque paragraphe par mapping, UUID historique, puis création ;
4. applique les traductions FR/EN de chaque paragraphe ;
5. rattache les paragraphes à `field_home_components` dans l’ordre du YAML ;
6. écrit le mapping du node et des paragraphes.

Les menus restent explicitement hors périmètre.

## Validation prévue

Les commandes de validation du ticket sont :

```powershell
ddev drush cr
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync services --dry-run
ddev drush emerging:content-sync services
ddev drush emerging:content-sync services
ddev drush emerging:content-sync --all --dry-run
ddev drush emerging:content-sync --all
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```
