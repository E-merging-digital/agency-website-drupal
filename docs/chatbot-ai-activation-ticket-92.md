# Ticket 92 - Activation IA encadree du chatbot

## Objectif

Ce ticket prepare l'integration OpenAI du chatbot Drupal sans introduire
d'agent autonome, de memoire conversationnelle persistante, de RAG complet ni
de dependance IA obligatoire.

Le mode exporte reste `guide` et `future_ai.enabled: false`. Drupal conserve le
controle de l'UX, de la securite, des prompts, du contexte public, du fallback,
du multilingue et de la politique RGPD.

## Architecture

Flux prepare :

1. Frontend Drupal.
2. Endpoint POST `/api/emerging-digital-chatbot/conversation`.
3. Controle Drupal : CSRF, flood limit, sanitation, validation du mode.
4. Abstraction `FutureAiGatewayInterface`.
5. Provider `OpenAiResponsesGateway`.
6. OpenAI Responses API, uniquement cote serveur.
7. Fallback local `NullFutureAiGateway` si l'IA est inactive, indisponible ou
   jugee risquee.

Le provider OpenAI n'utilise pas d'outil, pas de `previous_response_id`, pas de
conversation OpenAI et transmet `store: false`. Le chatbot ne devient donc pas
un agent autonome.

## Configuration

Configuration exportable : `emerging_digital_chatbot.settings`.

Champs principaux :

- `mode`: `guide` ou `ai`.
- `future_ai.enabled`: activation effective de la couche IA.
- `future_ai.endpoint`: endpoint Responses API.
- `future_ai.model`: modele OpenAI.
- `future_ai.temperature`: temperature basse pour limiter la variabilite.
- `future_ai.max_output_tokens`: budget de reponse court.
- `future_ai.timeout_seconds`: timeout provider.
- `future_ai.security.max_input_chars`: limite de saisie visiteur.
- `future_ai.security.max_context_chars`: limite du contexte public prepare.
- `future_ai.security.rate_limit`: limitation Flood API.
- `future_ai.prompts.fr.system` et `future_ai.prompts.en.system`: prompts
  systeme versionnes.
- `future_ai.context.allowed_public_paths`: structure future mini-RAG limitee
  aux pages publiques.

Pour activer reellement l'IA, `mode` doit valoir `ai` et
`future_ai.enabled` doit valoir `true`. Sinon, le endpoint repond en fallback
guide.

## Cle API

La cle API ne doit jamais etre commitee ni exportee en configuration.

Ordre de resolution :

1. configuration du provider `ai_provider_openai` via le module Key ;
2. Key Drupal declaree dans `future_ai.openai_key_id` ;
3. `$settings['emerging_digital_chatbot.openai_api_key']` ;
4. variable `EMERGING_DIGITAL_CHATBOT_OPENAI_API_KEY` ;
5. variable `OPENAI_API_KEY`.

## Garde-fous

Le chatbot IA ne doit jamais :

- donner un prix, devis ou budget ;
- promettre un delai ;
- valider une faisabilite finale ;
- accepter commercialement un projet ;
- donner un avis juridique, medical, financier ou contractuel ;
- demander ou transmettre des donnees sensibles ;
- masquer qu'il s'agit d'une assistance automatisee.

La sanitation supprime les champs non autorises, limite les longueurs et bloque
les messages contenant des indices evidents de secrets, emails, telephones,
cartes, donnees nationales ou donnees medicales. Dans ces cas, aucun appel
OpenAI n'est effectue.

## Donnees transmises

Donnees minimales autorisees, apres sanitation :

- langue ;
- message general ;
- besoin general ;
- type de projet ;
- type d'organisation ;
- URL publique volontairement fournie.

Ne sont pas transmis :

- conversations completes persistantes ;
- submissions Webform ;
- contenus admin ;
- brouillons ;
- fichiers prives ;
- mots de passe, tokens ou secrets ;
- donnees sensibles ou de tiers.

## Fallback

Le fallback est immediat et non bloquant. Il s'applique si :

- le mode guide est actif ;
- l'IA est desactivee ;
- la cle API est absente ;
- le payload est invalide ;
- la sanitation bloque l'entree ;
- le rate limit est atteint ;
- l'API repond vide, en erreur ou hors timeout ;
- une reponse provider franchit les garde-fous commerciaux.

Message de garde-fou FR attendu :

> Je peux vous aider a clarifier votre besoin et vous orienter vers la bonne
> page. Pour une proposition engageante, l'equipe doit reprendre contact avec
> vous.

## RAG futur

Le RAG complet reste hors perimetre. La structure prepare seulement un profil
`public_pages_v1`, une liste de chemins publics FR/EN et un provider
`PublicAiContextProvider`.

Une phase ulterieure pourra remplacer cette liste par un contexte public genere
depuis des nodes publies et valides, sans inclure d'admin, de brouillons, de
submissions, de conversations ni de donnees personnelles.
