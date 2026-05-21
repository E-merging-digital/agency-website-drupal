# Ticket 115 - Parcours de conversion et qualification commerciale

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/295

Date : 2026-05-21

## Objectif

Transformer le parcours visiteur en tunnel plus lisible :

1. comprendre l'offre ;
2. choisir un parcours ;
3. qualifier le besoin ;
4. contacter l'equipe avec un contexte utile.

Le ticket ne met pas en place de generation automatique de leads, de scoring, de
CRM, de devis automatique ou de backend Future AI. Le chatbot reste en mode
guide et `future_ai.enabled` reste a `false`.

## Audit CTA

Constats principaux :

- la homepage avait deja un CTA contact clair, mais le libelle restait tres
  general ;
- la page Services expliquait les expertises mais pouvait mieux pousser vers la
  qualification avant contact ;
- la page Contact contenait une intention de qualification, mais les
  informations utiles a fournir devaient etre plus explicites ;
- le chatbot orientait vers les bonnes pages, mais ne proposait pas encore un
  parcours dedie "qualifier mon projet" ;
- les avertissements sur les donnees sensibles etaient presents dans le cadre
  chatbot, mais pas assez visibles dans le parcours de conversion.

## Strategie CTA

La hierarchie retenue est en trois niveaux.

### Decouverte

Objectif : aider le visiteur a comprendre l'offre avant de contacter.

- Voir les expertises / View our expertise
- Voir les services / View services
- Voir des cas similaires / See similar cases
- Explorer IA & Drupal / Explore AI & Drupal

### Qualification

Objectif : preparer une demande exploitable sans rallonger le formulaire.

- Qualifier mon projet / Qualify my project
- Preparer le contexte, le type de besoin, l'existant, l'horizon et les
  contraintes
- Comprendre les options Drupal, web business, SEO/accessibilite ou IA encadree

### Conversion

Objectif : faire basculer vers un contact humain.

- Qualifier mon projet / Qualify my project
- Demander un cadrage / Request scoping
- Aller au contact / Go to contact

Le contact reste humain : aucune promesse de budget, delai ou faisabilite
definitive n'est donnee automatiquement.

## Parcours chatbot

Le nouveau parcours guide `qualify_project` propose une qualification legere
FR/EN :

- contexte du projet ;
- type de besoin ;
- etat du site existant ;
- horizon souhaite ;
- niveau de maturite ;
- contraintes principales ;
- rappel de ne pas envoyer de donnees sensibles.

Le widget affiche tous les parcours configures au lieu de limiter l'affichage
aux cinq premiers. Cette modification reste frontend et ne change pas le backend
chatbot.

## Page Contact

La page Contact rappelle maintenant les informations utiles a fournir :

- contexte projet ;
- type de besoin ;
- URL du site existant si disponible ;
- budget indicatif optionnel ;
- delais souhaites ;
- niveau de maturite ;
- contraintes et decisions deja prises.

Elle rappelle aussi de ne pas transmettre de mots de passe, secrets, donnees
sensibles, documents confidentiels ou donnees personnelles de tiers.

## Preparation Future AI

Les scenarios futurs restent documentes sans implementation backend :

- qualification ;
- brief ;
- recommandation ;
- synthese.

Ces scenarios devront rester limites aux contenus publics, explicites sur les
garde-fous et soumis a validation humaine avant toute proposition engageante.

## Perimetre confirme

Le ticket modifie uniquement la configuration guide du chatbot, le widget JS, les
contenus Content Sync homepage/services/contact et cette documentation. Il ne
modifie pas les menus, les aliases, `system.site:page.front`, les providers
OpenAI, le backend Future AI, les workflows ou le script de deploiement.
