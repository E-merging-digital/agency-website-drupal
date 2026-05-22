# Ticket 118 - Stabilisation des exports Drupal

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/303

## Cause racine

Le probleme reproduit venait de deux sources distinctes.

La configuration active locale n'avait pas encore importe la correction recente
du split `production`. Un `drush cex` avant `drush cim` reexportait donc
l'ancien contenu de `config_split.config_split.production`, avec
`google_tag.container.default` dans `complete_list`. Cette modification a ete
rejetee afin de conserver le split production attendu :

- `google_tag.settings`
- `google_tag.container.G-K5TDNZCPTY.69f8b7287a84a3.47771255`

Les autres differences etaient des normalisations neutres produites par
l'export Drupal :

- ordre canonique des champs dans
  `core.entity_form_display.node.page.default` ;
- ajout explicite de `langcode: true` dans les champs masques de
  `core.entity_view_display.node.page.default` ;
- ordre canonique des proprietes de `core.menu.static_menu_link_overrides` ;
- suppression de guillemets non necessaires dans
  `emerging_digital_chatbot.settings` et
  `block.block.emerging_digital_footer_branding`.

## Correction conservee

Seules les normalisations neutres ont ete conservees. Le fichier
`config_split.config_split.production.yml` n'est pas modifie par ce ticket afin
de ne pas casser la configuration GA4 de production.

## Verification attendue

Apres import de la configuration synchronisee :

```bash
ddev drush cim -y
ddev drush cex -y
```

`cex` doit indiquer que la configuration active est identique au repertoire
d'export `../config/sync`.
