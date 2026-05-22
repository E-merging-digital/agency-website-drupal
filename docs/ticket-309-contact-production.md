# Ticket 309 - Formulaire de contact production

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/309

Date : 2026-05-22

## Objectif

Finaliser le Webform `contact` pour un usage en production sans modifier les
menus, la homepage, Config Split, le deploiement ou le parcours de qualification
du ticket 120.

## Changements retenus

- Le message de confirmation est affiche en ligne dans une box visible apres
  soumission, au lieu de rediriger vers `<front>`.
- La validation HTML5 native reste active pour bloquer les champs obligatoires
  sans soumission serveur inutile.
- Les champs `name` et `email` restent obligatoires en FR et EN.
- Le handler de notification envoie les demandes a
  `contact@emergingdigital.be`.
- Le module contrib Honeypot `2.2.x` est ajoute et active.
- La protection Honeypot est limitee au Webform `contact` via
  `hook_form_alter()`.

## Choix anti-spam

Honeypot est retenu parce que sa branche `^2.2` est compatible Drupal 11 et
parce qu'il ajoute un champ cache que les visiteurs humains ne voient pas. Cette
approche est moins intrusive qu'un CAPTCHA et ne modifie pas le parcours public
du formulaire.

La restriction temporelle Honeypot est desactivee (`time_limit: 0`) pour eviter
d'introduire un delai minimal ou un effet de bord de cache sur la page Contact.
La protection appliquee est donc le champ Honeypot invisible, cible uniquement
sur le Webform `contact`.

## Accessibilite visuelle

La confirmation utilise la classe dediee `contact-confirmation`, avec un role
`status` et `aria-live` `polite`. Le theme deplace le retour Webform dans la
zone Contact, juste au-dessus du formulaire, puis declenche un scroll vers la
boite de confirmation. Le message est garde en session pour rester visible au
rechargement de la page.

La boite affiche une bordure visible, un fond vert accessible, une icone de
succes, un titre et un texte d'au moins 1rem pour ne pas dependre uniquement de
la couleur.

Les champs obligatoires sont indiques par un astérisque et une mention globale
visible. Le consentement RGPD garde une aide visible sous la case.

## Preservation du ticket 120

Le parcours de qualification reste intact :

- les parametres URL `type`, `source` et `context` ne sont pas modifies ;
- les ancres `#qualification-contact` et `#contact-form` restent gerees par les
  contenus et le theme existants ;
- le JavaScript de preselection et de resume contextuel n'est pas modifie ;
- aucun appel OpenAI, chatbot ou automatisation commerciale n'est ajoute.
