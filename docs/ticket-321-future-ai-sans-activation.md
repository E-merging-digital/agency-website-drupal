# Ticket 321 - Couche Future AI sans activation

Ce ticket conserve la couche preparatoire Future AI du module
`emerging_digital_chatbot` sans permettre son execution reelle.

## Etat attendu

- Le widget public reste en mode guide.
- `future_ai.enabled` reste a `false` dans la configuration exportee.
- Aucune route publique de conversation IA n'est declaree.
- Aucun appel OpenAI ne doit etre possible via le service provider OpenAI.
- Le provider OpenAI reste present dans l'architecture, mais `isEnabled()`
  retourne `false` tant qu'un ticket d'activation dedie ne le deverrouille pas.
- Le contexte public est construit uniquement depuis des noeuds publies,
  traduits dans la langue demandee et accessibles via les chemins publics
  explicitement autorises.
- Les chemins techniques comme `/admin`, `/user`, `/system`, `/batch` et
  `/core`, ainsi que les chemins avec query string ou fragment, sont exclus du
  contexte.

## Garde-fous conserves

- Pas de secret dans la configuration exportee.
- Pas de stockage de conversation.
- Pas de tracking.
- Pas d'endpoint public.
- Pas de vector store, pas d'outil autonome, pas de `previous_response_id`.
- Les reponses Future AI serialisees restent controlees par les objets
  `FutureAiResponse`.
- Les erreurs provider ne doivent jamais exposer la cle, le message visiteur ou
  le contexte public.

## Activation ulterieure

Un futur ticket d'activation devra explicitement :

1. redefinir la politique d'activation du provider OpenAI ;
2. verifier la gestion des cles via Drupal Key ;
3. maintenir `store: false` dans le payload OpenAI ;
4. ajouter ou adapter les tests d'integration sans appel reseau reel ;
5. documenter les validations RGPD, securite et exploitation.
