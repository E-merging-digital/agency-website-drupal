# Ticket 120 - Qualification contact sans IA

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/307

Date : 2026-05-22

## Objectif

Structurer un parcours de qualification entre les pages Service, AI Feature,
Cas client et Contact, sans appel OpenAI, sans chatbot et sans automatisation
commerciale.

## Parcours retenu

Les pages de detail ajoutent un CTA vers le formulaire Contact avec des
parametres URL :

- `type` : audit, redesign, migration, ai ou maintenance ;
- `source` : service, ai_feature, case_client ou contact ;
- `context` : titre de la page ou contexte editorial choisi.

La page Contact propose aussi cinq variantes editoriales localisees :

- audit ;
- refonte ;
- migration ;
- IA ;
- maintenance.

Les liens pointent vers l'ancre localisee `#contact-form`. Le bloc de choix
utilise l'ancre `#qualification-contact`.

## Formulaire Contact

Le formulaire reste le webform existant. Le JavaScript du theme lit les
parametres URL, affiche un resume visible du contexte choisi, preselectionne le
type de projet, prepare l'objet et ajoute le contexte au debut du message.

Le champ `project_type` accepte maintenant explicitement `migration` en FR et
EN pour eviter de rabattre une migration vers une refonte.

## Perimetre confirme

Le ticket ne modifie pas les menus, la homepage, Config Split, les workflows
GitHub, `system.site:page.front`, le chatbot ou les providers OpenAI.
