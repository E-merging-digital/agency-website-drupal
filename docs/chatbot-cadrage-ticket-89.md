# Ticket 89 - Cadrage chatbot contact et qualification légère

## Objectif

Préparer l'intégration future d'un chatbot pour le site E-merging Digital,
orienté prise de contact, qualification légère et orientation vers les bonnes
pages Drupal.

Le chatbot doit rester un assistant d'accueil et d'orientation. Il ne doit pas
devenir un agent commercial autonome, produire des devis, promettre des
prestations, ni collecter plus de données que nécessaire.

## Positionnement recommandé

La meilleure approche pour le futur Ticket 90 est un widget conversationnel
simple, géré côté Drupal, avec une couche IA optionnelle et strictement
encadrée côté serveur.

Recommandation cible :

1. Phase 1 : widget Drupal léger, parcours guidés, contexte statique, CTA vers
   le formulaire de contact et/ou prise de rendez-vous.
2. Phase 2 : enrichissement IA via API OpenAI, avec contexte limité aux pages
   publiques validées.
3. Phase 3 : mini-RAG, analytics, qualification plus fine et éventuelle
   connexion CRM, sans automatisation commerciale autonome.

Cette approche est plus cohérente avec le projet qu'un widget SaaS externe
intégré en iframe : elle garde la maîtrise UX, RGPD, multilingue, SEO et
maintenance Drupal.

## Pages sources prioritaires

Les sources doivent rester limitées aux contenus publics, stables et utiles à
la conversion :

- Homepage : `/fr/accueil`, `/en/home`
- Services : `/fr/services`, `/en/services`
- IA & Drupal : `/fr/ia-drupal`, `/en/ai-drupal`
- Contact : `/fr/contact`, `/en/contact`
- Landing pages services :
  - `/fr/agence-drupal-belgique`, `/en/drupal-agency-belgium`
  - `/fr/creation-site-drupal`, `/en/drupal-website-creation`
  - `/fr/maintenance-drupal`, `/en/drupal-maintenance`
  - `/fr/migration-drupal`, `/en/drupal-migration`
  - `/fr/refonte-site-drupal`, `/en/drupal-website-redesign`
  - `/fr/audit-drupal`, `/en/drupal-audit`
  - `/fr/accessibilite-seo-optimisation`, `/en/ai-accessibility-seo-optimization`
  - `/fr/ia-integree`, `/en/integrated-ai`
- Pages AI Feature :
  - `/fr/ia-drupal/redaction-assistee`, `/en/ai-drupal/assisted-writing`
  - `/fr/ia-drupal/correction-editoriale`, `/en/ai-drupal/editorial-review`
  - `/fr/ia-drupal/traduction-fr-en`, `/en/ai-drupal/fr-en-translation`
  - `/fr/ia-drupal/resumes-tags-structure`, `/en/ai-drupal/summaries-tags-structure`
  - `/fr/ia-drupal/seo-liens-internes`, `/en/ai-drupal/seo-internal-links`
  - `/fr/ia-drupal/gouvernance-validation`, `/en/ai-drupal/governance-approval`
- Pages légales utiles à la transparence :
  - `/fr/politique-de-confidentialite`, `/en/privacy-policy`
  - `/fr/politique-de-cookies`, `/en/cookie-policy`

Ces pages sont déjà déclarées dans le catalogue Content Sync. Le ticket 90 ne
doit pas modifier leurs aliases, UUID, mappings ou contenus YAML.

## Objectifs fonctionnels

Le chatbot doit aider le visiteur à :

- comprendre rapidement si l'agence peut l'aider ;
- identifier le type de besoin : création, refonte, migration, maintenance,
  SEO, accessibilité, IA intégrée ;
- rejoindre la bonne page service ou IA ;
- formuler une demande de contact plus claire ;
- basculer vers un humain dès que la demande devient spécifique.

Le chatbot peut poser quelques questions simples :

- type d'organisation : PME, ASBL, institution, autre ;
- type de projet ;
- existence d'un site Drupal actuel ;
- langue préférée ;
- horizon approximatif du projet ;
- moyen de contact souhaité.

Il doit toujours proposer une sortie claire :

- "Continuer vers le formulaire de contact" ;
- "Voir les services Drupal" ;
- "Découvrir IA & Drupal" ;
- "Demander un échange humain".

## Comportements interdits

Le chatbot ne doit pas :

- annoncer qu'une prestation est acceptée ;
- donner un prix, une fourchette de prix ou une estimation automatique ;
- promettre un délai contractuel ;
- valider une faisabilité technique définitive ;
- donner un avis juridique, fiscal ou contractuel ;
- collecter des données sensibles ;
- insister si le visiteur refuse de laisser ses coordonnées ;
- masquer qu'il s'agit d'une assistance automatisée.

Réponse de garde-fou recommandée :

> Je peux vous aider à clarifier votre besoin et vous orienter vers la bonne
> page. Pour un avis engageant, un budget ou une proposition, l'équipe doit
> reprendre contact avec vous.

## Données autorisées

Collecte minimale autorisée, uniquement quand le visiteur souhaite être
recontacté :

- nom ;
- email ;
- société ou organisation ;
- type d'organisation ;
- type de projet ;
- besoin métier résumé ;
- URL du site existant, si le visiteur la fournit volontairement ;
- langue préférée.

Ces données doivent être transférées vers le formulaire contact ou une future
submission Webform, pas stockées dans un journal conversationnel long par
défaut.

## Données à éviter

Le chatbot doit éviter ou refuser :

- données de santé, opinions politiques, convictions religieuses ;
- informations financières détaillées ;
- mots de passe, accès, tokens, secrets ;
- données personnelles de tiers ;
- documents confidentiels ;
- budget détaillé si cela n'est pas strictement nécessaire au premier contact ;
- toute collecte "au cas où".

## Stratégie RGPD

Principes à retenir pour le Ticket 90 :

- afficher un court message de transparence avant la première saisie libre ;
- expliquer que les messages peuvent être traités par un service d'IA si l'IA
  est activée ;
- ne déclencher la collecte de coordonnées qu'après intention explicite ;
- fournir un lien vers la politique de confidentialité ;
- ne pas activer d'analytics conversationnel nominatif sans base légale claire ;
- séparer les événements analytics anonymes des données de contact ;
- limiter la conservation des conversations ;
- permettre la suppression des données liées à une demande.

Le formulaire de contact contient déjà un consentement RGPD. Le chatbot doit
s'aligner sur cette logique au lieu d'introduire un second mécanisme opaque.

## Architecture Drupal cible

Module recommandé pour le Ticket 90 : `emerging_digital_chatbot`.

Responsabilités du module :

- exposer un block Drupal ou un render element pour le widget ;
- fournir une library JS/CSS dédiée, sans modifier les menus ;
- gérer la configuration du widget :
  - activation globale ;
  - pages autorisées ;
  - langue ;
  - messages FR/EN ;
  - mode guide ou mode IA ;
  - liens de sortie ;
- fournir une route serveur pour les appels conversationnels si l'IA est
  activée ;
- appliquer rate limiting, CSRF/session protection et sanitation des entrées ;
- journaliser seulement les erreurs techniques utiles, sans stocker par défaut
  les conversations complètes.

Intégration frontend :

- bouton flottant discret en bas à droite sur desktop ;
- bouton compact et non intrusif sur mobile ;
- panneau accessible au clavier ;
- focus trap dans le panneau ouvert ;
- fermeture visible ;
- `aria-live` prudent pour les nouveaux messages ;
- respect du contraste et des tailles tactiles ;
- aucun recouvrement permanent du formulaire de contact ou du language
  switcher.

Le thème `emerging_digital` doit seulement recevoir les styles nécessaires au
rendu premium si le module ne les porte pas lui-même. La logique métier reste
côté module.

## Widget externe vs module Drupal

| Option | Avantages | Risques | Verdict |
| --- | --- | --- | --- |
| Widget SaaS externe | rapide à tester, back-office fourni | dépendance fournisseur, RGPD plus complexe, UX moins maîtrisée, multilingue parfois rigide | utile seulement pour prototype non stratégique |
| Module Drupal sans IA | maîtrise totale, RGPD simple, stable, peu coûteux | moins conversationnel, pas de réponse libre riche | meilleur MVP si l'objectif principal est contact |
| Drupal + API OpenAI | UX plus naturelle, évolutif, contexte contrôlé | coût API, garde-fous à tester, données envoyées à un tiers | cible recommandée après MVP guide |
| Drupal + mini-RAG | réponses ancrées dans les pages publiques, scalable | indexation et fraîcheur à maintenir | phase 2 pertinente |
| Agent autonome | puissant pour workflows complexes | trop risqué pour conversion simple, gouvernance lourde | hors périmètre |

## Options OpenAI

Pour un nouveau projet, l'API à privilégier est la Responses API. La
documentation officielle OpenAI la présente comme l'interface recommandée pour
les nouveaux projets et comme une base adaptée aux interactions avec outils,
contexte et état conversationnel.

Options pertinentes :

- Responses API : génération de réponses, conversation courte, instructions de
  garde-fou, sortie structurée.
- Function calling : appeler du code Drupal contrôlé, par exemple générer une
  synthèse de contact ou proposer un lien interne.
- File search / vector stores : base possible pour un mini-RAG sur les pages
  publiques validées.
- Agents SDK : à réserver à une phase avancée si plusieurs outils, traces ou
  workflows coordonnés deviennent nécessaires.

Option non recommandée en phase 1 :

- assistant agentique autonome avec outils multiples. Le besoin actuel est une
  aide à la conversion, pas une automatisation commerciale.

Points RGPD OpenAI à cadrer avant production :

- les données envoyées à l'API ne sont pas utilisées pour entraîner les modèles
  sauf opt-in, selon la documentation OpenAI ;
- les logs d'abuse monitoring peuvent être conservés jusqu'à 30 jours par
  défaut ;
- les options de rétention, zero data retention ou data residency doivent être
  vérifiées contractuellement avant d'envoyer des données personnelles
  identifiantes.

Sources officielles consultées :

- https://platform.openai.com/docs/guides/responses-vs-chat-completions
- https://platform.openai.com/docs/api-reference/responses
- https://platform.openai.com/docs/guides/tools-file-search
- https://platform.openai.com/docs/api-reference/vector-stores
- https://platform.openai.com/docs/guides/retrieval
- https://platform.openai.com/docs/guides/agents-sdk
- https://platform.openai.com/docs/guides/your-data

## Approche RAG simple

Le RAG doit rester simple et éditorialement maîtrisé.

Phase 2 recommandée :

1. Générer un corpus public à partir de pages Drupal publiées et validées.
2. Inclure uniquement :
   - titre ;
   - alias ;
   - langue ;
   - type de contenu ;
   - résumé court ;
   - extrait nettoyé du contenu long ;
   - liens internes utiles.
3. Exclure :
   - submissions Webform ;
   - conversations ;
   - données personnelles ;
   - brouillons ;
   - contenus non publiés.
4. Indexer par langue.
5. Retourner des réponses courtes avec liens vers les pages sources.

Deux variantes techniques sont possibles :

- RAG Drupal-side : index JSON/DB local généré depuis les nodes publiés, puis
  contexte injecté dans la requête IA. Plus simple à auditer.
- RAG OpenAI file search : vector store OpenAI alimenté par exports publics.
  Plus évolutif, mais gouvernance externe et synchronisation à cadrer.

Pour ce projet, commencer par un RAG Drupal-side est plus maintenable. OpenAI
file search devient intéressant si le volume de contenu augmente ou si la
qualité de retrieval devient un sujet central.

## Stratégie UX

Ton recommandé :

- calme ;
- professionnel ;
- direct ;
- jamais trop familier ;
- jamais insistant ;
- orienté clarification.

Exemples de messages :

- "Bonjour, je peux vous aider à trouver le bon point d'entrée pour votre
  projet Drupal ou IA."
- "Je peux clarifier votre besoin, puis vous proposer la page utile ou le
  formulaire de contact."
- "Pour un budget ou une proposition engageante, l'équipe vous recontactera."

Parcours prioritaires :

1. "J'ai un projet Drupal"
2. "Je veux moderniser un site existant"
3. "Je cherche de l'aide sur IA & Drupal"
4. "Je veux améliorer SEO / accessibilité / performance"
5. "Je souhaite vous contacter"

Le chatbot doit favoriser des boutons de choix rapides avant la saisie libre.
La saisie libre est utile, mais elle doit rester secondaire pour réduire la
collecte inutile et garder une expérience premium.

## Analytics et conversion

Mesures utiles, sans données personnelles :

- ouverture du widget ;
- clic sur un choix rapide ;
- clic vers `/fr/contact` ou `/en/contact` ;
- clic vers une page service ;
- abandon après ouverture ;
- transfert vers formulaire ;
- langue de session.

Mesures à éviter :

- contenu complet des messages dans GA4 ;
- email, nom ou société dans les events ;
- scoring automatique nominatif ;
- profiling individuel.

L'intégration analytics doit rester compatible avec la stratégie existante :
pas de script en dur dans Twig, pas de tracking custom non documenté, et
configuration via Drupal/Google Tag quand applicable.

## Déploiement progressif

### Phase 1 - MVP guide

Objectif : améliorer l'orientation et la prise de contact sans risque IA.

- widget Drupal ;
- choix rapides ;
- textes FR/EN configurés ;
- CTA vers contact ;
- pré-remplissage possible d'un message de contact ;
- aucune décision commerciale automatisée ;
- pas de stockage conversationnel long ;
- tests accessibilité et responsive.

### Phase 2 - IA encadrée

Objectif : réponses courtes et contextualisées sur les pages publiques.

- endpoint serveur Drupal ;
- OpenAI Responses API ;
- prompt système strict ;
- liste blanche de pages sources ;
- garde-fous commerciaux ;
- logs techniques minimaux ;
- monitoring coûts/erreurs ;
- fallback vers parcours guide si l'IA est indisponible.

### Phase 3 - Mini-RAG et intégrations

Objectif : enrichir la qualification sans transformer le chatbot en commercial
autonome.

- index public par langue ;
- retrieval limité aux contenus publiés ;
- analytics de conversion ;
- intégration CRM éventuelle ;
- synthèse de lead relue par humain ;
- workflows internes maîtrisés.

## Recommandation finale

Pour le Ticket 90, implémenter un module Drupal léger de chatbot guidé, avec
une architecture préparée pour OpenAI mais sans dépendance IA obligatoire au
premier déploiement.

Le MVP doit prouver la valeur conversionnelle : meilleure orientation,
meilleure qualité des demandes, accès plus rapide au contact. L'IA doit arriver
ensuite comme une couche d'assistance contrôlée, pas comme le coeur du produit.

Architecture cible :

- Drupal reste le point de contrôle UX, RGPD, multilingue et routing.
- Le widget est un composant frontend sobre porté par une library dédiée.
- Les contenus sources viennent des pages publiques déjà structurées.
- Les données personnelles passent par Webform ou un flux de contact explicite.
- OpenAI est appelé uniquement côté serveur, avec prompts, contexte et
  garde-fous versionnés.
- Le RAG reste limité aux contenus publics et publiés.

Cette trajectoire est sobre, premium, maintenable et compatible avec une agence
Drupal + IA haut de gamme : elle améliore l'UX sans promettre plus que ce que
le site peut tenir.

## Préparation du Ticket 90

Périmètre proposé :

- créer `web/modules/custom/emerging_digital_chatbot` ;
- ajouter config install + schema ;
- ajouter block/widget activable ;
- ajouter library JS/CSS dédiée ;
- ajouter textes FR/EN ;
- ajouter parcours guidés ;
- ajouter CTA vers contact et pages sources ;
- ajouter garde-fous UX/RGPD ;
- ajouter tests simples si le module expose une logique PHP ;
- documenter la configuration.

Hors périmètre Ticket 90 :

- RAG complet ;
- CRM ;
- scoring ;
- devis automatique ;
- agent autonome ;
- stockage conversationnel avancé ;
- fine-tuning ;
- modification des menus ;
- modification Content Sync ;
- modification aliases ;
- modification `system.site:page.front`.

Définition de terminé proposée :

- widget visible et accessible ;
- parcours FR/EN disponibles ;
- CTA contact opérationnel ;
- données personnelles minimisées ;
- aucun prix ou engagement automatique ;
- fallback sans IA disponible ;
- configuration exportable ;
- validations projet lancées.
