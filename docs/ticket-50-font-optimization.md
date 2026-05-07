# Ticket 50 - Optimisation des fonts

## Objectif

Auditer les fonts chargees sur la homepage Drupal et appliquer uniquement les optimisations utiles pour Lighthouse et les Core Web Vitals, sans sur-optimisation ni ajout de module contrib.

## Homepage auditee

- URL locale: `http://agency-website-drupal.ddev.site/fr`
- Theme actif observe: `web/themes/custom/emerging_digital`
- CSS theme charges sur la homepage:
  - `/themes/custom/emerging_digital/css/base.css`
  - `/themes/custom/emerging_digital/css/layout.css`
  - `/themes/custom/emerging_digital/css/components.css`

## Fonts reellement chargees

Aucune webfont n'est chargee par la homepage.

Le HTML rendu ne contient pas de lien vers Google Fonts, `fonts.gstatic.com`, ni vers un fichier `.woff`, `.woff2`, `.ttf` ou `.otf`.

Le theme declare uniquement des piles CSS:

- `--font-body: "Inter", "Segoe UI", Roboto, Arial, sans-serif;`
- `--font-heading: "Poppins", "Segoe UI", Roboto, Arial, sans-serif;`

Ces familles ne sont pas associees a des fichiers webfont dans le projet. Le navigateur utilise donc une police locale si elle existe sur l'environnement utilisateur, puis les fallbacks systeme.

## Google Fonts externes

Aucune utilisation de Google Fonts externe n'a ete identifiee dans:

- `config`
- `web/modules/custom`
- `web/themes/custom`
- le HTML rendu de la homepage

Il n'y a donc pas de cout reseau lie a `fonts.googleapis.com` ou `fonts.gstatic.com`.

## Fonts self-hosted

Aucun fichier font self-hosted n'a ete trouve dans le perimetre custom/config:

- aucun `.woff2`
- aucun `.woff`
- aucun `.ttf`
- aucun `.otf`

## Regles @font-face et font-display

Aucune regle `@font-face` n'a ete trouvee dans le perimetre custom/config.

Par consequence:

- aucun `font-display` n'est applicable;
- aucun risque de FOIT/FOUT lie a une webfont projet n'a ete identifie;
- aucune optimisation `font-display: swap` n'est possible sans introduire de webfont, ce qui serait une sur-optimisation pour ce ticket.

## Formats

Comme aucune webfont n'est chargee, aucun format webfont n'est utilise.

Evaluation:

- `woff2`: non utilise
- `woff`: non utilise
- `ttf` / `otf`: non utilises
- autres formats: non identifies

## Fonts critiques above-the-fold

Les textes critiques above-the-fold sont:

- branding header: `.site-name`
- navigation principale
- titre hero: `.ed-section__title--hero`
- texte d'introduction hero
- boutons CTA hero

Ces elements utilisent les piles CSS du theme:

- corps et texte courant: `var(--font-body)`
- titres et branding: `var(--font-heading)`

Comme ces piles ne declenchent aucun telechargement de webfont, elles ne bloquent pas FCP/LCP via une ressource font.

## Evaluation du preload

Decision: ne pas ajouter de preload.

Raisons:

- aucune webfont n'est chargee;
- aucun fichier `.woff2` critique n'existe dans le theme;
- preloader une ressource inexistante ou non critique serait inutile;
- ajouter le module contrib `preload_font` n'apporterait pas de benefice mesurable dans l'etat actuel;
- preloader plusieurs variantes irait a l'encontre de l'objectif de simplicite et risquerait de degrader la priorisation reseau.

`preload_font` n'est donc pas retenu pour ce ticket.

## Optimisation appliquee

Une seule correction sure a ete appliquee dans le theme:

- remplacement de `font-family: var(--font-base);` par `font-family: var(--font-body);` dans `web/themes/custom/emerging_digital/css/layout.css`

`--font-base` n'etait pas defini. La correction evite une declaration CSS invalide et garde l'usage typographique aligne avec les variables existantes du theme.

## Lighthouse

Lighthouse n'a pas pu etre execute localement: aucun binaire `lighthouse`, `npx`, Chrome ou Edge n'a ete trouve dans l'environnement PowerShell disponible.

Impact attendu cote fonts:

- pas de reduction de requetes font, car aucune requete font n'existait;
- pas de preload ajoute;
- pas de degradation de priorisation reseau;
- correction mineure de coherence CSS sur une variable de font.

## Commandes executees

Commandes d'audit principales:

```powershell
git checkout main
git pull origin main
git checkout -b feature/ticket-50-font-optimization
ddev exec find web/themes/custom -iname "*.woff*" -o -iname "*.ttf" -o -iname "*.otf"
ddev exec grep -R "@font-face\|font-display\|fonts.googleapis" web/themes/custom -n
ddev exec curl -s http://agency-website-drupal.ddev.site/fr
Get-ChildItem -Path web\themes\custom,web\modules\custom,config -Recurse -File -Include *.woff,*.woff2,*.ttf,*.otf
Get-ChildItem -Path web\themes\custom,web\modules\custom,config -Recurse -File | Select-String -Pattern 'fonts.googleapis.com','fonts.gstatic.com','@font-face','font-display','preload_font' -SimpleMatch
ddev exec drush pml --status=enabled --type=module
```

## Conclusion

Le projet ne charge actuellement aucune webfont sur la homepage. L'optimisation pertinente consiste donc a ne pas ajouter de preload ni de module contrib, et a conserver une solution simple cote theme. Le seul changement code applique corrige une reference de variable CSS de font non definie.
