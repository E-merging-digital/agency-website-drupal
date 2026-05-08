# Ticket 55 - Landing page Agence Drupal Belgique

## Objectif

Créer la page pilier `/agence-drupal-belgique` pour positionner Emerging Digital comme agence Drupal senior en Belgique, avec un contenu commercial, SEO et LLM-ready.

## Contenu livré

- URL canonique Drupal : `/agence-drupal-belgique`
- URL locale avec préfixe langue : `/fr/agence-drupal-belgique`
- H1 : `Agence Drupal en Belgique pour sites durables, rapides et accessibles`
- Title SEO : `Agence Drupal en Belgique | Sites Drupal, IA, SEO technique`
- Meta description : `Emerging Digital accompagne PME, ASBL et institutions en Belgique dans la création, la refonte, la migration et l’optimisation de sites Drupal rapides, accessibles et enrichis par l’IA.`

## Sections

- Hero avec promesse claire
- Pour qui : PME, ASBL, institutions
- Services Drupal : création, refonte, migration, maintenance, audit
- IA concrète dans Drupal : rédaction, SEO, traduction, workflow éditorial
- Qualité web : performance, accessibilité, SEO technique, contenus structurés
- Expertise technique : Drupal, PHP, Symfony, Laravel
- Pourquoi choisir Drupal
- Méthode de travail
- FAQ
- CTA vers contact, services, IA & Drupal et cas clients

## Implémentation

- Contenu ajouté dans le module `emerging_digital_content` via les YAML de default content.
- UUID du node : `0b4b1728-7c80-4c17-a057-ebf52dc4eb5a`
- Alias versionné : `/agence-drupal-belgique`
- Métadonnées SEO spécifiques ajoutées via `hook_metatags_alter()`.
- FAQ exposée en HTML et en JSON-LD `FAQPage`.
- Page ajoutée à `llms_txt.settings.yml` dans les pages importantes.

## Maillage interne

La page lie vers :

- `/contact`
- `/services`
- `/ia-drupal`
- `/cas-clients`
