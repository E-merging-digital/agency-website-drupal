# Emerging Digital Chatbot

MVP de chatbot de qualification local pour le site E-merging Digital.

Le module fournit un widget accessible base sur un arbre de decision
configure dans `emerging_digital_chatbot.settings`. Il ne fait aucun appel
HTTP externe, ne contacte aucun service d'IA, ne stocke aucune conversation,
ne cree aucune entite metier, ne pose aucun cookie et n'ajoute aucun tracking.

## Architecture

- `ChatbotConfig` lit la configuration Drupal et verifie les conditions
  d'affichage.
- `QualificationEngine` normalise les etapes et garde uniquement des CTA
  internes.
- `ChatbotBlock` separe le rendu Drupal de la logique metier.
- `chatbot-widget.js` gere uniquement l'interaction locale dans le navigateur.
- `chatbot-widget.css` porte le rendu responsive.

## Configuration

Les libelles, messages, etapes et CTA FR/EN sont dans :

`config/install/emerging_digital_chatbot.settings.yml`

La meme configuration est exportee dans :

`config/sync/emerging_digital_chatbot.settings.yml`

La couche preparatoire Future AI reste presente pour un ticket ulterieur :
interfaces gateway/provider, garde d'environnement, contexte public sanitise et
objets de reponse. Elle est desactivee par configuration, le provider OpenAI
reste verrouille cote service, elle n'est pas exposee par le widget public et
aucune route publique de conversation n'est declaree dans le MVP.

La note d'exploitation liee au verrouillage est documentee dans
`docs/ticket-321-future-ai-sans-activation.md`.

## Tests

Le test fonctionnel principal est :

`web/modules/custom/agency_project_tests/tests/src/Functional/ChatbotMvpTest.php`
