# Ticket 51 - Contraste du bandeau cookies

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/169

## Contexte

Lighthouse signalait un contraste insuffisant sur le bandeau cookies, alors que les scores PageSpeed restaient déjà élevés.

La correction devait rester dans le thème custom `emerging_digital`, sans modifier les modules contrib ni le module custom de consentement.

## Diagnostic

Le bandeau cookies est injecté côté JavaScript par le module custom `emerging_digital_cookie_consent`.

DOM généré observé sur `http://agency-website-drupal.ddev.site/fr` :

- conteneur : `#ed-cookie-banner.ed-cookie-banner`
- fond du panneau : `.ed-cookie-banner__content` avec `#0f172a`
- lien de politique ajouté par le thème : `.cookie-policy-link.cookie-policy-link--banner.js-cookie-policy-link--banner`

La cause réelle du contraste insuffisant venait du lien ajouté par le thème. La classe générique `.cookie-policy-link` utilisait `var(--color-muted)` (`#4b5563`), prévu pour un fond clair. Dans le bandeau, ce lien se retrouvait sur le fond bleu nuit `#0f172a`.

Ratio avant correction :

- `#4b5563` sur `#0f172a` : `2.36:1`, insuffisant pour du texte normal WCAG AA.

## Correction

Correction ciblée dans :

- `web/themes/custom/emerging_digital/css/components.css`

Le lien `.cookie-policy-link` est désormais surchargé uniquement lorsqu'il est dans `.ed-cookie-banner`.

Couleurs après correction :

- lien normal : `#dbeafe` sur `#0f172a`, ratio `14.63:1`
- lien au survol/focus : `#ffffff` sur `#0f172a`, ratio `17.85:1`

Ces ratios dépassent le minimum WCAG AA (`4.5:1`) pour du texte normal.

## Vérifications

Commandes et contrôles effectués :

- `ddev exec drush cr`
- inspection du DOM généré via Edge headless et `--dump-dom`
- vérification que la CSS corrigée est servie par le site local
- calcul des ratios WCAG
- capture desktop Edge headless `1365x900`
- capture responsive Edge headless `500x844`

Résultat visuel :

- desktop : lien du bandeau lisible, design sobre conservé
- mobile/responsive : bandeau lisible, boutons visibles, lien lisible sur fond sombre

Limite de vérification :

- le runtime Node du navigateur intégré Codex a été bloqué par Windows avec `Accès refusé`; la vérification visuelle a donc été faite avec Edge headless local.

## Périmètre

Fichiers modifiés :

- `web/themes/custom/emerging_digital/css/components.css`
- `docs/ticket-51-accessibility-cookie-contrast.md`

Aucun module contrib n'a été modifié.
