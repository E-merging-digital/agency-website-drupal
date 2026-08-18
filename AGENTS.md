# AGENTS.md

Documentation de contexte pour Codex et les contributeurs du projet Drupal
E-merging Digital. Elle doit rester courte, pratique et alignee avec le code.

## 1. Stack technique

- Drupal 11, base `drupal/recommended-project`.
- PHP DDEV : 8.4. Plateforme Composer : 8.3 dans `composer.json`.
- MariaDB 11.8 via DDEV.
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

## 2.1 Doctrine Drupal AI

- Pour toute tache touchant l'IA, la traduction IA ou une fonctionnalite IA du
  blog, lire **obligatoirement** `docs/drupal-ai-architecture.md` avant toute
  modification.
- Drupal AI est l'abstraction par defaut : ne pas ajouter d'appel direct a un
  provider/SDK IA sans exception explicitement decidee dans un ticket.
- L'IA assiste le workflow Drupal ; elle ne contourne pas permissions,
  traductions, revisions ou validation humaine et ne publie pas automatiquement
  par defaut.
- Aucun secret IA n'est versionne ; utiliser Drupal Key/configuration locale
  securisee.
- Preferer les composants Drupal AI stables aux implementations custom et ne pas
  introduire de branche `-dev`/experimentale sans ticket et preuve dedies.
- `agency_ai_translation` est une implementation legacy a converger avec Drupal
  AI/AI Translate seulement apres preuve de parite ; ne pas la supprimer ou
  l'etendre avec de nouveaux appels provider directs par habitude.

## 2.2 Capacites d'execution et d'inspection UI

- Lire **obligatoirement** `docs/operations/execution-capabilities.md` avant de
  conclure qu'un runner, DDEV, navigateur, Playwright, Playwright MCP, Chrome
  DevTools MCP ou une capacite d'inspection UI est indisponible.
- Agency dispose du runner auto-heberge dedie `agency-browser-runner-01` sur
  `preflight-runner-01`, compte `agency-runner`.
- Playwright Test, Chromium, Playwright MCP et Chrome DevTools MCP sont des
  capacites disponibles sur ce runner.
- Invariant : `operator-surface capability != project-executor capability`.
  L'absence d'un outil MCP directement visible dans un cockpit ChatGPT ne
  signifie pas que la capacite n'existe pas sur le runner.
- Les artifacts GitHub de Browser Validation (result JSON, evidence,
  screenshots, traces) peuvent etre recuperes et examines par un agent pour une
  revue visuelle reelle du rendu final.
- Avant `HUMAN_REQUIRED`, recharger le registre de capacites et verifier les
  routes machine gouvernees existantes.

## 3. Workflow Git/branches

- 1 ticket GitHub = 1 branche Git = 1 Pull Request.
- Toujours partir de `main` a jour.
- Nommer la branche `feature/<slug-du-ticket>`.
- Ne jamais modifier directement `main`.
- La PR doit cibler `main` et referencer le ticket avec `Closes #X`.
- Aucun changement hors perimetre du ticket.

## 4. Governed Content / Content Sync

- Lire `docs/governed-content-trajectory.md` avant toute modification du
  catalogue Content Sync.
- La migration controlee des contenus ordinaires est **terminee depuis #441**.
  Le catalogue ne doit plus servir de precedent pour versionner du nouveau
  contenu marketing/editorial.
- Les sources machine autoritatives sont le catalogue
  `web/modules/custom/emerging_digital_content/content_sync/catalog.yml` et la
  policy `Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy`.
  La documentation explique cet etat mais ne constitue pas une seconde liste
  d'admission.
- Le catalogue contient uniquement trois contenus explicitement Governed
  Content : `mentions-legales`, `politique-confidentialite`,
  `politique-cookies`.
- `GovernedContentPolicy::LEGACY_RELEASE_PENDING_IDS` est vide. Aucun contenu
  ordinaire ne doit etre ajoute ou readmis dans cette classe.
- Les 33 contenus ordinaires historiquement pilotes par Content Sync ont ete
  liberes et prouves : 3 cas clients du pilote #440, puis 30 contenus sous
  #441 (10 `ai_feature`, 14 `service` et 6 `page`). Ils sont editor-owned dans
  Drupal et ne doivent pas etre repris par Content Sync lors des deploiements.
- Les anciens payloads ordinaires RELEASED ne doivent pas etre recrees dans
  `content_sync/node/` par habitude. Toute nouvelle admission exige un ticket,
  une classe gouvernee justifiee, une identite stable, une procedure de
  review/promotion et un rollback explicite.
- Retirer de la gouvernance Git n'a jamais signifie supprimer ou depublier
  Drupal. Les preuves #440/#441 ont conserve node IDs, UUID, traductions,
  aliases et revisions pendant les releases et les rollbacks.
- Les commandes `emerging:content-sync:release` et `:readmit` restent des
  primitives de transition/rollback auditees ; elles ne definissent plus un
  backlog ordinaire a liberer.
- `GovernedContentCatalogPolicyTest`, la policy runtime et les tests de release
  protegent l'admission durable et empechent la readmission accidentelle des
  contenus ordinaires deja RELEASED.
- #442 fait converger la facade et la terminologie sans dupliquer le moteur.
  `emerging:governed-content` et `emerging:governed-content:validate` sont les
  noms operationnels a privilegier ; `emerging:content-sync` et
  `emerging:content-sync:validate` restent des noms de compatibilite.
- Valider avant toute application :
  `ddev drush emerging:governed-content:validate`.
- Toujours tester en lecture seule avant ecriture :
  `ddev drush emerging:governed-content --all --dry-run`.
- Application globale : `ddev drush emerging:governed-content --all`.
- Application ciblee :
  `ddev drush emerging:governed-content <content-id> --dry-run`, puis sans
  `--dry-run` lorsque le ticket l'autorise.
- La facade historique `emerging:content-sync*` doit rester fonctionnelle tant
  que #442 n'a pas defini et prouve une fenetre de deprecation/retrait.
- Le moteur Governed Content doit rester idempotent, lisible en dry-run et
  prudent en production.
- Ne pas recycler les `legacy_uuid`, mappings ou identifiants metier existants.
- Governed Content ne doit jamais contenir de secret, token, mot de passe, cle
  privee ou credential ; les references a Drupal Key restent des references.

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
- Verifier les URL FR/EN apres Governed Content.

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
- Une commande `emerging:governed-content` doit laisser les entites
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
npm run browser:validate
```

Le CI doit inclure un smoke test Drupal `BrowserTestBase` minimal sur
`<front>` et echouer en cas d'erreur de rendu runtime.

Pour un changement frontend ou interactif, utiliser la Browser Validation
Playwright sur le runner Agency lorsque le ticket l'exige et examiner les
preuves visuelles publiees.

## 15. Bonnes pratiques Codex

- Lire le ticket, `AGENTS.md`, puis les fichiers touches avant modification.
- Lire `docs/operations/execution-capabilities.md` avant toute conclusion sur
  les capacites machine ou UI disponibles.
- Limiter les changements au perimetre exact du ticket.
- Preferer les patterns existants aux nouvelles abstractions.
- Ne jamais reformater ou refactoriser hors necessite.
- Pour les changements Governed Content, toujours lire la trajectoire, valider
  le catalogue et faire un dry-run avant toute ecriture.
- Pour les changements frontend, verifier le rendu public concerne.
- Mentionner clairement les validations lancees et leurs resultats.

## 16. Pieges connus

- DDEV utilise PHP 8.4 mais `composer.json` declare une plateforme PHP 8.3.
- L'absence d'un MCP dans la surface operateur courante n'implique pas son
  absence du runner Agency ; relire le registre de capacites.
- Les pages `service` ne portent pas directement les paragraphes
  `field_home_components`; les grilles sont enrichies via promotions.
- `node:page` a une meta description plus generique que `node:service`.
- Modifier les aliases peut casser le SEO, les hreflang et le Link Checker.
- Modifier les menus peut casser les traductions et le language switcher.
- `--prune=unpublish` est reserve aux cas maitrises ; ne jamais l'utiliser par
  habitude ni comme mecanisme de liberation d'un contenu ordinaire.

## 17. Interdictions importantes

- Ne pas modifier les menus.
- Ne pas modifier `system.site:page.front`.
- Ne pas modifier les workflows GitHub Actions sans ticket dedie.
- Ne pas modifier `scripts/deploy-production.sh`.
- Ne pas modifier les content types sans ticket dedie.
- Ne pas modifier les contenus YAML hors ticket de contenu explicite.
- Ne pas modifier les aliases hors ticket SEO/contenu explicite.
- Ne pas casser le moteur Governed Content ni la compatibilite Content Sync.
- Ne pas modifier la logique metier existante hors demande explicite.
- Ne jamais commiter de secret, token, cle ou fichier local sensible.
