# Ticket 49 - Diagnostic cacheability homepage /fr

Issue: https://github.com/E-merging-digital/agency-website-drupal/issues/165

## Constat initial

Mesure anonyme avant correction, apres `drush cr`:

```text
HTTP/1.1 200 OK
Cache-Control: max-age=3600, public
X-Drupal-Cache: MISS
X-Drupal-Dynamic-Cache: MISS
X-Drupal-Cache-Tags: ... CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form ...
X-Drupal-Cache-Contexts: ... session.exists ... user.permissions user.roles
```

La page est statique visuellement, mais le bloc place en region `header_language`
utilisait le plugin contrib `language_dropdown_block:language_interface`.

## Cause identifiee

La source de `CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form` est le bloc
`block.block.emerging_digital_languagedropdownswitchercontent`.

Le module contrib `lang_dropdown` construit ce bloc via Form API:

```php
$form = $this->formBuilder->getForm(LanguageDropdownForm::class, ...);
```

Le rendu HTML contenait donc un formulaire de switcher de langue, meme si aucun
formulaire metier n'etait visible sur la homepage. La desactivation temporaire
du bloc a fait disparaitre le tag `CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form`.

## Verifications

- `emerging_digital_messages`: pas la source du tag `form`.
- `emerging_digital_main_menu`: pas la source du tag `form`.
- `emerging_digital_footer_menu`: pas la source du tag `form`.
- `emerging_digital_content`: pas la source du tag `form` sur `/fr`.
- `emerging_digital_footer_branding`: pas la source du tag `form`.
- Webform: le theme rend le Webform `contact` uniquement pour le paragraphe
  UUID `855b08da-0ec9-4261-883a-d27f214606e6`, rattache a la page Contact
  (`/contact`), pas a la homepage `/fr`.
- Preprocess `emerging_digital`: aucun preprocess homepage ne rend de Webform.
- BigPipe: active. Le contexte `cookies:big_pipe_nojs` est attendu. Aucun lazy
  builder custom n'a ete trouve dans `web/themes/custom` ou `web/modules/custom`.

## Contextes restants

Apres suppression du formulaire du header, les contextes suivants restent:

```text
session.exists
user.permissions
user.roles:authenticated
```

Ils ne proviennent pas du formulaire `lang_dropdown`: ils restent presents meme
quand le bloc de langue est desactive. Ils sont lies au rendu Drupal core des
blocs, menus, access checks et contexte de session/BigPipe. Ils ne rendent pas
la reponse privee tant qu'aucune session anonyme n'est demarree.

## Correction appliquee

Correction minimale appliquee:

- remplacement du plugin de bloc `language_dropdown_block:language_interface`
  par un bloc custom `emerging_digital_language_switcher`;
- conservation de la meme region `header_language`;
- conservation de l'UI existante (`language-switcher`, JS/CSS du theme);
- rendu de liens HTML stateless `/fr` et `/en`;
- aucune suppression du changement de langue;
- aucun changement dans les modules contrib.

Fichiers:

- `config/sync/block.block.emerging_digital_languagedropdownswitchercontent.yml`
- `web/modules/custom/emerging_digital_content/src/Plugin/Block/LanguageSwitcherBlock.php`

## Mesure apres correction

Mesure anonyme apres `drush cr`:

```text
HTTP/1.1 200 OK
Cache-Control: max-age=3600, public
X-Drupal-Cache: MISS
X-Drupal-Dynamic-Cache: MISS
X-Drupal-Cache-Tags: block_view ... rendered user:1
X-Drupal-Cache-Contexts: cookies:big_pipe_nojs languages:language_content languages:language_interface languages:language_url route.menu_active_trails:footer route.menu_active_trails:main session.exists theme timezone url.path url.query_args url.site user.permissions user.roles:authenticated
```

Le tag `CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form` n'est plus present.
La reponse ne contient pas de `Set-Cookie` et reste `public`.

Verification HTML:

```text
language-switcher present
lang_dropdown_form absent
<form absent
```

## Notes

Un test temporaire avec le bloc langue core `language_block:language_interface`
a bien supprime le formulaire, mais a declenche une session anonyme dans cette
configuration locale. Il n'a donc pas ete retenu.
