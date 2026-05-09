# Ticket 58 — Agence Drupal Belgique

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/181

## Décision d’implémentation

La page `/agence-drupal-belgique` est gérée par le content sync custom `emerging:content-sync` avec l’identifiant métier stable `agence-drupal-belgique`.

Le synchroniseur ne dépend pas de `default_content` pour cette page et ne modifie pas directement les entités `menu_link_content`.

## Comportement

- Le contenu cible est un node de type `service`.
- Les alias conservés sont `/agence-drupal-belgique` en français et `/drupal-agency-belgium` en anglais.
- Si un node existe déjà avec l’alias ou le titre métier, il est mis à jour au lieu d’être dupliqué.
- La page `/services` et la homepage reçoivent une carte éditoriale rejouable vers la page service.
- Les cartes de paragraphes `services` acceptent un troisième segment optionnel `Titre|Description|URL` pour afficher un lien.
- Le rendu des cartes localise l’URL avec l’API Drupal selon la langue du paragraphe : `/agence-drupal-belgique` en français et `/en/drupal-agency-belgium` en anglais.
- Le libellé du bouton est rendu en français (`Découvrir`) et en anglais (`Discover`).
- Les métadonnées SEO utilisent les defaults `node__service`, basés sur `field_short_description`.

## Validation locale

Commandes prévues :

```bash
ddev drush cr
ddev drush emerging:content-sync agence-drupal-belgique --dry-run
ddev drush emerging:content-sync agence-drupal-belgique
composer phpcs
composer phpstan
composer ci
```
