# Agency #1417 — Correspondance MVP NL-BE dans Drupal (R3, SOURCE_ONLY)

Autorité R3 : https://github.com/E-merging-digital/agency-website-drupal/issues/1417#issuecomment-6887758295
Texte provisoire non approuvé : https://github.com/E-merging-digital/agency-website-drupal/issues/1417#issuecomment-6887681547

| Élément | Identité vérifiée en source | Éditeur Drupal natif — travaux différés |
| --- | --- | --- |
| Accueil | system.site page.front=/node/5 ; révision runtime non prouvée | Traduire le nœud existant et ses Paragraphs Hero / CTA / ALT ; route /nl seulement après contenu NL réel. |
| Services | Route publique /fr/services ; ID/révision non prouvés ; Paragraph services : field_heading, field_text, field_items | Identifier le nœud dans Drupal, traduire ses Paragraphs/cartes ; alias candidat /diensten et aucun lien vers sous-page NL non traduite. |
| Contact | UUID dans le thème d962ec05-25ec-4446-b645-0959a57964e7 ; ID/révision runtime non prouvés | Traduire ce nœud ; vérifier rendu Contact V3, Webform contact, messages et consentement. |
| Formulaire | config/sync/webform.webform.contact.yml et override NL candidat | Installer en DEV isolé autorisé via Config Translation ; vérifier les validations natives ; aucune modification des handlers e-mail. |
| Menu et footer | menu_link_content.main/footer traduisibles, blocs footer versionnés | Traduire les liens Home / Diensten / Contact, puis liens légaux uniquement vers pages NL réellement traduites. Les labels footer ont un override NL source. |
| Pages légales | Trois pages FR publiques ; IDs/révisions inconnus | Traduire leurs entités existantes ; alias candidats /privacyverklaring, /cookiebeleid, /wettelijke-vermeldingen ; relecture juridique obligatoire. |
| SEO | Metatag page/front, Pathauto existants | En Drupal : titre, résumé, alias, canonical, hreflang et sitemap ; ne pas supposer une traduction ou indexer des pages NL non réelles. |

NL_VISIBILITY_HARD_GATE=ACTIVE. Preuve Drupal DEV isolé R4 : avec le préfixe `nl: nl`, `/nl` sert l'accueil français sous `lang="nl"` (HTTP 200) alors que le nœud 5 n'a aucune traduction NL. Sans préfixe NL dans `language.negotiation`, `/nl`, `/nl/node/5` et `/nl/node/2` (y compris avec une traduction NL du nœud 2) répondent 404, tandis que `/fr` et `/en` restent 200 ; aucun lien NL n'apparaît dans le sélecteur rendu FR. Le préfixe `/nl` reste donc absent du candidat source jusqu'à une autorisation distincte après création/relecture des contenus natifs, tests SEO/menu/Webform et vérification des routes. Aucune activation, fusion ni publication implicite.

Relecture linguistique NL-BE (kmo, vzw, AVG, CTA) et juridique (consentement, durée de conservation annoncée 12 mois, RGPD, cookies/Google Maps) en attente. Texte CANDIDATE_FOR_REVIEW. Default FR et lock FR/true inchangés. Aucun déploiement ni activation.

## R7 — Matérialisation des brouillons NL-BE en DEV isolé (15/11/2026)

Autorité : [Agency #1417 R7](https://github.com/E-merging-digital/agency-website-drupal/issues/1417#issuecomment-6902398159). **Brouillons DEV uniquement, sans validation linguistique ni juridique.** Les écritures dans la DB DEV ne sont pas transportées par cette PR ; ne pas confondre sources versionnées et état Drupal des éditeurs.

| Surface | Node ID / UUID DEV | Révision DEV | Paragraphs traduits, révisions DEV | Alias NL candidat |
| --- | --- | --- | --- | --- |
| Accueil | 5 / `7b8d9926-e015-457f-9da3-562439b962a7` | 9 | 1:33, 8:37, 15:40, 21:44, 25:47, 26:48, 29:50 | `<front>` |
| Services | 2 / `d6e1876f-8e32-4f75-b6bb-946de5f5afdb` | 10 | 6:35, 7:36, 12:39, 19:43, 23:45 | `/diensten` |
| Contact V3 | 3 / `d962ec05-25ec-4446-b645-0959a57964e7` | 11 | 4:34, 10:38, 16:41, 18:42, 24:46, 27:49 | `/contact` |
| Mentions légales | 6 / `aa527cd3-b88c-4f6c-810e-81c5d42decbb` | 12 | 30:51 | `/wettelijke-vermeldingen` |
| Confidentialité | 7 / `8fc191d9-e3e5-41b2-87fb-db8f6b749add` | 13 | 31:52 | `/privacyverklaring` |
| Cookies | 8 / `0215dbcc-bceb-4de5-8460-c64dd3bb85c5` | 14 | 32:53 | `/cookiebeleid` |

La version NL des six nœuds a `status=0` (brouillon). Les versions FR/EN et leurs champs, statuts et références restent inchangés selon les contrôles Drupal natifs. La traduction NL de Services issue du test R4 a été remplacée par le candidat éditorial. Les trois identités légales proviennent **uniquement** d'un apply Governed Content ciblé, `validate` + `--all --dry-run` + trois dry-run ciblés PASS, après snapshot DDEV natif `agency-1417-r7-preseed`. Snapshot supplémentaire `agency-1417-r7-before-nl` pour revenir aux trois pages légales FR/EN sans perdre le seed.

Relecture dans l'éditeur Drupal du DEV local : `/node/5/translations`, `/node/2/translations`, `/node/3/translations`, puis `/node/6/translations`, `/node/7/translations`, `/node/8/translations` (connexion éditeur requise). Les Paragraphs NL sont les traductions des **entités existantes**, non de nouveaux Paragraphs ; des rendus Drupal natifs de leurs titres ont été contrôlés. Le gabarit de héros du thème dispose désormais d'une branche NL explicite, sans changer les branches FR/EN. Les menus `menu_link_content` n'ont pas été modifiés ; navigation MVP limitée aux destinations existantes Home → Diensten → Contact. Un défaut du sélecteur sur la front a été reproduit après création des traductions NL : il annonçait `Néerlandais` avec `href="/"` (sans préfixe NL). Le `LanguageSwitcherBlock` lit désormais la configuration native de négociation et ne propose pas NL tant que `url.prefixes.nl` n'est pas exactement `nl`, avec dépendance de cache sur cette configuration. Le contrôle HTTP FR après cache rebuild confirme l'absence de lien/hreflang NL.

Les trois traductions légales NL candidates sont déclarées dans **les mêmes entrées Governed Content** déjà gouvernées : `content_sync/catalog.yml` et `content_sync/node/{mentions-legales,politique-confidentialite,politique-cookies}.yml`. `emerging:governed-content:validate` + dry-run ont passé les contrôles après cet ajout ; **aucun apply supplémentaire** de la nouvelle source NL, qui ne doit pas être promue avant relecture humaine (le moteur Governed Content publierait normalement les traductions déclarées).

Webform `contact` : les identifiants techniques, `#required`, `#type` et handlers restent ceux de FR/EN ; version NL config existante vérifiée (champs et consentement obligatoires, lien vers `/nl/privacyverklaring`, confirmation « Bedankt … »). Validation d'une soumission fictive **non enregistrée** sans appel de handler ni SMTP. Ceci n'est pas un test de livraison mail ni une approbation du consentement. Le lien NL du consentement est **volontairement inaccessible** jusqu'à une activation ultérieure autorisée.

**Visibilité fermée :** `language.negotiation` ne configure toujours que FR/EN ; HTTP DEV `/nl`, `/nl/diensten`, `/nl/contact` et les trois routes légales NL = 404 ; `/fr` et `/en` = 200. Ne pas annoncer un parcours NL public ni une validation responsive sur navigateur ; l'examen visuel/mobile, SEO canonicals/hreflang/sitemap, les pages non traduites, la livraison Webform et le GO linguistique/juridique restent des portes **post-brouillon**.

**Questions de relecture humaine :** NL-BE commercial : `kmo`, `vzw`, `AVG`, CTA, positionnement Drupal/AI, titres/ALT ; juridique : base légale du consentement, conservation de 12 mois, coordonnées/siège/hébergement, consentement cookies et Google Maps. `NL_VISIBILITY_HARD_GATE=ACTIVE`, `HUMAN_NL_BE_REVIEW=PENDING`, `HUMAN_LEGAL_REVIEW=PENDING`, aucun READY/MERGE/PREPROD/PROD.
