# Ticket 52 - Audit des librairies CSS Drupal

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/171

## Objectif

Auditer les CSS chargees sur la homepage Drupal et reduire uniquement les chargements inutiles suffisamment surs, sans ajouter de module contrib, sans PurgeCSS, sans bundler, et sans modifier les modules contrib.

## Homepage auditee

- URL locale : `http://agency-website-drupal.ddev.site/fr`
- Theme actif : `web/themes/custom/emerging_digital`
- Profil audite : visiteur anonyme, route non-admin

## Librairies globales identifiees

Le theme `emerging_digital` declare une seule librairie globale dans `emerging_digital.info.yml` :

- `emerging_digital/global-styling`

Cette librairie charge :

- `css/base.css` : base typographique, variables CSS, reset de box sizing
- `css/layout.css` : header, footer, navigation, layout responsive
- `css/components.css` : boutons, sections, cartes, CTA, styles de formulaires et contenus
- `js/main.js` avec `defer`
- dependance : `core/drupal`

Ces fichiers sont utilises par la homepage : header, navigation, hero, cards, trust list, CTA, footer, menu mobile et langue.

## CSS observees avant correction

La homepage anonyme chargeait notamment :

- `/core/modules/system/css/components/align.module.css`
- `/core/modules/system/css/components/container-inline.module.css`
- `/core/modules/system/css/components/clearfix.module.css`
- `/core/modules/system/css/components/hidden.module.css`
- `/core/modules/system/css/components/js.module.css`
- `/modules/contrib/ai/assets/css/ai_global.css`
- `/modules/custom/emerging_digital_cookie_consent/css/cookie-consent.css`
- `/modules/contrib/paragraphs/css/paragraphs.unpublished.css`
- `/themes/custom/emerging_digital/css/base.css`
- `/themes/custom/emerging_digital/css/layout.css`
- `/themes/custom/emerging_digital/css/components.css`

Tailles locales utiles pour l'audit :

- `ai_global.css` : 7 671 octets
- `ai_global.js` : 2 330 octets
- `paragraphs.unpublished.css` : 57 octets
- `cookie-consent.css` : 4 679 octets
- CSS theme : 30 661 octets au total

## Diagnostic

`ai/ai_global` est attache inconditionnellement par le module contrib `ai` via `ai_page_attachments()`.

Le CSS et le JS de cette librairie concernent des utilitaires UI AI : classes `ai-*`, icones, pills et tooltips `data-ai-tooltip`. Aucune utilisation publique de ces classes ou attributs n'a ete trouvee dans :

- `web/themes/custom`
- `web/modules/custom`
- `config/sync`

Dans ce projet, l'IA est utilisee pour des fonctions editoriales/admin, notamment `agency_ai_translation`, pas pour un composant public de la homepage.

`paragraphs/drupal.paragraphs.unpublished` est aussi charge sur la homepage car Paragraphs l'attache au rendu des paragraphes. Cette CSS est minuscule et sert a signaler les paragraphes non publies. Sa suppression selective propre est moins directe, car elle est ajoutee dans le `ParagraphViewBuilder` contrib apres les hooks classiques d'alter de rendu. La retirer globalement via override de librairie ou `hook_css_alter` serait trop large pour un gain de 57 octets et pourrait degrader l'experience des editeurs.

## Optimisation appliquee

Ajout d'un `hook_page_attachments_alter()` dans le theme custom :

- retire `ai/ai_global` uniquement pour les visiteurs anonymes ;
- ne s'applique pas aux routes admin ;
- conserve la librairie pour les utilisateurs connectes, afin de ne pas casser les usages editoriaux ou les ecrans AI ;
- ajoute le contexte de cache `user.roles:anonymous`.

Fichier modifie :

- `web/themes/custom/emerging_digital/emerging_digital.theme`

## CSS observees apres correction

La homepage anonyme ne charge plus :

- `/modules/contrib/ai/assets/css/ai_global.css`

Le JS associe a la meme librairie n'est plus charge non plus :

- `/modules/contrib/ai/assets/js/ai_global.js`

La reduction appliquee est donc d'environ 10 Ko non minifies sur la homepage anonyme, sans ajout de complexite de build.

Les CSS restantes sont conservees volontairement :

- CSS core Drupal : petites CSS de composants attaches par le rendu core ;
- `cookie-consent.css` : necessaire au bandeau cookies public ;
- `paragraphs.unpublished.css` : conservee pour eviter une suppression globale trop large ;
- CSS theme : utilisee par les composants visibles de la homepage et des pages secondaires.

## Lighthouse

Lighthouse CLI n'a pas ete execute localement : aucun binaire `lighthouse` ou `npx` n'est disponible dans le PATH PowerShell de l'environnement.

Impact attendu :

- une ressource CSS contrib inutile en moins sur la homepage anonyme ;
- une ressource JS contrib inutile en moins avec la meme librairie ;
- pas de changement de rendu attendu ;
- pas de nouveau module contrib, pas d'AdvAgg, pas de PurgeCSS, pas de webpack/Vite.

## Verifications

Commandes principales executees :

```powershell
git checkout main
git pull origin main
git checkout -b feature/ticket-52-css-library-audit
ddev exec drush cr
ddev exec curl -s http://agency-website-drupal.ddev.site/fr
ddev exec curl -I -s http://agency-website-drupal.ddev.site/fr
ddev exec curl -s http://agency-website-drupal.ddev.site/fr | Select-String -Pattern 'ai_global','paragraphs.unpublished','stylesheet'
ddev exec drush cst
ddev exec drush cex -y
ddev exec env SIMPLETEST_BASE_URL=http://agency-website-drupal.ddev.site SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/agency_project_tests/tests --exclude-group unstable_language_switcher
```

Controles realises :

- audit des librairies du theme ;
- recherche des `attach_library`, `libraries-extend` et `libraries-override` ;
- verification des usages publics des classes/attributs `ai-*` ;
- verification que le HTML final ne contient plus `ai_global` ;
- verification desktop et mobile via Edge headless avec profil temporaire et URL cache-bustee ;
- verification que la homepage repond en `200 OK`.

Resultat des tests cibles :

- `agency_project_tests` : OK
- 3 tests executes
- 23 assertions
- 15 deprecations contrib/core signalees, sans echec de test

## Decision

Optimisation retenue : retirer `ai/ai_global` sur les pages publiques anonymes.

Optimisations non retenues :

- ne pas ajouter AdvAgg ;
- ne pas ajouter PurgeCSS ;
- ne pas ajouter webpack/Vite ;
- ne pas decouper artificiellement les trois CSS du theme, car elles sont petites, lisibles et partagees par les composants de la homepage et des pages secondaires ;
- ne pas supprimer globalement `paragraphs.unpublished.css`, car le gain est negligeable et le risque editorial n'est pas justifie.
