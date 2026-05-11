# Strategie pour les futures pages services SEO

Issue: https://github.com/E-merging-digital/agency-website-drupal/issues/219

## Decision recommandee

Les futures pages SEO `/creation-site-drupal`, `/refonte-site-drupal`,
`/migration-drupal`, `/maintenance-drupal` et `/audit-drupal` doivent reutiliser
le content type `service`, avec `agence-drupal-belgique` comme blueprint
editorial et technique.

Il ne faut pas creer de nouveau content type pour ce besoin, ni utiliser
`node:page` comme support principal des pages services SEO.

La strategie la plus sure est:

1. creer, dans un ticket futur dedie a la production de contenu, une entree
   Content Sync `node/service` par page SEO;
2. definir pour chaque entree un identifiant metier stable, des aliases FR/EN
   explicites et des champs traduits;
3. utiliser `field_short_description` comme source de la meta description et du
   Schema.org WebPage;
4. structurer `field_detailed_description` avec un contenu HTML editorial clair
   (`h2`, listes, liens internes);
5. utiliser les `promotions` Content Sync pour ajouter les cartes des nouvelles
   pages dans les grilles de services existantes;
6. garder la creation massive de contenu hors de ce ticket d'analyse.

## Constats

### Content types configures

Les content types presents en configuration sont:

| Content type | Usage attendu | Utilisation reelle locale |
| --- | --- | --- |
| `page` | Pages institutionnelles et pages assemblees par paragraphes | 8 noeuds, 16 traductions |
| `service` | Offres commerciales et pages services | 1 noeud, 2 traductions |
| `article` | Actualites / contenus editoriaux | 0 noeud |
| `ai_feature` | Fonctionnalites IA liees aux services | 0 noeud |
| `case_client` | Cas clients dedies | 0 noeud |

Les content types reellement utilises en contenu local sont donc `page` et
`service`.

### Content Sync

Le catalogue Content Sync contient actuellement:

| ID Content Sync | Bundle | Role |
| --- | --- | --- |
| `agence-drupal-belgique` | `service` | Page service SEO existante |
| `services` | `page` | Page hub services |
| `ia-drupal` | `page` | Page thematique |
| `cas-clients` | `page` | Page cas clients |
| `contact` | `page` | Page contact |
| `mentions-legales` | `page` | Page legale |
| `politique-confidentialite` | `page` | Page legale |
| `politique-cookies` | `page` | Page legale |
| `homepage` | `page` | Accueil |

Techniquement, le gestionnaire Content Sync accepte les bundles `service` et
`page` pour les noeuds. Les composants paragraphes sont appliques uniquement aux
entrees `page` via `field_home_components`. Les entrees `service` supportent les
champs du noeud et les `promotions`, qui injectent ou mettent a jour des cartes
dans les paragraphes `services` des pages cibles.

Les mappings persistants sont deja en place pour les noeuds et paragraphes
existants. La strategie future doit donc ajouter de nouveaux identifiants metier
sans modifier les UUID historiques ni les mappings existants.

## Analyse du modele agence-drupal-belgique

`agence-drupal-belgique` est un noeud `service` bilingue:

| Langue | Alias | Titre |
| --- | --- | --- |
| FR | `/agence-drupal-belgique` | Agence Drupal Belgique |
| EN | `/drupal-agency-belgium` | Drupal Agency Belgium |

Le modele repose sur:

- `field_short_description`: description courte obligatoire, traduisible,
  reutilisee par les metatags, Open Graph, Twitter Cards et Schema.org WebPage;
- `field_detailed_description`: contenu long HTML, traduisible, avec structure
  editoriale, listes, liens internes et maillage vers `/services` et
  `/ia-drupal`;
- `promotions`: cartes synchronisees vers la page Services et la homepage;
- aliases explicites dans le catalogue, avec `pathauto` desactive lors de la
  synchronisation;
- mapping Content Sync stable vers le noeud Drupal existant.

Ce modele est donc le meilleur blueprint actuel pour des landing pages services
SEO: il est simple, deja synchronise, compatible avec les metatags par bundle,
et ne demande pas de nouvelle architecture.

## Paragraphes reutilisables

Les paragraphes disponibles et utilises sont:

| Paragraphe | Utilisation actuelle | Reutilisation pertinente |
| --- | --- | --- |
| `hero` | Hero des pages assemblees | Pattern editorial pour les titres et introductions |
| `text_block` | Sections de texte, pages legales, contact | Pattern pour sections SEO redactionnelles |
| `services` | Grilles de services et cartes promotionnelles | Fortement pertinent pour le maillage interne |
| `cta` | Appels a l'action | Pertinent en fin de parcours |
| `trust_list` | Listes de benefices / preuves | Pertinent pour reassurance |
| `case_clients` | Synthese probleme / solution / resultat | Pertinent si des preuves projet sont ajoutees |
| `ai_features` | Grilles d'usages IA | Pertinent seulement pour les pages liees a l'IA |

Important: ces paragraphes sont aujourd'hui attachables a `node:page` via
`field_home_components`, pas a `node:service`. Ils doivent donc etre reutilises
comme patterns editoriaux et via les promotions dans les grilles, mais pas
forces dans les pages `service` sans ticket technique specifique.

Si les futures pages services doivent devenir de vraies pages composees avec
paragraphes, il faudra un ticket separe pour ajouter un champ de composants sur
`service` ou generaliser le champ existant, mettre a jour Content Sync, les
tests, les displays et le template Twig. Ce n'est pas necessaire pour la
premiere vague SEO.

## Patterns SEO existants

Les patterns deja presents sont:

- titre de noeud comme titre SEO;
- `field_short_description` comme meta description pour `service`;
- Schema.org `WebPage` pour `service` et `page`;
- sitemap active pour `node.service` et `node.page`;
- aliases FR/EN explicites et stables;
- contenu detaille structure avec titres, listes et liens internes;
- page hub `/services` avec grille de cartes;
- promotions Content Sync qui enrichissent les grilles sans ecraser les autres
  items;
- CTA vers `/contact`;
- maillage vers `/services`, `/ia-drupal`, `/cas-clients` et les pages services.

Le point faible actuel de `node:page` pour des pages SEO est la meta description
generique: `Page institutionnelle de [site:name].` Pour des pages d'intention
commerciale, `node:service` est donc plus adapte.

## Options evaluees

### Reutiliser `node:service`

Recommande.

Avantages:

- semantique coherente avec des offres Drupal;
- metatags et Schema.org bases sur `field_short_description`;
- sitemap deja configure;
- template Twig dedie deja present;
- Content Sync supporte deja ce bundle;
- blueprint `agence-drupal-belgique` deja valide;
- faible risque sur les menus, aliases, UUID et mappings.

Limite:

- pas de paragraphes attachables directement aujourd'hui.

Cette limite est acceptable pour les futures pages SEO si le contenu est
structure dans `field_detailed_description` et si les grilles de maillage sont
gerees via `promotions`.

### Reutiliser `node:page`

Non recommande pour les pages services SEO principales.

Avantages:

- page-builder existant avec paragraphes;
- rendu riche deja teste sur homepage, services, IA Drupal et cas clients.

Limites:

- metatags trop generiques pour le SEO transactionnel;
- semantique institutionnelle moins claire;
- risque de melanger pages hub/institutionnelles et landing pages services;
- Content Sync page remplace l'ordre des composants, ce qui demande plus de
  vigilance sur les UUID de paragraphes.

`node:page` doit rester le support des hubs, pages institutionnelles et pages
composees globales.

### Creer un nouveau type

Non recommande a ce stade.

Un nouveau type imposerait des champs, displays, pathauto, sitemap, metatags,
Schema.org, templates Twig, tests Content Sync et procedures de mapping
supplementaires. Le besoin actuel est couvert par `service`; creer un type
augmenterait la complexite sans gain clair.

### Reutiliser les paragraphes existants

Oui, mais de maniere ciblee.

Pour la premiere vague, les paragraphes doivent etre reutilises:

- comme patterns de structure editoriale;
- dans les pages hubs existantes via `promotions`;
- pour enrichir `/services` et la homepage avec des cartes vers les nouvelles
  pages.

La reutilisation directe des paragraphes dans les pages `service` doit attendre
un ticket technique si le besoin est confirme.

## Impacts

### SEO

`node:service` est le meilleur choix: chaque page peut avoir une description
courte unique, un contenu long cible, des liens internes et un alias exact.
Les futures pages doivent cibler une intention par URL et eviter les contenus
dupliques entre elles.

### Schema.org

Le projet utilise actuellement `schema_web_page_type: WebPage` pour `service`.
C'est suffisant pour la premiere iteration. Un enrichissement vers un schema
plus specifique de type service/offre peut etre traite plus tard, apres
validation de la strategie SEO et sans bloquer la creation des pages.

### Sitemap

`node.service` est deja indexe par Simple Sitemap. Les nouvelles pages services
seront incluses si elles sont publiees et traduites. Les aliases doivent rester
explicites dans Content Sync pour conserver les URL SEO demandees.

### Content Sync

Le modele `service` est deja supporte. Les nouvelles pages devront etre ajoutees
avec:

- un `id` stable;
- `entity_type: node`;
- `bundle: service`;
- `business_aliases` FR/EN;
- `translations` FR/EN;
- `field_short_description` et `field_detailed_description`;
- des `promotions` vers `/services` et eventuellement la homepage.

Il ne faut pas modifier les mappings existants ni recycler des UUID historiques.
Les nouveaux contenus recevront leurs propres mappings lors de l'application
Content Sync.

### Templates Twig

Le template `node--service.html.twig` suffit pour la premiere vague. Aucun
template Twig n'est requis dans ce ticket.

Si un champ de paragraphes est ajoute plus tard sur `service`, il faudra revoir
le template pour rendre les composants sans casser le rendu actuel de
`field_short_description`, `field_detailed_description` et
`field_related_ai_features`.

### Maintenabilite

Reutiliser `service` limite les changements et garde un seul modele pour les
landing pages services. Les pages hubs continuent d'etre gerees par `page` et
paragraphes.

### Evolutivite

La strategie reste evolutive:

- court terme: pages `service` simples, SEO, synchronisees;
- moyen terme: enrichissement du maillage via promotions;
- long terme: eventuel champ de composants pour `service` si les pages doivent
  devenir modulaires.

## Strategie proposee pour les futures pages

| Page FR | Alias EN suggere | Type | Blueprint | Role |
| --- | --- | --- | --- | --- |
| `/creation-site-drupal` | `/drupal-website-creation` | `service` | `agence-drupal-belgique` | Acquisition creation Drupal |
| `/refonte-site-drupal` | `/drupal-website-redesign` | `service` | `agence-drupal-belgique` | Acquisition refonte |
| `/migration-drupal` | `/drupal-migration` | `service` | `agence-drupal-belgique` | Acquisition migration |
| `/maintenance-drupal` | `/drupal-maintenance` | `service` | `agence-drupal-belgique` | Acquisition maintenance |
| `/audit-drupal` | `/drupal-audit` | `service` | `agence-drupal-belgique` | Acquisition audit |

Pour chaque page:

- definir une intention SEO unique;
- rediger une description courte differenciee;
- structurer le detail avec un H2 principal, une liste de prestations, un
  paragraphe de contexte, un CTA et 2 a 4 liens internes;
- ajouter une carte dans la grille `/services`;
- ajouter eventuellement une carte dans la homepage si la priorite commerciale
  le justifie;
- conserver les aliases explicites FR/EN dans Content Sync;
- ne pas modifier les menus dans le meme ticket.

## Conclusion

Le modele `agence-drupal-belgique` doit devenir le blueprint principal des
futures pages services SEO. Il combine le bon content type, les bons metatags,
le sitemap, les aliases bilingues, le maillage interne et le mecanisme
Content Sync deja en production.

La creation d'un nouveau type ou le detournement de `node:page` ajouterait de la
complexite et du risque sans benefice suffisant. La bonne trajectoire est donc
de standardiser les nouvelles landing pages sur `node:service`, puis d'ouvrir un
ticket separe uniquement si l'on veut rendre les pages services modulaires par
paragraphes.
