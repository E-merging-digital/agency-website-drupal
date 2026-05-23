# Chatbot MVP non IA

Le chatbot MVP est un assistant de qualification local et deterministe. Il
oriente le visiteur vers des services ou un contact humain a partir de choix
guides.

## Perimetre

- aucun appel OpenAI ;
- aucun appel HTTP externe ;
- aucune dependance JavaScript externe ;
- aucun stockage conversationnel ;
- aucun cookie specifique ;
- aucun tracking ;
- aucune entite Drupal creee par le parcours visiteur.

## Architecture

Le module `emerging_digital_chatbot` expose un bloc Drupal place par
configuration. La logique metier est isolee dans `QualificationEngine`, qui
normalise l'arbre de decision et rejette les CTA externes. Le frontend lit un
payload Drupal deja rendu dans `drupalSettings` et gere l'ouverture, la
fermeture, le focus, le clavier, les choix et les CTA sans appel reseau.

## Internationalisation

Les contenus FR/EN sont configures dans
`emerging_digital_chatbot.settings.yml`. Les chaines statiques du bloc passent
par l'API de traduction Drupal.

## Evolution Future AI

La couche preparatoire Future AI est conservee dans le code : contexte public
sanitise, interfaces gateway/provider, garde d'environnement, objets de reponse
et tests Kernel. Elle reste desactivee par configuration et n'est pas reliee au
widget public.

Le MVP ne declare plus de route publique de conversation et ne rend aucun
endpoint IA dans `drupalSettings`. Les valeurs de configuration Future AI
restent des placeholders non secrets pour un ticket ulterieur ; elles ne
suffisent pas a declencher un appel externe depuis le parcours visiteur.
