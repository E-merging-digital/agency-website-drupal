# AGENTS.md

Documentation de contexte pour Codex et les contributeurs du projet Drupal
E-merging Digital. Elle doit rester courte, pratique et alignee avec le code.

## 1. Stack technique

- Drupal 11, base `drupal/recommended-project`.
- PHP DDEV : 8.4. Plateforme Composer : 8.3 dans `composer.json`.
- MariaDB 10.11 via DDEV.
- Docroot : `web/`.
- Theme custom : `web/themes/custom/emerging_digital`.
- Modules custom principaux : `emerging_digital_content`,
  `agency_project_tests`, `agency_ai_translation`.

## 2. Architecture projet

- Code custom uniquement dans `web/modules/custom`, `web/themes/custom` et
  `web/profiles/custom`.
- Configuration Drupal versionnee dans `config/sync`.
- Catalogue Content Sync dans
  `web/modules/custom/emerging_digital_content/content_sync`.
- Documentation projet dans `docs/`.
- Scripts d'exploitation dans `scripts/`.

## 3. Workflow Git/branches

- 1 ticket GitHub = 1 branche Git = 1 Pull Request.
- Toujours partir de `main` a jour.
- Nommer la branche `feature/<slug-du-ticket>`.
- Ne jamais modifier directement `main`.
- La PR doit cibler `main` et referencer le ticket avec `Closes #X`.
- Aucun changement hors perimetre du ticket.

## 4. Workflow Content Sync

- Source editoriale versionnee :
  `web/modules/custom/emerging_digital_content/content_sync/catalog.yml`.
- Fichiers de contenu :
  `web/modules/custom/emerging_digital_content/content_sync/node/*.yml`.
- Valider avant toute application :
  `ddev drush emerging:content-sync:validate`.
- Toujours tester en lecture seule avant ecriture :
  `ddev drush emerging:content-sync --all --dry-run`.
- Application globale : `ddev drush emerging:content-sync --all`.
- Application ciblee :
  `ddev drush emerging:content-sync <content-id> --dry-run`, puis sans
  `--dry-run`.
- Content Sync doit rester idempotent, lisible en dry-run et prudent en
  production.
- Ne pas recycler les `legacy_uuid`, mappings ou identifiants metier existants.

## 5. Regles SEO

- Les landing pages services SEO utilisent le bundle `service`.
- `agence-drupal-belgique` est le blueprint editorial et technique des pages
  services.
- `field_short_description` alimente la meta description, Open Graph, Twitter
  Cards et Schema.org WebPage.
- `field_detailed_description` porte le contenu long structure : H2, listes,
  CTA et liens internes.
- Les aliases FR/EN doivent rester explicites, stables et declares dans Content
  Sync pour les contenus geres.
- Ne pas creer de nouveau content type pour une page service SEO sans ticket
  technique dedie.

## 6. Regles multilingues

- Le site est bilingue FR/EN.
- Toute page geree par Content Sync doit declarer ses traductions FR et EN.
- Les aliases publics attendus sont prefixes par Drupal selon la langue
  (`/fr/...`, `/en/...`) mais les aliases declares restent neutres dans le
  catalogue (`/contact`, `/drupal-agency-belgium`, etc.).
- Ne jamais casser le language switcher, les hreflang ou les traductions de
  menus.
- Les textes SEO doivent etre differencies par langue, pas simplement dupliques.

## 7. Regles de maillage interne

- Les liens internes SEO doivent etre explicites dans les champs HTML quand ils
  portent une intention de navigation.
- Favoriser les liens vers `/services`, `/ia-drupal`, `/cas-clients`,
  `/contact` et les pages services pertinentes.
- Les pages services doivent contenir 2 a 4 liens internes utiles.
- Ne pas ajouter de liens artificiels ou redondants.
- Verifier les URL FR/EN apres Content Sync.

## 8. Promotions homepage/services

- Les cartes ajoutees aux grilles existantes passent par les `promotions`
  Content Sync.
- Les promotions enrichissent les paragraphes `services` de la homepage et/ou
  de la page Services sans ecraser les autres items.
- Ne pas modifier l'ordre ou le contenu des composants hors besoin du ticket.
- La homepage ne doit recevoir une promotion que si la priorite commerciale est
  explicite.

## 9. Menus

- Les menus sont hors perimetre de Content Sync.
- Ne pas creer, modifier, traduire, reordonner ou supprimer de liens de menus
  dans un ticket de contenu ou SEO, sauf demande explicite.
- Une commande `emerging:content-sync` doit laisser les entites
  `menu_link_content` intactes.

## 10. `system.site:page.front`

- Ne pas modifier `system.site:page.front` sans ticket explicite.
- La configuration actuelle pointe vers `/node/5` dans `config/sync`.
- La front publique est geree via le contenu `homepage` et ses aliases FR/EN.
- Toute correction de homepage doit preserver `<front>` et le smoke test.

## 11. Link Checker

- Module contrib : `drupal/linkchecker`.
- Rapport admin : `/admin/reports/linkchecker`.
- Configuration : `/admin/config/content/linkchecker`.
- Les champs HTML portant le maillage SEO sont analyses.
- Les champs `link` de paragraphes ne sont pas scannes pour eviter les faux
  positifs sur des URI internes neutres.
- Le Link Checker n'est pas bloquant pour le deploiement et n'est pas integre a
  GitHub Actions.

## 12. Commandes DDEV utiles

```bash
ddev start
ddev composer install
ddev drush cr
ddev drush updb -y
ddev drush cim -y
ddev drush cex -y
ddev drush status
ddev drush cron
```

## 13. Validations obligatoires

Executer avant de rendre un ticket :

```bash
git diff --check
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```

## 14. Commandes de tests

```bash
ddev composer test:homepage-smoke
ddev composer test:contact
ddev composer test:project-functional
ddev composer test:ai-translation:stable
ddev composer ci
```

Le CI doit inclure un smoke test Drupal `BrowserTestBase` minimal sur
`<front>` et echouer en cas d'erreur de rendu runtime.

## 15. Bonnes pratiques Codex

- Lire le ticket, `AGENTS.md`, puis les fichiers touches avant modification.
- Limiter les changements au perimetre exact du ticket.
- Preferer les patterns existants aux nouvelles abstractions.
- Ne jamais reformater ou refactoriser hors necessite.
- Pour les changements Content Sync, toujours valider le catalogue et faire un
  dry-run.
- Pour les changements frontend, verifier le rendu public concerne.
- Mentionner clairement les validations lancees et leurs resultats.

## 16. Pieges connus

- DDEV utilise PHP 8.4 mais `composer.json` declare une plateforme PHP 8.3.
- Les pages `service` ne portent pas directement les paragraphes
  `field_home_components`; les grilles sont enrichies via promotions.
- `node:page` a une meta description plus generique que `node:service`.
- Modifier les aliases peut casser le SEO, les hreflang et le Link Checker.
- Modifier les menus peut casser les traductions et le language switcher.
- `--prune=unpublish` est reserve aux cas maitrises ; ne jamais l'utiliser par
  habitude.

## 17. Interdictions importantes

- Ne pas modifier les menus.
- Ne pas modifier `system.site:page.front`.
- Ne pas modifier les workflows GitHub Actions.
- Ne pas modifier `scripts/deploy-production.sh`.
- Ne pas modifier les content types sans ticket dedie.
- Ne pas modifier les contenus YAML hors ticket de contenu explicite.
- Ne pas modifier les aliases hors ticket SEO/contenu explicite.
- Ne pas casser Content Sync ni son idempotence.
- Ne pas modifier la logique metier existante hors demande explicite.
- Ne jamais commiter de secret, token, cle ou fichier local sensible.
